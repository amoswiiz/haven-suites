<?php
namespace App\Services;

use RuntimeException;

class OwnerRemittanceCalculator
{
    public function calculate(int $unitId, string $month): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new RuntimeException('Invalid month.');
        }
        $db = db_connect();
        $from = $month . '-01';
        $next = date('Y-m-d', strtotime($from . ' +1 month'));

        $openingRow = $db->table('unit_opening_balances')
            ->where('unit_id', $unitId)->get()->getRowArray();
        $baseline = 0;
        $cutoff = null;
        if ($openingRow) {
            $cutoff = (string)$openingRow['effective_month'];
            if ($from < $cutoff) {
                throw new RuntimeException('Statement predates the configured opening balance.');
            }
            $baseline = $this->cents($openingRow['amount']);
        }
        // All collections up to the selected month are needed for carry-forward.
        $collections = $db->table('guest_payments gp')
            ->select('gp.amount, gp.payment_date')
            ->join('reservations r', 'r.id = gp.reservation_id')
            ->where('r.unit_id', $unitId)
            ->where('gp.payment_date <', $next)
            ->get()->getResultArray();
        if ($cutoff !== null) {
            $collections = array_values(array_filter($collections, static fn($p) => $p['payment_date'] >= $cutoff));
        }
        $openingCollections = 0;
        $openingFees = 0;
        $currentCollections = 0;
        $currentFees = 0;
        $issues = [];
        foreach ($collections as $payment) {
            $date = (string)$payment['payment_date'];
            $amount = $this->cents($payment['amount']);
            $prior = $date < $from;
            if ($prior) $openingCollections += $amount;
            else $currentCollections += $amount;
            $matches = $db->table('unit_host_agreements')
                ->select('commission_rate')
                ->where('unit_id', $unitId)
                ->where('effective_from <=', $date)
                ->groupStart()->where('effective_to IS NULL', null, false)
                    ->orWhere('effective_to >=', $date)->groupEnd()
                ->get()->getResultArray();
            if (count($matches) !== 1) {
                $issues[] = 'Missing or overlapping management agreement on ' . $date;
                continue;
            }
            $rate = (string)$matches[0]['commission_rate'];
            if (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $rate) || (float)$rate > 100) {
                $issues[] = 'Invalid management fee rate on ' . $date;
                continue;
            }
            $basisPoints = (int)round((float)$rate * 100);
            $fee = intdiv($amount * $basisPoints + 5000, 10000);
            if ($prior) $openingFees += $fee;
            else $currentFees += $fee;
        }

        $expenseRows = $db->table('unit_expenses')
            ->select('expense_date, amount')
            ->where('unit_id', $unitId)
            ->where('expense_date <', $next)
            ->get()->getResultArray();
        $priorExpenses = 0;
        $currentExpenses = 0;
        foreach ($expenseRows as $expense) {
            if ($cutoff !== null && $expense['expense_date'] < $cutoff) continue;
            if ($expense['expense_date'] < $from) $priorExpenses += $this->cents($expense['amount']);
            else $currentExpenses += $this->cents($expense['amount']);
        }

        // Payments are allocated to an accounting month; payment_date is separately retained.
        $remittanceRows = $db->table('owner_remittances')
            ->select('period_month, amount')
            ->where('unit_id', $unitId)
            ->where('period_month <', $next)
            ->get()->getResultArray();
        $priorPaid = 0;
        $currentPaid = 0;
        foreach ($remittanceRows as $payment) {
            if ($cutoff !== null && $payment['period_month'] < $cutoff) continue;
            if ($payment['period_month'] < $from) $priorPaid += $this->cents($payment['amount']);
            else $currentPaid += $this->cents($payment['amount']);
        }
        $opening = $baseline + $openingCollections - $openingFees - $priorExpenses - $priorPaid;
        $entitlement = $currentCollections - $currentFees - $currentExpenses;
        $closing = $opening + $entitlement - $currentPaid;
        $available = max(0, $closing);
        // A deficit is a normal carried-forward balance, not a blocking data error.
        $deficitToRecover = max(0, -$closing);
        $recoveredThisMonth = min(max(0, -$opening), max(0, $entitlement));
        if ($currentPaid > max(0, $opening + $entitlement)) {
            $issues[] = 'Existing remittances exceed the available owner balance.';
        }
        return [
            'opening_balance' => $this->money($opening),
            'collected' => $this->money($currentCollections),
            'management_fee' => $this->money($currentFees),
            'expenses' => $this->money($currentExpenses),
            'entitlement' => $this->money($entitlement),
            'paid' => $this->money($currentPaid),
            'closing_balance' => $this->money($closing),
            'outstanding' => $this->money($available),
            'deficit_recovered' => $this->money($recoveredThisMonth),
            'deficit_to_recover' => $this->money($deficitToRecover),
            'issues' => array_values(array_unique($issues)),
        ];
    }

    public function cents($value): int
    {
        $value = (string)($value ?? '0');
        if (!preg_match('/^-?\d{1,12}(?:\.\d{1,2})?$/', $value)) {
            throw new RuntimeException('Invalid monetary value.');
        }
        $negative = str_starts_with($value, '-');
        $parts = explode('.', ltrim($value, '-'));
        $cents = ((int)$parts[0] * 100) + (int)str_pad($parts[1] ?? '', 2, '0');
        return $negative ? -$cents : $cents;
    }

    public function money(int $cents): string
    {
        return ($cents < 0 ? '-' : '') .
            sprintf('%d.%02d', intdiv(abs($cents), 100), abs($cents) % 100);
    }
}
