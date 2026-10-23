<?php
namespace App\Controllers;

use DateTimeImmutable;
use RuntimeException;

class InvestorReports extends BaseController
{
    private function cents($value): int
    {
        $value = trim((string)($value ?? '0'));
        if (!preg_match('/^-?\d{1,12}(?:\.\d{1,2})?$/', $value)) {
            throw new RuntimeException('Invalid accounting amount.');
        }
        $negative = str_starts_with($value, '-');
        $parts = explode('.', ltrim($value, '-'), 2);
        $result = (int)$parts[0] * 100 + (int)str_pad($parts[1] ?? '', 2, '0');
        return $negative ? -$result : $result;
    }

    private function money(int $cents): string
    {
        return ($cents < 0 ? '-' : '') . sprintf('%d.%02d', intdiv(abs($cents), 100), abs($cents) % 100);
    }

    public function index()
    {
        $user = auth()->user();
        if (!$user || !$user->can('finance.view')) {
            return service('response')->setStatusCode(403)->setBody('Permission denied.');
        }
        $db = db_connect();
        $units = $db->table('units u')
            ->select('u.id, u.unit_number, p.name AS property_name')
            ->join('properties p', 'p.id = u.property_id', 'left')
            ->orderBy('p.name')->orderBy('u.unit_number')->get()->getResultArray();

        $unitId = max(0, (int)$this->request->getGet('unit_id'));
        $fromMonth = trim((string)($this->request->getGet('from_month') ?: date('Y-m')));
        $toMonth = trim((string)($this->request->getGet('to_month') ?: $fromMonth));
        $validMonth = static function (string $month): bool {
            $date = DateTimeImmutable::createFromFormat('!Y-m', $month);
            return $date !== false && $date->format('Y-m') === $month;
        };
        if (!$validMonth($fromMonth) || !$validMonth($toMonth) || $toMonth < $fromMonth) {
            return service('response')->setStatusCode(400)->setBody('Invalid reporting period.');
        }
        $start = $fromMonth . '-01';
        $end = (new DateTimeImmutable($toMonth . '-01'))->modify('+1 month')->format('Y-m-d');
        if ((new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->m
            + 12 * (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->y > 24) {
            return service('response')->setStatusCode(400)->setBody('Select a period of up to 24 months.');
        }
        $selected = null;
        foreach ($units as $unit) {
            if ((int)$unit['id'] === $unitId) $selected = $unit;
        }
        $months = [];
        for ($cursor = new DateTimeImmutable($start); $cursor->format('Y-m-d') < $end; $cursor = $cursor->modify('+1 month')) {
            $key = $cursor->format('Y-m');
            $months[$key] = ['revenue' => 0, 'nights' => 0, 'expenses' => 0, 'fee' => 0, 'remitted' => 0];
        }
        $bookings = $expenses = $remittances = $issues = [];
        if ($selected) {
            $agreements = $db->table('unit_host_agreements')
                ->select('id, commission_rate, effective_from, effective_to')
                ->where('unit_id', $unitId)->where('effective_from <', $end)
                ->groupStart()->where('effective_to', null)->orWhere('effective_to >=', $start)->groupEnd()
                ->get()->getResultArray();

            $reservations = $db->table('reservations')
                ->select('id, guest_name, check_in, check_out, nightly_rate, total_amount, status')
                ->where('booking_type', 'managed')->where('unit_id', $unitId)
                ->where('check_in <', $end)->where('check_out >', $start)
                ->whereIn('status', ['checked_in', 'checked_out'])
                ->orderBy('check_in')->orderBy('id')->get()->getResultArray();
            $today = date('Y-m-d');
            foreach ($reservations as $r) {
                $arrival = new DateTimeImmutable($r['check_in']);
                $departure = new DateTimeImmutable($r['check_out']);
                $nights = (int)$arrival->diff($departure)->days;
                if ($nights <= 0) { $issues[] = 'Reservation #' . $r['id'] . ' has invalid dates.'; continue; }
                $total = $this->cents($r['total_amount']);
                $nightly = $this->cents($r['nightly_rate']);
                if ($total < 0 || $nightly < 0 || $total !== $nightly * $nights) {
                    $issues[] = 'Reservation #' . $r['id'] . ' total differs from nightly rate × nights; allocated using total amount.';
                }
                // Exact integer-cent allocation: every night receives its fair share; remainder assigned to earliest nights.
                $base = intdiv($total, $nights);
                $remainder = $total % $nights;
                $byMonth = [];
                for ($i = 0, $night = $arrival; $night < $departure; $night = $night->modify('+1 day'), $i++) {
                    $day = $night->format('Y-m-d');
                    if ($day < $start || $day >= $end || $day >= $today) continue;
                    $key = $night->format('Y-m');
                    $amount = $base + ($i < $remainder ? 1 : 0);
                    $months[$key]['revenue'] += $amount;
                    $months[$key]['nights']++;
                    if (!isset($byMonth[$key])) $byMonth[$key] = ['nights' => 0, 'revenue' => 0];
                    $byMonth[$key]['nights']++;
                    $byMonth[$key]['revenue'] += $amount;
                    $matches = [];
                    foreach ($agreements as $agreement) {
                        if ($agreement['effective_from'] <= $day && ($agreement['effective_to'] === null || $agreement['effective_to'] >= $day)) {
                            $matches[] = $agreement;
                        }
                    }
                    if (count($matches) !== 1) {
                        $issues[] = 'Reservation #' . $r['id'] . ': ' . (count($matches) ? 'overlapping' : 'missing') . ' management agreement on ' . $day . '.';
                        continue;
                    }
                    $rate = (string)$matches[0]['commission_rate'];
                    if (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $rate) || (float)$rate > 100) {
                        $issues[] = 'Invalid management fee rate on ' . $day . '.';
                        continue;
                    }
                    $basisPoints = (int)round((float)$rate * 100);
                    $months[$key]['fee'] += intdiv($amount * $basisPoints + 5000, 10000);
                }
                foreach ($byMonth as $key => $part) {
                    $bookings[] = ['id' => $r['id'], 'guest' => $r['guest_name'], 'check_in' => $r['check_in'],
                        'check_out' => $r['check_out'], 'month' => $key, 'nights' => $part['nights'],
                        'revenue' => $this->money($part['revenue'])];
                }
            }
            $expenseRows = $db->table('unit_expenses e')
                ->select('e.id, e.expense_date, e.description, e.amount, c.name AS category')
                ->join('expense_categories c', 'c.id = e.category_id', 'left')
                ->where('e.unit_id', $unitId)->where('e.expense_date >=', $start)->where('e.expense_date <', $end)
                ->orderBy('e.expense_date')->orderBy('e.id')->get()->getResultArray();
            foreach ($expenseRows as $e) {
                $key = substr($e['expense_date'], 0, 7);
                $amount = $this->cents($e['amount']);
                $months[$key]['expenses'] += $amount;
                $expenses[] = ['date' => $e['expense_date'], 'month' => $key, 'description' => $e['description'],
                    'category' => $e['category'] ?: 'Uncategorized', 'amount' => $this->money($amount)];
            }
            $paymentRows = $db->table('owner_remittances')
                ->select('period_month, payment_date, payment_method, reference, amount')
                ->where('unit_id', $unitId)->where('period_month >=', $start)->where('period_month <', $end)
                ->orderBy('period_month')->orderBy('payment_date')->get()->getResultArray();
            foreach ($paymentRows as $p) {
                $key = substr($p['period_month'], 0, 7);
                $amount = $this->cents($p['amount']);
                $months[$key]['remitted'] += $amount;
                $remittances[] = ['month' => $key, 'date' => $p['payment_date'], 'method' => $p['payment_method'],
                    'reference' => $p['reference'], 'amount' => $this->money($amount)];
            }
        }
        $totals = ['revenue' => 0, 'nights' => 0, 'expenses' => 0, 'fee' => 0, 'remitted' => 0, 'net' => 0, 'remaining' => 0];
        foreach ($months as &$row) {
            $row['net'] = $row['revenue'] - $row['expenses'] - $row['fee'];
            $row['remaining'] = $row['net'] - $row['remitted'];
            foreach ($totals as $key => $_) $totals[$key] += $row[$key];
        }
        unset($row);
        $pdfRequested = $this->request->getGet('pdf') === '1';
        $viewName = $pdfRequested ? 'reports/investor_pdf'
            : ($this->request->getGet('print') === '1' ? 'reports/investor_print' : 'reports/investor');
        $data = [
            'pageTitle' => 'Investor Reports', 'units' => $units, 'selected' => $selected,
            'unitId' => $unitId, 'fromMonth' => $fromMonth, 'toMonth' => $toMonth,
            'months' => $months, 'totals' => $totals, 'bookings' => $bookings,
            'expenses' => $expenses, 'remittances' => $remittances,
            'issues' => array_values(array_unique($issues)),
        ];
        if (!$pdfRequested) {
            return view($viewName, $data);
        }
        if (!$selected) {
            return service('response')->setStatusCode(400)->setBody('Select a managed apartment first.');
        }
        if (!class_exists(\Dompdf\Dompdf::class)) {
            return service('response')->setStatusCode(503)->setBody(
                'PDF library not installed. In the project directory run: composer require dompdf/dompdf'
            );
        }
        // The PDF view uses embedded local branding; no external image requests.
        $logoPath = FCPATH . 'assets/img/haven-suites-logo.png';
        $data['logoDataUri'] = is_file($logoPath)
            ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath)) : '';
        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', FCPATH);
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml(view($viewName, $data), 'UTF-8');
        $dompdf->render();
        // Page numbers, positioned within the reserved bottom margin.
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('Helvetica', 'normal');
        $canvas->page_text(495, 813, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 8, [0.4, 0.46, 0.56]);
        $filename = 'Haven-Suites-Investor-' . $unitId . '-' . $fromMonth . '-to-' . $toMonth . '.pdf';
        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setHeader('Cache-Control', 'private, no-store, max-age=0')
            ->setBody($dompdf->output());
    }
}
