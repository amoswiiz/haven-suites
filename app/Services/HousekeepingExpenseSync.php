<?php

namespace App\Services;

use Config\Database;

class HousekeepingExpenseSync
{
    private function cents($value): int
    {
        $value = trim((string) $value);
        if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $value)) {
            throw new \RuntimeException('Invalid cleaning amount: ' . $value);
        }
        $parts = explode('.', $value, 2);
        return (int)$parts[0] * 100 + (int)str_pad($parts[1] ?? '', 2, '0');
    }

    private function money(int $value): string
    {
        return sprintf('%d.%02d', intdiv($value, 100), $value % 100);
    }

    /**
     * Sync a completed cleaning task and its expense atomically.
     * The default database connection is shared with the caller's transaction.
     * Cash-only updates to amount_paid/status are permitted when the original
     * expense month is locked, provided the caller checks the cash payment month.
     */
    public function syncTask(int $taskId): void
    {
        $db = Database::connect();
        $db->transBegin(); // CI4 nested transactions participate in the outer transaction.
        try {
            $initial = $db->table('housekeeping')->select('unit_id')->where('id', $taskId)->get()->getRowArray();
            if (!$initial) {
                throw new \RuntimeException('Cleaning task not found.');
            }
            $unitId = (int)$initial['unit_id'];
            if (!$db->query('SELECT id FROM units WHERE id = ? FOR UPDATE', [$unitId])->getRowArray()) {
                throw new \RuntimeException('Cleaning unit not found.');
            }
            $task = $db->query('SELECT * FROM housekeeping WHERE id = ? FOR UPDATE', [$taskId])->getRowArray();
            if (!$task || (int)$task['unit_id'] !== $unitId) {
                throw new \RuntimeException('Cleaning task changed. Please retry.');
            }
            if (!in_array($task['status'], ['completed', 'verified'], true)) {
                $db->transCommit();
                return;
            }
            if (empty($task['completed_at']) || $task['cleaning_charge'] === null) {
                throw new \RuntimeException('Completed cleaning task has no completion date or charge.');
            }
            $date = substr((string)$task['completed_at'], 0, 10);
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                throw new \RuntimeException('Invalid cleaning completion date.');
            }
            $amount = $this->cents($task['cleaning_charge']);
            $category = $db->table('expense_categories')->where('name', 'Cleaning')->get()->getRowArray();
            if (!$category) {
                throw new \RuntimeException('Cleaning expense category is missing.');
            }
            $allocations = $db->table('cleaner_payment_allocations')
                ->select('amount')->where('housekeeping_id', $taskId)->get()->getResultArray();
            $paid = 0;
            foreach ($allocations as $allocation) {
                $paid += $this->cents($allocation['amount']);
            }
            if ($paid > $amount) {
                throw new \RuntimeException('Cleaner payments exceed cleaning charge for task #' . $taskId);
            }
            $existing = $db->table('unit_expenses')
                ->where('source_type', 'housekeeping')->where('source_id', $taskId)
                ->get()->getRowArray();
            if ($existing && $db->table('expense_payments')->where('expense_id', $existing['id'])->countAllResults() > 0) {
                throw new \RuntimeException('Task #' . $taskId . ' has ordinary expense payments; reconcile first.');
            }
            $description = 'Housekeeping #' . $taskId . ' - ' . str_replace('_', ' ', (string)($task['cleaning_type'] ?? 'cleaning'));
            $newRecognized = [
                'unit_id' => $unitId,
                'category_id' => (int)$category['id'],
                'expense_date' => $date,
                'description' => $description,
                'amount' => $this->money($amount),
            ];
            $guard = new AccountingPeriodGuard($db);
            if ($existing) {
                $recognizedChanged = (int)$existing['unit_id'] !== $unitId
                    || (int)$existing['category_id'] !== (int)$category['id']
                    || (string)$existing['expense_date'] !== $date
                    || (string)$existing['description'] !== $description
                    || $this->cents($existing['amount']) !== $amount;
                if ($recognizedChanged) {
                    $oldUnitId = (int)$existing['unit_id'];
                    if ($oldUnitId !== $unitId) {
                        // Lock the previous unit too before checking its closed month.
                        if (!$db->query('SELECT id FROM units WHERE id = ? FOR UPDATE', [$oldUnitId])->getRowArray()) {
                            throw new \RuntimeException('Previous cleaning unit not found.');
                        }
                    }
                    $guard->assertOpenForChange($oldUnitId, (string)$existing['expense_date']);
                    $guard->assertOpen($unitId, $date);
                }
            } else {
                $guard->assertOpen($unitId, $date);
            }
            $paidChanged = !$existing || $this->cents($existing['amount_paid']) !== $paid;
            if ($existing && !$recognizedChanged && !$paidChanged) {
                $db->transCommit();
                return;
            }
            $now = date('Y-m-d H:i:s');
            $data = $newRecognized + [
                'amount_paid' => $this->money($paid),
                'payment_status' => $paid === 0 ? 'unpaid' : ($paid === $amount ? 'paid' : 'partial'),
                'updated_at' => $now,
            ];
            if ($existing) {
                $ok = $db->table('unit_expenses')->where('id', $existing['id'])->update($data);
            } else {
                $ok = $db->table('unit_expenses')->insert($data + [
                    'source_type' => 'housekeeping', 'source_id' => $taskId, 'created_at' => $now,
                ]);
            }
            if (!$ok || $db->transStatus() === false) {
                throw new \RuntimeException('Could not synchronize cleaning expense #' . $taskId);
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }
    }
}
