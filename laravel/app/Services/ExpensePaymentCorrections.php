<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExpensePaymentCorrections
{
    public function snapshot(object $expense, ?object $payment): string
    {
        return hash('sha256', json_encode($this->state($expense, $payment), JSON_THROW_ON_ERROR));
    }

    private function state(object $expense, ?object $payment): array
    {
        return ['expense' => (array) $expense, 'payment' => $payment === null ? null : (array) $payment];
    }

    private function money(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    public function correct(int $expenseId, ?int $paymentId, array $data): void
    {
        abort_unless(auth()->user()?->active && auth()->user()->can('finance.manage'), 403);
        $data = Validator::make($data, [
            'amount' => ['required', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D'],
            'payment_date' => 'required|date_format:Y-m-d',
            'payment_method' => [$paymentId === null ? 'nullable' : 'required', 'string', Rule::in(['mpesa', 'bank', 'cash', 'other'])],
            'reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:10000',
            'reason' => 'required|string|min:3|max:1000',
            'snapshot' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
        ])->validate();

        $db = DB::connection('tenant');
        $db->transaction(function () use ($db, $expenseId, $paymentId, $data): void {
            $initial = $db->table('unit_expenses')->find($expenseId);
            abort_unless($initial, 404);
            if ($initial->unit_id !== null && ! $db->table('units')->where('id', $initial->unit_id)->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['expense' => 'The expense apartment no longer exists.']);
            }
            $expense = $db->table('unit_expenses')->where('id', $expenseId)->lockForUpdate()->firstOrFail();
            if ($expense->unit_id !== $initial->unit_id) {
                throw ValidationException::withMessages(['expense' => 'The expense changed. Reload the correction page before saving.']);
            }
            $payments = $db->table('expense_payments')->where('expense_id', $expenseId)->orderBy('id')->lockForUpdate()->get();
            $payment = $paymentId === null ? null : $payments->first(fn ($row) => (int) $row->id === $paymentId);
            if ($paymentId !== null) {
                abort_unless($payment, 404);
            }
            if (! hash_equals($this->snapshot($expense, $payment), $data['snapshot'])) {
                throw ValidationException::withMessages(['expense' => 'This expense or payment changed. Reload the correction page before saving.']);
            }

            $expenseCorrections = app(ExpenseCorrections::class);
            if (! $expenseCorrections->editable($expense, true)) {
                throw ValidationException::withMessages(['expense' => 'This expense belongs to another workflow. Correct its payment in the original cleaning, rent or bill record.']);
            }
            $amount = $expenseCorrections->cents($data['amount']);
            $total = $expenseCorrections->cents($expense->amount);
            $storedPaid = $expenseCorrections->cents($expense->amount_paid);
            $ledgerPaid = $payments->sum(fn ($row) => $expenseCorrections->cents($row->amount));
            if ($paymentId === null) {
                if ($payments->isNotEmpty() || $storedPaid <= 0) {
                    throw ValidationException::withMessages(['expense' => 'A historical paid balance can only be corrected when it has no individual payment entries. Reload this expense.']);
                }
                $newPaid = $amount;
                $oldDate = $expense->paid_at ?: $expense->expense_date;
            } else {
                if ($ledgerPaid !== $storedPaid) {
                    throw ValidationException::withMessages(['expense' => 'The stored paid amount does not match its payment ledger. Reconcile the ledger before correcting an individual payment.']);
                }
                if ($amount <= 0) {
                    throw ValidationException::withMessages(['amount' => 'An individual payment must remain positive.']);
                }
                $newPaid = $ledgerPaid - $expenseCorrections->cents($payment->amount) + $amount;
                $oldDate = $payment->payment_date;
            }
            if ($newPaid > $total) {
                throw ValidationException::withMessages(['amount' => 'The corrected payments cannot exceed the expense total.']);
            }
            if ($amount > 0 && $data['payment_date'] < $expense->expense_date && $data['payment_date'] !== $oldDate) {
                throw ValidationException::withMessages(['payment_date' => 'A new payment date cannot be before the expense date. You may retain an existing advance payment date.']);
            }
            $method = trim($data['payment_method'] ?? '') ?: null;
            $reference = trim($data['reference'] ?? '') ?: null;
            $notes = trim($data['notes'] ?? '') ?: null;
            $changed = $paymentId === null
                ? $amount !== $storedPaid || ($amount > 0 && ($expense->paid_at !== $data['payment_date'] || $expense->payment_method !== $method || $expense->payment_reference !== $reference))
                : $amount !== $expenseCorrections->cents($payment->amount) || $payment->payment_date !== $data['payment_date']
                    || $payment->payment_method !== $method || $payment->reference !== $reference || $payment->notes !== $notes;
            if (! $changed) {
                throw ValidationException::withMessages(['expense' => 'No payment details were changed.']);
            }

            if ($expense->unit_id !== null) {
                try {
                    $guard = new AccountingPeriodGuard;
                    $guard->assertOpen((int) $expense->unit_id, $oldDate);
                    if ($amount > 0) {
                        $guard->assertOpen((int) $expense->unit_id, $data['payment_date']);
                    }
                } catch (\RuntimeException $exception) {
                    throw ValidationException::withMessages(['expense' => $exception->getMessage()]);
                }
            }

            $before = $this->state($expense, $payment);
            if ($paymentId !== null) {
                $db->table('expense_payments')->where('id', $paymentId)->where('expense_id', $expenseId)->update([
                    'amount' => $this->money($amount), 'payment_date' => $data['payment_date'],
                    'payment_method' => $method, 'reference' => $reference, 'notes' => $notes, 'updated_at' => now(),
                ]);
                $last = $db->table('expense_payments')->where('expense_id', $expenseId)->orderByDesc('payment_date')->orderByDesc('id')->first();
                $paidAt = $last->payment_date;
                $method = $last->payment_method;
                $reference = $last->reference;
            } else {
                $paidAt = $amount > 0 ? $data['payment_date'] : null;
                if ($amount === 0) {
                    $method = null;
                    $reference = null;
                }
            }
            $db->table('unit_expenses')->where('id', $expenseId)->update([
                'amount_paid' => $this->money($newPaid),
                'payment_status' => $newPaid === 0 ? 'unpaid' : ($newPaid === $total ? 'paid' : 'partial'),
                'paid_at' => $paidAt, 'payment_method' => $method, 'payment_reference' => $reference, 'updated_at' => now(),
            ]);
            $after = $this->state($db->table('unit_expenses')->find($expenseId), $paymentId === null ? null : $db->table('expense_payments')->find($paymentId));
            $db->table('expense_payment_corrections')->insert([
                'expense_id' => $expenseId, 'payment_id' => $paymentId,
                'kind' => $paymentId === null ? 'historical' : 'payment',
                'actor_id' => auth()->id(), 'actor_name' => mb_substr(auth()->user()->name, 0, 255),
                'reason' => trim($data['reason']), 'before_snapshot' => json_encode($before, JSON_THROW_ON_ERROR),
                'after_snapshot' => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
        }, 3);
    }
}
