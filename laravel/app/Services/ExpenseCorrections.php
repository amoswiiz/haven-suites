<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpenseCorrections
{
    public function snapshot(object $expense): string
    {
        return hash('sha256', json_encode((array) $expense, JSON_THROW_ON_ERROR));
    }

    public function editable(object $expense, bool $lock = false): bool
    {
        if ($expense->source_type !== 'manual' || $expense->source_id !== null) {
            return false;
        }
        $db = DB::connection('tenant');

        if (! $db->getSchemaBuilder()->hasTable('staff_bills')) {
            return true;
        }
        $linked = $db->table('staff_bills')->where('expense_id', $expense->id);
        if ($lock) {
            $linked->lockForUpdate();
        }

        return $linked->first() === null;
    }

    public function cents(mixed $value): int
    {
        if (! is_scalar($value) || ! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', trim((string) $value))) {
            throw ValidationException::withMessages(['amount' => 'Enter a valid expense amount with up to two decimal places.']);
        }
        $parts = explode('.', trim((string) $value), 2);

        return (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    private function money(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    public function correct(int $id, array $data): void
    {
        abort_unless(auth()->user()?->active && auth()->user()->can('finance.manage'), 403);
        $db = DB::connection('tenant');
        $db->transaction(function () use ($db, $id, $data): void {
            $initial = $db->table('unit_expenses')->find($id);
            abort_unless($initial, 404);
            $newUnit = ! empty($data['unit_id']) ? (int) $data['unit_id'] : null;
            $unitIds = array_values(array_unique(array_filter([$initial->unit_id, $newUnit], static fn ($unit) => $unit !== null)));
            sort($unitIds, SORT_NUMERIC);
            foreach ($unitIds as $unitId) {
                if (! $db->table('units')->where('id', $unitId)->lockForUpdate()->first()) {
                    throw ValidationException::withMessages(['unit_id' => 'Choose an apartment belonging to this company.']);
                }
            }
            $expense = $db->table('unit_expenses')->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($expense->unit_id !== $initial->unit_id || ! hash_equals($this->snapshot($expense), $data['snapshot'])) {
                throw ValidationException::withMessages(['expense' => 'This expense or its payments changed. Reload the edit page before saving.']);
            }
            if (! $this->editable($expense, true)) {
                throw ValidationException::withMessages(['expense' => 'This expense is linked to another workflow. Correct it in its original cleaning, rent or bill record.']);
            }
            $category = $db->table('expense_categories')->where('id', $data['category_id'])->lockForUpdate()->first();
            if (! $category || (! $category->is_active && (int) $category->id !== (int) $expense->category_id)) {
                throw ValidationException::withMessages(['category_id' => 'Choose an active expense category.']);
            }
            $payments = $db->table('expense_payments')->where('expense_id', $id)->lockForUpdate()->get();
            $ledgerPaid = $payments->sum(fn ($payment) => $this->cents($payment->amount));
            $cachedPaid = $this->cents($expense->amount_paid);
            $legacyPaid = $payments->isEmpty() && $cachedPaid > 0;
            $paid = $legacyPaid ? $cachedPaid : $ledgerPaid;
            $amount = $this->cents($data['amount']);
            $unitChanged = $newUnit !== ($expense->unit_id === null ? null : (int) $expense->unit_id);
            $dateChanged = $data['expense_date'] !== $expense->expense_date;
            $categoryChanged = (int) $data['category_id'] !== (int) $expense->category_id;
            $financialChange = $unitChanged || $dateChanged || $categoryChanged
                || $amount !== $this->cents($expense->amount);
            if ($financialChange && $newUnit !== null && strcasecmp($category->name, 'Rent') === 0 && (new UnitAccounting)->mode($newUnit, $data['expense_date']) === 'leased') {
                throw ValidationException::withMessages(['category_id' => 'Post leased-apartment rent from Apartment accounting & rent to avoid duplicate rent expenses.']);
            }
            $ledgerMismatch = ! $legacyPaid && ($paid !== $cachedPaid || $paid > $this->cents($expense->amount));
            if ($financialChange && $ledgerMismatch) {
                throw ValidationException::withMessages(['expense' => 'The paid amount does not match its payment ledger. You can correct description, supplier and notes; reconcile payments before changing financial details.']);
            }
            if ($amount <= 0 || ($financialChange && $amount < $paid)) {
                throw ValidationException::withMessages(['amount' => 'The expense total must be positive and cannot be below recorded payments. Correct the payment separately if it was entered incorrectly.']);
            }
            if ($dateChanged && $payments->contains(fn ($payment) => $payment->payment_date < $data['expense_date'])) {
                throw ValidationException::withMessages(['expense_date' => 'The expense date cannot be later than an existing payment date.']);
            }
            $legacyDate = $legacyPaid ? ($expense->paid_at ?? null) : null;
            if ($legacyPaid && ($unitChanged || $dateChanged || $categoryChanged) && ! $legacyDate) {
                throw ValidationException::withMessages(['expense' => 'This historical paid expense has no payment date. Verify its payment date before changing its apartment, expense date or category.']);
            }
            if ($legacyPaid && $dateChanged && $legacyDate < $data['expense_date']) {
                throw ValidationException::withMessages(['expense_date' => 'The expense date cannot be later than its recorded historical payment date.']);
            }
            $guard = new AccountingPeriodGuard;
            try {
                if ($expense->unit_id !== null) {
                    $guard->assertOpen((int) $expense->unit_id, $expense->expense_date);
                }
                if ($newUnit !== null) {
                    $guard->assertOpen($newUnit, $data['expense_date']);
                }
                if ($financialChange) {
                    $paymentDates = $payments->pluck('payment_date')->all();
                    if ($legacyDate) {
                        $paymentDates[] = $legacyDate;
                    }
                    foreach (array_unique($paymentDates) as $paymentDate) {
                        foreach ($unitIds as $unitId) {
                            $guard->assertOpen((int) $unitId, $paymentDate);
                        }
                    }
                }
            } catch (\RuntimeException $exception) {
                throw ValidationException::withMessages(['expense' => $exception->getMessage()]);
            }
            $changes = [
                'unit_id' => $newUnit, 'category_id' => (int) $data['category_id'],
                'expense_date' => $data['expense_date'], 'description' => trim($data['description']),
                'supplier_name' => trim($data['supplier_name'] ?? '') ?: null,
                'notes' => trim($data['notes'] ?? '') ?: null, 'amount' => $this->money($amount),
                'payment_status' => $ledgerMismatch ? $expense->payment_status : ($paid === 0 ? 'unpaid' : ($paid === $amount ? 'paid' : 'partial')),
            ];
            $before = (array) $expense;
            $hasChange = false;
            foreach ($changes as $field => $value) {
                if ((string) ($before[$field] ?? '') !== (string) ($value ?? '')) {
                    $hasChange = true;
                    break;
                }
            }
            if (! $hasChange) {
                throw ValidationException::withMessages(['expense' => 'No expense details were changed.']);
            }
            $db->table('unit_expenses')->where('id', $id)->update($changes + ['updated_at' => now()]);
            $after = $db->table('unit_expenses')->find($id);
            $db->table('expense_corrections')->insert([
                'expense_id' => $id, 'actor_id' => auth()->id(), 'actor_name' => auth()->user()->name,
                'reason' => trim($data['reason']), 'before_snapshot' => json_encode($before, JSON_THROW_ON_ERROR),
                'after_snapshot' => json_encode((array) $after, JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
        }, 3);
    }
}
