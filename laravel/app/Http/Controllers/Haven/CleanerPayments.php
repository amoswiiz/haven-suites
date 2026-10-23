<?php

namespace App\Http\Controllers\Haven;

use App\Models\Haven\CleanerPaymentModel;
use App\Models\Haven\CleanerPaymentAllocationModel;
use Illuminate\Http\RedirectResponse;

class CleanerPayments extends BaseController
{
    private function authorized(): bool
    {
        $user = haven_auth()->user();
        return $user !== null && $user->can('finance.view');
    }

    private function forbidden()
    {
        return haven_service('response')->setStatusCode(403)->setContent('Permission denied.');
    }

    private function moneyToCents($value): ?int
    {
        if (!is_scalar($value) || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', trim((string) $value))) {
            return null;
        }
        $parts = explode('.', trim((string) $value), 2);
        return ((int) $parts[0] * 100) + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    private function centsToMoney(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    /** Only manager-verified cleaning jobs can be paid. */
    private function eligibleTasks(int $staffId): array
    {
        $db = db_connect();
        $rows = $db->table('housekeeping h')
            ->select('h.id, h.completed_at, h.cleaning_type, h.cleaning_charge, h.status, u.unit_number, p.name AS property_name')
            ->join('units u', 'u.id = h.unit_id', 'left')
            ->join('properties p', 'p.id = u.property_id', 'left')
            ->where('h.assigned_staff_id', $staffId)
            ->where('h.status', 'verified')
            ->where('h.cleaning_charge >', 0)
            ->orderBy('h.completed_at', 'ASC')
            ->get()->getResultArray();

        $allocated = $db->table('cleaner_payment_allocations')
            ->select('housekeeping_id, SUM(amount) AS paid')
            ->groupBy('housekeeping_id')
            ->get()->getResultArray();
        $paidByTask = [];
        foreach ($allocated as $entry) {
            $paidByTask[(int) $entry['housekeeping_id']] = $this->moneyToCents($entry['paid']) ?? 0;
        }

        $available = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $remaining = ($this->moneyToCents($row['cleaning_charge']) ?? 0) - ($paidByTask[$id] ?? 0);
            if ($remaining <= 0) {
                continue;
            }
            $row['remaining'] = $this->centsToMoney($remaining);
            $available[] = $row;
        }
        return $available;
    }

    public function index()
    {
        if (!$this->authorized()) {
            return $this->forbidden();
        }
        $db = db_connect();
        $payments = $db->table('cleaner_payments cp')
            ->select('cp.*, s.full_name AS cleaner_name')
            ->join('staff s', 's.id = cp.staff_id', 'left')
            ->orderBy('cp.payment_date', 'DESC')
            ->orderBy('cp.id', 'DESC')
            ->get()->getResultArray();
        return haven_view('cleaner_payments/index', [
            'pageTitle' => 'Cleaner Payments',
            'payments' => $payments,
        ]);
    }

    public function create()
    {
        if (!$this->authorized()) {
            return $this->forbidden();
        }
        $db = db_connect();
        $staff = $db->table('staff')
            ->select('id, full_name')
            ->orderBy('full_name', 'ASC')
            ->get()->getResultArray();
        $staffId = filter_var($this->request->getGet('staff_id'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]) ?: null;
        return haven_view('cleaner_payments/create', [
            'pageTitle' => 'Record Cleaner Payment',
            'staff' => $staff,
            'staffId' => $staffId,
            'tasks' => $staffId ? $this->eligibleTasks($staffId) : [],
        ]);
    }

    public function store()
    {
        if (!haven_auth()->user() || !haven_auth()->user()->can('finance.manage')) {
            return $this->forbidden();
        }
        $staffId = filter_var($this->request->getPost('staff_id'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $date = (string) $this->request->getPost('payment_date');
        $method = (string) $this->request->getPost('payment_method');
        $reference = trim((string) $this->request->getPost('reference'));
        $notes = trim((string) $this->request->getPost('notes'));
        $allocations = $this->request->getPost('allocations');
        $amountCents = $this->moneyToCents($this->request->getPost('amount'));
        $dateObj = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$staffId || !$dateObj || $dateObj->format('Y-m-d') !== $date
            || !in_array($method, ['mpesa', 'bank', 'cash'], true)
            || $amountCents === null || $amountCents <= 0
            || strlen($reference) > 100 || strlen($notes) > 10000
            || !is_array($allocations)) {
            return redirect()->back()->withInput()->with('error', 'Check the payment details and try again.');
        }

        $requested = [];
        $sumCents = 0;
        foreach ($allocations as $id => $value) {
            if (!ctype_digit((string) $id) || (int) $id < 1) {
                return redirect()->back()->withInput()->with('error', 'Invalid task ID.');
            }
            if ($value === '' || $value === null) {
                continue;
            }
            $cents = $this->moneyToCents($value);
            if ($cents === null) {
                return redirect()->back()->withInput()->with('error', 'Invalid allocation amount.');
            }
            if ($cents === 0) {
                continue;
            }
            $requested[(int) $id] = $cents;
            $sumCents += $cents;
        }
        if ($requested === [] || $sumCents !== $amountCents) {
            return redirect()->back()->withInput()->with('error', 'Payment amount must equal the sum allocated to tasks.');
        }
        ksort($requested, SORT_NUMERIC);

        $db = db_connect();
        $db->transBegin();
        try {
            // Lock the cleaner's staff row so concurrent payments for the same
            // cleaner are serialized, preventing double allocations.
            $staffRows = $db->query('SELECT id FROM staff WHERE id = ? FOR UPDATE', [(int) $staffId])->getResultArray();
            if (!$staffRows) {
                throw new \RuntimeException('Cleaner not found.');
            }
            // Lock every affected unit in a deterministic order before locking tasks.
            $unitIds = [];
            foreach (array_keys($requested) as $taskId) {
                $taskUnit = $db->table('housekeeping')->select('unit_id')->where('id', $taskId)->get()->getRowArray();
                if (!$taskUnit) throw new \RuntimeException('Cleaning task not found.');
                $unitIds[(int)$taskUnit['unit_id']] = (int)$taskUnit['unit_id'];
            }
            sort($unitIds, SORT_NUMERIC);
            $guard = new \App\Services\AccountingPeriodGuard($db);
            foreach ($unitIds as $unitId) {
                if (!$db->query('SELECT id FROM units WHERE id = ? FOR UPDATE', [$unitId])->getRowArray()) {
                    throw new \RuntimeException('Cleaning unit not found.');
                }
                $guard->assertOpen($unitId, $date);
            }
            foreach ($requested as $taskId => $cents) {
                $task = $db->query(
                    'SELECT id, unit_id, cleaning_charge, status, assigned_staff_id FROM housekeeping WHERE id = ? FOR UPDATE',
                    [$taskId]
                )->getRowArray();
                if (!$task || !in_array((int)$task['unit_id'], $unitIds, true)
                    || (int) $task['assigned_staff_id'] !== (int) $staffId
                    || $task['status'] !== 'verified') {
                    throw new \RuntimeException('A selected task is not eligible for payment.');
                }
                $paid = $db->table('cleaner_payment_allocations')
                    ->selectSum('amount', 'paid')
                    ->where('housekeeping_id', $taskId)
                    ->get()->getRowArray();
                $paidCents = $this->moneyToCents($paid['paid'] ?? '0') ?? 0;
                $chargeCents = $this->moneyToCents($task['cleaning_charge']) ?? 0;
                if ($cents > $chargeCents - $paidCents) {
                    throw new \RuntimeException('A task allocation exceeds its outstanding balance. Refresh and try again.');
                }
            }

            $paymentModel = new CleanerPaymentModel();
            if (!$paymentModel->insert([
                'staff_id' => (int) $staffId,
                'payment_date' => $date,
                'amount' => $this->centsToMoney($amountCents),
                'payment_method' => $method,
                'reference' => $reference !== '' ? $reference : null,
                'notes' => $notes !== '' ? $notes : null,
                'recorded_by' => (int) haven_auth()->user()->id,
            ])) {
                throw new \RuntimeException('Payment could not be saved.');
            }
            $paymentId = (int) $paymentModel->getInsertID();
            $allocationModel = new CleanerPaymentAllocationModel();
            foreach ($requested as $taskId => $cents) {
                if (!$allocationModel->insert([
                    'payment_id' => $paymentId,
                    'housekeeping_id' => $taskId,
                    'amount' => $this->centsToMoney($cents),
                ])) {
                    throw new \RuntimeException('Payment allocation could not be saved.');
                }
            }
            // Keep accounting balances in sync within the same payment transaction.
            $accounting = new \App\Services\HousekeepingExpenseSync();
            foreach (array_keys($requested) as $taskId) {
                $accounting->syncTask((int) $taskId);
            }
            if ($db->transStatus() === false) {
                throw new \RuntimeException('Database transaction failed.');
            }
            $db->transCommit();
            return redirect()->to(site_url('payments/cleaners'))
                ->with('success', 'Cleaner payment recorded successfully.');
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Cleaner payment failed: {error}', ['error' => $e->getMessage()]);
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }
}
