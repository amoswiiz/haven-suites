<?php

namespace App\Http\Controllers\Haven;

use App\Services\UnitAccounting;
use Symfony\Component\HttpFoundation\Response as ResponseInterface;

class MonthlyHostCommissions extends BaseController
{
    private function cents($value): int
    {
        $parts = explode('.', (string) $value, 2);

        return ((int) $parts[0] * 100) + (int) str_pad(substr($parts[1] ?? '', 0, 2), 2, '0');
    }

    private function money(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    public function index(): string|ResponseInterface
    {
        $user = haven_auth()->user();
        if ($user === null || ! $user->can('finance.view')) {
            return haven_service('response')->setStatusCode(403)->setContent('Permission denied.');
        }

        $month = trim((string) ($this->request->getGet('month') ?: date('Y-m')));
        $date = \DateTimeImmutable::createFromFormat('!Y-m', $month);
        if (! $date || $date->format('Y-m') !== $month) {
            $month = date('Y-m');
            $date = new \DateTimeImmutable($month.'-01');
        }
        $from = $date->format('Y-m-d');
        $until = $date->modify('+1 month')->format('Y-m-d');
        $unitId = max(0, (int) $this->request->getGet('unit_id'));
        $db = db_connect();

        $units = $db->table('units u')
            ->select('u.id, u.unit_number, p.name AS property_name')
            ->join('properties p', 'p.id = u.property_id', 'left')
            ->orderBy('p.name', 'ASC')->orderBy('u.unit_number', 'ASC')
            ->get()->getResultArray();

        // Read each payment exactly once. Commission is attributed to payment_date,
        // not check-in date, reservation creation date or booking month.
        $query = $db->table('guest_payments gp')
            ->select('gp.id, gp.payment_date, gp.amount, gp.reservation_id, r.unit_id, u.unit_number, p.name AS property_name')
            ->join('reservations r', 'r.id = gp.reservation_id', 'inner')
            ->join('units u', 'u.id = r.unit_id', 'inner')
            ->join('properties p', 'p.id = u.property_id', 'left')
            ->where('gp.payment_date >=', $from)
            ->where('gp.payment_date <', $until);
        if ($unitId > 0) {
            $query->where('r.unit_id', $unitId);
        }
        $payments = $query->orderBy('gp.payment_date', 'ASC')->orderBy('gp.id', 'ASC')->get()->getResultArray();

        $agreementQuery = $db->table('unit_host_agreements')
            ->select('id, unit_id, host_name, commission_rate, effective_from, effective_to')
            ->where('effective_from <', $until)
            ->groupStart()->where('effective_to', null)->orWhere('effective_to >=', $from)->groupEnd();
        if ($unitId > 0) {
            $agreementQuery->where('unit_id', $unitId);
        }
        $agreementsByUnit = [];
        foreach ($agreementQuery->orderBy('effective_from', 'ASC')->get()->getResultArray() as $agreement) {
            $agreementsByUnit[(int) $agreement['unit_id']][] = $agreement;
        }

        $rows = [];
        $unmatched = [];
        $ambiguous = [];
        $totalCollections = 0;
        $totalCommission = 0;
        $unmatchedCollections = 0;
        $unitAccounting = new UnitAccounting;
        foreach ($payments as $payment) {
            if ($unitAccounting->mode((int) $payment['unit_id'], $payment['payment_date']) === 'leased') {
                continue;
            }
            $amount = $this->cents($payment['amount']);
            $totalCollections += $amount;
            $matching = [];
            foreach ($agreementsByUnit[(int) $payment['unit_id']] ?? [] as $agreement) {
                if ($agreement['effective_from'] <= $payment['payment_date']
                    && ($agreement['effective_to'] === null || $agreement['effective_to'] >= $payment['payment_date'])) {
                    $matching[] = $agreement;
                }
            }
            $matching = $unitAccounting->matchingAgreements((int) $payment['unit_id'], $payment['payment_date'], $matching);
            if (count($matching) !== 1) {
                $unmatchedCollections += $amount;
                $issue = [
                    'payment_id' => $payment['id'],
                    'payment_date' => $payment['payment_date'],
                    'reservation_id' => $payment['reservation_id'],
                    'unit' => trim(($payment['property_name'] ?? '').' / '.$payment['unit_number'], ' /'),
                    'amount' => $this->money($amount),
                ];
                if (count($matching) === 0) {
                    $unmatched[] = $issue;
                } else {
                    $ambiguous[] = $issue;
                }

                continue;
            }
            $agreement = $matching[0];
            // Round per payment to cents; rate has at most two decimal places.
            $rateHundredths = (int) round(((float) $agreement['commission_rate']) * 100);
            $commission = intdiv($amount * $rateHundredths + 5000, 10000);
            $key = (int) $agreement['id'];
            if (! isset($rows[$key])) {
                $rows[$key] = [
                    'unit' => trim(($payment['property_name'] ?? '').' / '.$payment['unit_number'], ' /'),
                    'host_name' => $agreement['host_name'],
                    'commission_rate' => $agreement['commission_rate'],
                    'collections_cents' => 0,
                    'commission_cents' => 0,
                    'payment_count' => 0,
                ];
            }
            $rows[$key]['collections_cents'] += $amount;
            $rows[$key]['commission_cents'] += $commission;
            $rows[$key]['payment_count']++;
            $totalCommission += $commission;
        }
        foreach ($rows as &$row) {
            $row['collections'] = $this->money($row['collections_cents']);
            $row['commission'] = $this->money($row['commission_cents']);
        }
        unset($row);

        return haven_view('reports/monthly_host_commissions', [
            'pageTitle' => 'Monthly Host Commissions',
            'month' => $month,
            'unitId' => $unitId,
            'units' => $units,
            'rows' => array_values($rows),
            'unmatched' => $unmatched,
            'ambiguous' => $ambiguous,
            'totalCollections' => $this->money($totalCollections),
            'totalCommission' => $this->money($totalCommission),
            'unmatchedCollections' => $this->money($unmatchedCollections),
        ]);
    }
}
