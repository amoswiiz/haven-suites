<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

class MonthlyCompanyIncome extends BaseController
{
    private function cents($value): int
    {
        $value = trim((string) ($value ?? '0'));
        if (!preg_match('/^-?\d{1,12}(?:\.\d{1,2})?$/', $value)) {
            throw new \RuntimeException('Invalid monetary amount in accounting records.');
        }
        $negative = str_starts_with($value, '-');
        $parts = explode('.', ltrim($value, '-'), 2);
        $result = ((int) $parts[0] * 100) + (int) str_pad($parts[1] ?? '', 2, '0');
        return $negative ? -$result : $result;
    }

    private function money(int $cents): string
    {
        return ($cents < 0 ? '-' : '') . sprintf('%d.%02d', intdiv(abs($cents), 100), abs($cents) % 100);
    }

    public function index(): string|ResponseInterface
    {
        $user = auth()->user();
        if ($user === null || !$user->can('finance.view')) {
            return service('response')->setStatusCode(403)->setBody('Permission denied.');
        }

        $month = trim((string) ($this->request->getGet('month') ?: date('Y-m')));
        $date = \DateTimeImmutable::createFromFormat('!Y-m', $month);
        if (!$date || $date->format('Y-m') !== $month) {
            $month = date('Y-m');
            $date = new \DateTimeImmutable($month . '-01');
        }
        $from = $date->format('Y-m-d');
        $until = $date->modify('+1 month')->format('Y-m-d');
        $db = db_connect();

        // Match MonthlyHostCommissions: recognize commission on guest payment date,
        // using the unique management agreement effective on that date.
        $payments = $db->table('guest_payments gp')
            ->select('gp.id, gp.payment_date, gp.amount, gp.reservation_id, r.unit_id, u.unit_number, p.name AS property_name')
            ->join('reservations r', 'r.id = gp.reservation_id', 'inner')
            ->join('units u', 'u.id = r.unit_id', 'inner')
            ->join('properties p', 'p.id = u.property_id', 'left')
            ->where('gp.payment_date >=', $from)
            ->where('gp.payment_date <', $until)
            ->orderBy('gp.payment_date', 'ASC')
            ->orderBy('gp.id', 'ASC')
            ->get()->getResultArray();

        $agreements = $db->table('unit_host_agreements')
            ->select('id, unit_id, host_name, commission_rate, effective_from, effective_to')
            ->where('effective_from <', $until)
            ->groupStart()->where('effective_to', null)->orWhere('effective_to >=', $from)->groupEnd()
            ->get()->getResultArray();
        $agreementsByUnit = [];
        foreach ($agreements as $agreement) {
            $agreementsByUnit[(int) $agreement['unit_id']][] = $agreement;
        }

        $managedRows = [];
        $issues = [];
        $managementCents = 0;
        $unmatchedCents = 0;
        foreach ($payments as $payment) {
            $amount = $this->cents($payment['amount']);
            $matches = [];
            foreach ($agreementsByUnit[(int) $payment['unit_id']] ?? [] as $agreement) {
                if ($agreement['effective_from'] <= $payment['payment_date']
                    && ($agreement['effective_to'] === null || $agreement['effective_to'] >= $payment['payment_date'])) {
                    $matches[] = $agreement;
                }
            }
            if (count($matches) !== 1) {
                $unmatchedCents += $amount;
                $issues[] = 'Payment #' . (int) $payment['id'] . ' (' . $payment['payment_date']
                    . '): ' . (count($matches) === 0 ? 'missing' : 'overlapping') . ' management agreement.';
                continue;
            }
            $agreement = $matches[0];
            $rate = (string) $agreement['commission_rate'];
            if (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $rate) || (float) $rate > 100) {
                $unmatchedCents += $amount;
                $issues[] = 'Payment #' . (int) $payment['id'] . ': invalid management commission rate.';
                continue;
            }
            $basisPoints = (int) round((float) $rate * 100);
            $commission = intdiv($amount * $basisPoints + 5000, 10000);
            $key = (int) $agreement['id'];
            if (!isset($managedRows[$key])) {
                $managedRows[$key] = [
                    'unit' => trim(($payment['property_name'] ?? '') . ' / ' . $payment['unit_number'], ' /'),
                    'host' => $agreement['host_name'],
                    'rate' => $rate,
                    'collections_cents' => 0,
                    'commission_cents' => 0,
                    'payments' => 0,
                ];
            }
            $managedRows[$key]['collections_cents'] += $amount;
            $managedRows[$key]['commission_cents'] += $commission;
            $managedRows[$key]['payments']++;
            $managementCents += $commission;
        }
        foreach ($managedRows as &$row) {
            $row['collections'] = $this->money($row['collections_cents']);
            $row['commission'] = $this->money($row['commission_cents']);
        }
        unset($row);

        // Partner markup is earned in the checkout month, only for completed stays.
        // This is booking-based income, not a guest-payment/cash receipt report.
        $partners = $db->table('reservations r')
            ->select('r.id, r.guest_name, r.check_in, r.check_out, r.total_amount, r.owner_net_amount, '
                . 'pa.apartment_name, pa.building_name, pa.owner_name')
            ->join('partner_apartments pa', 'pa.id = r.partner_apartment_id', 'left')
            ->where('r.booking_type', 'partner')
            ->where('r.status', 'checked_out')
            ->where('r.check_out >=', $from)
            ->where('r.check_out <', $until)
            ->orderBy('r.check_out', 'ASC')
            ->orderBy('r.id', 'ASC')
            ->get()->getResultArray();

        $partnerRows = [];
        $partnerMarkupCents = 0;
        $partnerGrossCents = 0;
        $partnerOwnerCents = 0;
        foreach ($partners as $partner) {
            if ($partner['owner_net_amount'] === null) {
                $issues[] = 'Partner reservation #' . (int) $partner['id'] . ': missing owner payout.';
                continue;
            }
            $gross = $this->cents($partner['total_amount']);
            $owner = $this->cents($partner['owner_net_amount']);
            if ($gross < 0 || $owner < 0 || $owner > $gross) {
                $issues[] = 'Partner reservation #' . (int) $partner['id'] . ': invalid guest or owner amount.';
                continue;
            }
            $markup = $gross - $owner;
            $partnerGrossCents += $gross;
            $partnerOwnerCents += $owner;
            $partnerMarkupCents += $markup;
            $partnerRows[] = [
                'id' => (int) $partner['id'],
                'apartment' => $partner['apartment_name'] ?: ('Partner apartment #' . (int) $partner['id']),
                'owner' => $partner['owner_name'] ?? '',
                'guest' => $partner['guest_name'],
                'check_in' => $partner['check_in'],
                'check_out' => $partner['check_out'],
                'gross' => $this->money($gross),
                'owner_amount' => $this->money($owner),
                'markup' => $this->money($markup),
            ];
        }

        return view('reports/monthly_company_income', [
            'pageTitle' => 'Monthly Company Income',
            'month' => $month,
            'managedRows' => array_values($managedRows),
            'partnerRows' => $partnerRows,
            'managementCommission' => $this->money($managementCents),
            'partnerMarkup' => $this->money($partnerMarkupCents),
            'companyIncome' => $this->money($managementCents + $partnerMarkupCents),
            'partnerGross' => $this->money($partnerGrossCents),
            'partnerOwner' => $this->money($partnerOwnerCents),
            'unmatchedCollections' => $this->money($unmatchedCents),
            'issues' => $issues,
        ]);
    }
}
