<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StaffBills
{
    public const CATEGORIES = ['Drinking water', 'Soap and toiletries', 'Cleaning supplies', 'Electricity tokens', 'Gas', 'Other consumables'];

    public function event(object $bill, int $actor, string $action, ?string $note = null): void
    {
        DB::connection('tenant')->table('staff_bill_events')->insert([
            'bill_id' => $bill->id, 'actor_id' => $actor, 'action' => $action, 'note' => $note,
            'snapshot' => json_encode($bill, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    public function review(int $id, int $actor, array $data): void
    {
        $db = DB::connection('tenant');
        $db->transaction(function () use ($db, $id, $actor, $data): void {
            $initial = $db->table('staff_bills')->find($id);
            abort_unless($initial, 404);
            $db->table('units')->where('id', $initial->unit_id)->lockForUpdate()->firstOrFail();
            $bill = $db->table('staff_bills')->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_if((int) $bill->submitted_by === $actor, 403, 'You cannot review your own bill.');
            if ($bill->status !== 'pending' || (int) $bill->version !== (int) $data['version'] || $bill->unit_id !== $initial->unit_id) {
                throw ValidationException::withMessages(['bill' => 'This bill changed. Refresh before reviewing.']);
            }
            $changes = ['status' => $data['action'], 'version' => $bill->version + 1, 'updated_at' => now()];
            if ($data['action'] === 'approved') {
                try {
                    (new AccountingPeriodGuard)->assertOpen((int) $bill->unit_id, $bill->purchase_date);
                } catch (\RuntimeException $e) {
                    throw ValidationException::withMessages(['bill' => $e->getMessage()]);
                }
                if ($bill->payer === 'company') {
                    $expense = $db->table('unit_expenses')->where('id', $data['expense_id'] ?? 0)->lockForUpdate()->first();
                    if (! $expense || (int) $expense->unit_id !== (int) $bill->unit_id
                        || $expense->source_type !== 'manual' || $expense->expense_date !== $bill->purchase_date
                        || (int) round($expense->amount * 100) !== (int) $bill->amount_minor
                        || (int) round($expense->amount_paid * 100) !== (int) $bill->amount_minor
                        || $db->table('staff_bills')->where('expense_id', $expense->id)->exists()) {
                        throw ValidationException::withMessages(['expense_id' => 'Link an unlinked, fully paid manual expense with the same apartment, date and amount. Reconcile company advances first.']);
                    }
                    $changes['expense_id'] = $expense->id;
                } else {
                    $category = $db->table('expense_categories')->where('id', $data['category_id'] ?? 0)->where('is_active', 1)->first();
                    if (! $category || in_array(strtolower($category->name), ['rent', 'cleaning'], true)) {
                        throw ValidationException::withMessages(['category_id' => 'Choose an active supplies or utilities category, not rent or cleaning labour.']);
                    }
                    $changes['expense_id'] = $db->table('unit_expenses')->insertGetId([
                        'unit_id' => $bill->unit_id, 'category_id' => $category->id, 'expense_date' => $bill->purchase_date,
                        'description' => $bill->description, 'amount' => number_format($bill->amount_minor / 100, 2, '.', ''),
                        'amount_paid' => '0.00', 'payment_status' => 'unpaid', 'supplier_name' => $bill->supplier,
                        'notes' => 'Staff bill #'.$bill->id.($bill->payer === 'personal' ? ': reimburse staff #'.$bill->staff_id : ': pay supplier'),
                        'source_type' => 'staff_bill', 'source_id' => $bill->id, 'recorded_by' => haven_auth()->id(),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            $db->table('staff_bills')->where('id', $id)->update($changes);
            $this->event($db->table('staff_bills')->find($id), $actor, $data['action'], $data['note'] ?? null);
        });
    }
}
