<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class OwnerRemittanceCalculator
{
    public function calculate(int $unitId, string $month): array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new RuntimeException('Invalid month.');
        }
        $db = db_connect();
        $from = $month.'-01';
        $approved = DB::connection('tenant')->table('owner_statement_closings')->where('unit_id', $unitId)->where('period_month', $from)->first();
        if ($approved) {
            if (! hash_equals($approved->statement_hash, hash('sha256', $approved->statement_json))) {
                throw new RuntimeException('Approved statement integrity check failed.');
            }

            return json_decode($approved->statement_json, true, 512, JSON_THROW_ON_ERROR);
        }
        $accounting = new UnitAccounting;
        $next = date('Y-m-d', strtotime($from.' +1 month'));

        $openingRow = $db->table('unit_opening_balances')
            ->where('unit_id', $unitId)->get()->getRowArray();
        $baseline = 0;
        $cutoff = null;
        if ($openingRow) {
            $cutoff = (string) $openingRow['effective_month'];
            if ($from < $cutoff) {
                throw new RuntimeException('Statement predates the configured opening balance.');
            }
            $baseline = $this->cents($openingRow['amount']);
        }
        $previous = DB::connection('tenant')->table('owner_statement_closings')->where('unit_id', $unitId)->where('period_month', '<', $from)->orderByDesc('period_month')->first();
        $previousSnapshot = null;
        if ($previous && (! $cutoff || $previous->period_month >= $cutoff)) {
            if (! hash_equals($previous->statement_hash, hash('sha256', $previous->statement_json))) {
                throw new RuntimeException('Prior approved statement integrity check failed.');
            }
            $previousSnapshot = json_decode($previous->statement_json, true, 512, JSON_THROW_ON_ERROR);
            $baseline = $this->cents($previousSnapshot['closing_balance']);
            $cutoff = date('Y-m-d', strtotime($previous->period_month.' +1 month'));
        }
        // All collections up to the selected month are needed for carry-forward.
        $collections = $db->table('guest_payments gp')
            ->select('gp.amount, gp.payment_date')
            ->join('reservations r', 'r.id = gp.reservation_id')
            ->where('r.unit_id', $unitId)
            ->where('gp.payment_date <', $next)
            ->get()->getResultArray();
        if ($cutoff !== null) {
            $collections = array_values(array_filter($collections, static fn ($p) => $p['payment_date'] >= $cutoff));
        }
        $openingCollections = 0;
        $openingFees = 0;
        $currentCollections = 0;
        $currentFees = 0;
        $issues = [];
        foreach ($collections as $payment) {
            $date = (string) $payment['payment_date'];
            if ($accounting->mode($unitId, $date) === 'leased') {
                continue;
            }
            $amount = $this->cents($payment['amount']);
            $prior = $date < $from;
            if ($prior) {
                $openingCollections += $amount;
            } else {
                $currentCollections += $amount;
            }
            $matches = $db->table('unit_host_agreements')
                ->select('commission_rate')
                ->where('unit_id', $unitId)
                ->where('effective_from <=', $date)
                ->groupStart()->where('effective_to IS NULL', null, false)
                ->orWhere('effective_to >=', $date)->groupEnd()
                ->get()->getResultArray();
            $matches = $accounting->matchingAgreements($unitId, $date, $matches);
            if (count($matches) !== 1) {
                $issues[] = 'Missing or overlapping management agreement on '.$date;

                continue;
            }
            $rate = (string) $matches[0]['commission_rate'];
            if (! preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $rate) || (float) $rate > 100) {
                $issues[] = 'Invalid management fee rate on '.$date;

                continue;
            }
            $basisPoints = (int) round((float) $rate * 100);
            $fee = intdiv($amount * $basisPoints + 5000, 10000);
            if ($prior) {
                $openingFees += $fee;
            } else {
                $currentFees += $fee;
            }
        }

        $expenseRows = $db->table('unit_expenses')
            ->select('expense_date, amount')
            ->where('unit_id', $unitId)
            ->where('expense_date <', $next)
            ->get()->getResultArray();
        $priorExpenses = 0;
        $currentExpenses = 0;
        foreach ($expenseRows as $expense) {
            if ($cutoff !== null && $expense['expense_date'] < $cutoff) {
                continue;
            }
            if ($expense['expense_date'] < $from) {
                $priorExpenses += $this->cents($expense['amount']);
            } else {
                $currentExpenses += $this->cents($expense['amount']);
            }
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
            if ($cutoff !== null && $payment['period_month'] < $cutoff) {
                continue;
            }
            if ($payment['period_month'] < $from) {
                $priorPaid += $this->cents($payment['amount']);
            } else {
                $currentPaid += $this->cents($payment['amount']);
            }
        }
        $companyExpenses = $currentExpenses;
        $priorExpenses = $currentExpenses = 0;
        $priorRent = $currentRent = $priorRentPaid = $currentRentPaid = 0;
        $tenantId = app('currentTenant')->id;
        $firstLease = DB::table('unit_operating_arrangements')->where('tenant_id', $tenantId)->where('unit_id', $unitId)->where('mode', 'leased')->min('starts_on');
        if ($firstLease) {
            $cursor = max($firstLease, $cutoff ?? $firstLease);
            $iterations = 0;
            for (; $cursor < $next; $cursor = date('Y-m-d', strtotime($cursor.' +1 month'))) {
                if (++$iterations > 1200) {
                    throw new RuntimeException('Lease history exceeds supported reporting range.');
                }
                $due = $accounting->rentDue($unitId, substr($cursor, 0, 7));
                if ($cursor < $from) {
                    $priorRent += $due;
                } else {
                    $currentRent += $due;
                }
            }
        }
        $rentExpenses = DB::connection('tenant')->table('unit_expenses')->where('unit_id', $unitId)->where('source_type', 'operating_rent')->where('expense_date', '<', $next)->get(['expense_date', 'amount_paid']);
        foreach ($rentExpenses as $expense) {
            if ($cutoff && $expense->expense_date < $cutoff) {
                continue;
            }
            if ($expense->expense_date < $from) {
                $priorRentPaid += $this->cents($expense->amount_paid);
            } else {
                $currentRentPaid += $this->cents($expense->amount_paid);
            }
        }
        $postedRent = DB::connection('tenant')->table('unit_expenses')->where('unit_id', $unitId)->where('source_type', 'operating_rent')->where('expense_date', '<', $next);
        if ($cutoff) {
            $postedRent->where('expense_date', '>=', $cutoff);
        }
        $postedCents = 0;
        foreach ($postedRent->pluck('amount') as $amount) {
            $postedCents += $this->cents($amount);
        }
        if ($priorRent + $currentRent > $postedCents) {
            $issues[] = 'Post outstanding monthly rent charges before approving this owner statement.';
        }
        $priorPaid += $priorRentPaid;
        $currentPaid += $currentRentPaid;
        if ($previousSnapshot !== null) {
            $latePayments = DB::connection('tenant')->table('expense_payments as p')->join('unit_expenses as e', 'e.id', '=', 'p.expense_id')->where('e.unit_id', $unitId)->where('e.source_type', 'operating_rent')->where('e.expense_date', '<', $cutoff)->where('p.payment_date', '<', $next);
            if (isset($previousSnapshot['rent_payment_last_id'])) {
                $latePayments->where('p.id', '>', $previousSnapshot['rent_payment_last_id']);
            } else {
                $latePayments->where('p.created_at', '>', $previous->approved_at);
            }
            foreach ($latePayments->get(['p.amount', 'p.payment_date']) as $payment) {
                if ($payment->payment_date < $from) {
                    $priorPaid += $this->cents($payment->amount);
                } else {
                    $currentPaid += $this->cents($payment->amount);
                }
            }
        }
        $opening = $baseline + $openingCollections - $openingFees + $priorRent - $priorPaid;
        $entitlement = $currentCollections - $currentFees + $currentRent;
        $closing = $opening + $entitlement - $currentPaid;
        $available = max(0, $closing);
        // A deficit is a normal carried-forward balance, not a blocking data error.
        $deficitToRecover = max(0, -$closing);
        $recoveredThisMonth = min(max(0, -$opening), max(0, $entitlement));
        if ($currentPaid > max(0, $opening + $entitlement)) {
            $issues[] = 'Existing remittances exceed the available owner balance.';
        }

        return [
            'accounting_policy' => 'company-funded-v2',
            'rent_payment_last_id' => (int) (DB::connection('tenant')->table('expense_payments as p')->join('unit_expenses as e', 'e.id', '=', 'p.expense_id')->where('e.unit_id', $unitId)->where('e.source_type', 'operating_rent')->max('p.id') ?? 0),
            'arrangement' => $accounting->mode($unitId, $from),
            'rental_income' => $this->money($currentRent),
            'company_funded_expenses' => $this->money($companyExpenses),
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
        $value = (string) ($value ?? '0');
        if (! preg_match('/^-?\d{1,12}(?:\.\d{1,2})?$/', $value)) {
            throw new RuntimeException('Invalid monetary value.');
        }
        $negative = str_starts_with($value, '-');
        $parts = explode('.', ltrim($value, '-'));
        $cents = ((int) $parts[0] * 100) + (int) str_pad($parts[1] ?? '', 2, '0');

        return $negative ? -$cents : $cents;
    }

    public function money(int $cents): string
    {
        return ($cents < 0 ? '-' : '').
            sprintf('%d.%02d', intdiv(abs($cents), 100), abs($cents) % 100);
    }
}
