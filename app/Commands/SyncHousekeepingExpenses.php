<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class SyncHousekeepingExpenses extends BaseCommand
{
    protected $group = 'Haven Accounting';
    protected $name = 'accounting:sync-cleaning';
    protected $description = 'Synchronize completed housekeeping charges and cleaner allocations with unit expenses.';
    protected $usage = 'accounting:sync-cleaning [--apply]';
    protected $arguments = [];
    protected $options = ['--apply' => 'Write changes. Without this option, preview only.'];

    private function cents($value): int
    {
        $value = trim((string) $value);
        if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $value)) {
            throw new \RuntimeException('Invalid monetary amount: ' . $value);
        }
        $parts = explode('.', $value, 2);
        return (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    private function money(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    public function run(array $params)
    {
        $apply = in_array('--apply', $params, true) || CLI::getOption('apply') !== null;
        $db = Database::connect();
        foreach (['housekeeping', 'unit_expenses', 'expense_categories', 'cleaner_payment_allocations', 'cleaner_payments', 'expense_payments'] as $table) {
            if (!$db->tableExists($table)) {
                CLI::error('Missing table: ' . $table);
                return;
            }
        }
        $category = $db->table('expense_categories')->where('name', 'Cleaning')->get()->getRowArray();
        if (!$category) {
            CLI::error('Cleaning category missing. Run the accounting migrations and category seeder first.');
            return;
        }
        CLI::write($apply ? 'APPLY MODE: updating expenses.' : 'PREVIEW ONLY: no database changes.', $apply ? 'yellow' : 'cyan');
        $tasks = $db->table('housekeeping')
            ->select('id, unit_id, status, completed_at, cleaning_charge, cleaning_type')
            ->whereIn('status', ['completed', 'verified'])
            ->orderBy('id', 'ASC')->get()->getResultArray();
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;
        foreach ($tasks as $task) {
            $id = (int) $task['id'];
            try {
                if (empty($task['completed_at']) || $task['cleaning_charge'] === null || (int) $task['unit_id'] < 1) {
                    $skipped++;
                    CLI::write("SKIP task {$id}: missing completion date, unit, or charge.");
                    continue;
                }
                $amount = $this->cents($task['cleaning_charge']);
                $allocations = $db->table('cleaner_payment_allocations a')
                    ->select('a.amount')->join('cleaner_payments cp', 'cp.id = a.payment_id', 'inner')
                    ->where('a.housekeeping_id', $id)->get()->getResultArray();
                $paid = 0;
                foreach ($allocations as $allocation) {
                    $paid += $this->cents($allocation['amount']);
                }
                if ($paid > $amount) {
                    throw new \RuntimeException('Cleaner allocations exceed cleaning charge. Reconcile before syncing.');
                }
                $existing = $db->table('unit_expenses')->where('source_type', 'housekeeping')->where('source_id', $id)->get()->getRowArray();
                if ($existing) {
                    $manualPayments = $db->table('expense_payments')->where('expense_id', (int) $existing['id'])->countAllResults();
                    if ($manualPayments > 0) {
                        throw new \RuntimeException('Linked expense has ordinary expense payments; manual reconciliation required.');
                    }
                }
                $date = substr((string) $task['completed_at'], 0, 10);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    throw new \RuntimeException('Invalid completion date.');
                }
                $status = $paid === 0 ? 'unpaid' : ($paid === $amount ? 'paid' : 'partial');
                $data = [
                    'unit_id' => (int) $task['unit_id'],
                    'category_id' => (int) $category['id'],
                    'expense_date' => $date,
                    'description' => 'Housekeeping #' . $id . ' - ' . str_replace('_', ' ', (string) ($task['cleaning_type'] ?? 'cleaning')),
                    'amount' => $this->money($amount),
                    'amount_paid' => $this->money($paid),
                    'payment_status' => $status,
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
                CLI::write(($existing ? 'UPDATE' : 'CREATE') . " task {$id}: {$date}, KES " . $this->money($amount) . ', paid KES ' . $this->money($paid));
                if ($apply) {
                    $db->transBegin();
                    try {
                        if ($existing) {
                            $db->table('unit_expenses')->where('id', (int) $existing['id'])->update($data);
                        } else {
                            $data['source_type'] = 'housekeeping';
                            $data['source_id'] = $id;
                            $data['created_at'] = date('Y-m-d H:i:s');
                            $db->table('unit_expenses')->insert($data);
                        }
                        if ($db->transStatus() === false) {
                            throw new \RuntimeException('Database write failed.');
                        }
                        $db->transCommit();
                    } catch (\Throwable $e) {
                        $db->transRollback();
                        throw $e;
                    }
                }
                $existing ? $updated++ : $created++;
            } catch (\Throwable $e) {
                $errors++;
                CLI::error("Task {$id}: " . $e->getMessage());
            }
        }
        CLI::write("Finished. Create: {$created}; update: {$updated}; skipped: {$skipped}; errors: {$errors}.");
        if (!$apply) {
            CLI::write('To save changes: php spark accounting:sync-cleaning --apply', 'yellow');
        }
        if ($errors) {
            CLI::write('Resolve errors before relying on the report.', 'red');
        }
    }
}
