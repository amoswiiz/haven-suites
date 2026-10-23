<?php

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;

class ExpensePayments extends BaseController
{
    private function allowed(string $permission): bool
    {
        $user = auth()->user();
        return $user !== null && $user->can($permission);
    }

    private function forbidden()
    {
        return service('response')->setStatusCode(403)->setBody('Permission denied.');
    }

    private function cents($value): ?int
    {
        if (!is_scalar($value) || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', trim((string) $value))) {
            return null;
        }
        $parts = explode('.', trim((string) $value), 2);
        return ((int) $parts[0] * 100) + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    private function money(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    private function isHousekeepingExpense(array $expense): bool
    {
        return ($expense['source_type'] ?? '') === 'housekeeping' && !empty($expense['source_id']);
    }

    public function show(int $id)
    {
        if (!$this->allowed('finance.view')) {
            return $this->forbidden();
        }

        $db = db_connect();
        $expense = $db->table('unit_expenses e')
            ->select('e.*, u.unit_number, p.name AS property_name, c.name AS category_name')
            ->join('units u', 'u.id = e.unit_id', 'left')
            ->join('properties p', 'p.id = u.property_id', 'left')
            ->join('expense_categories c', 'c.id = e.category_id', 'left')
            ->where('e.id', $id)
            ->get()->getRowArray();

        if (!$expense) {
            return service('response')->setStatusCode(404)->setBody('Expense not found.');
        }

        $housekeepingLinked = $this->isHousekeepingExpense($expense);
        $payments = [];
        $paidCents = 0;

        if ($housekeepingLinked) {
            $allocations = $db->table('cleaner_payment_allocations a')
                ->select('a.amount, cp.payment_date, cp.payment_method, cp.reference, cp.notes, cp.recorded_by, cp.id')
                ->join('cleaner_payments cp', 'cp.id = a.payment_id', 'inner')
                ->where('a.housekeeping_id', (int) $expense['source_id'])
                ->orderBy('cp.payment_date', 'ASC')
                ->orderBy('cp.id', 'ASC')
                ->get()->getResultArray();
            foreach ($allocations as $allocation) {
                $payments[] = $allocation;
                $paidCents += $this->cents($allocation['amount']) ?? 0;
            }
        } else {
            $payments = $db->table('expense_payments')
                ->where('expense_id', $id)
                ->orderBy('payment_date', 'ASC')
                ->orderBy('id', 'ASC')
                ->get()->getResultArray();
            foreach ($payments as $payment) {
                $paidCents += $this->cents($payment['amount']) ?? 0;
            }
        }

        $totalCents = $this->cents($expense['amount']) ?? 0;
        $cachedPaidCents = $this->cents($expense['amount_paid']) ?? 0;

        return view('unit_expenses/payments', [
            'pageTitle' => 'Expense Payments',
            'expense' => $expense,
            'payments' => $payments,
            'total' => $this->money($totalCents),
            'paid' => $this->money($paidCents),
            'balance' => $this->money(max(0, $totalCents - $paidCents)),
            'reconciliationIssue' => $paidCents !== $cachedPaidCents || $paidCents > $totalCents,
            'canManage' => $this->allowed('finance.manage') && !$housekeepingLinked,
            'housekeepingLinked' => $housekeepingLinked,
        ]);
    }

    public function store(int $id): RedirectResponse|\CodeIgniter\HTTP\ResponseInterface
    {
        if (!$this->allowed('finance.manage')) {
            return $this->forbidden();
        }

        $date = trim((string) $this->request->getPost('payment_date'));
        $method = trim((string) $this->request->getPost('payment_method'));
        $reference = trim((string) $this->request->getPost('reference'));
        $notes = trim((string) $this->request->getPost('notes'));
        $amountCents = $this->cents($this->request->getPost('amount'));
        $dateObject = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if (!$dateObject || $dateObject->format('Y-m-d') !== $date
            || !in_array($method, ['mpesa', 'bank', 'cash'], true)
            || $amountCents === null || $amountCents <= 0
            || strlen($reference) > 100 || strlen($notes) > 10000) {
            return redirect()->back()->withInput()->with('error', 'Please check the payment details.');
        }

        $db = db_connect();
        $db->transBegin();
        try {
            $expenseUnit = $db->table('unit_expenses')->select('unit_id')->where('id', $id)->get()->getRowArray();
            if (!$expenseUnit) {
                throw new \RuntimeException('Expense not found.');
            }
            $unitId = (int) $expenseUnit['unit_id'];
            if (!$db->query('SELECT id FROM units WHERE id = ? FOR UPDATE', [$unitId])->getRowArray()) {
                throw new \RuntimeException('Expense unit not found.');
            }
            (new \App\Services\AccountingPeriodGuard($db))->assertOpen($unitId, $date);
            $expense = $db->query('SELECT id, amount, amount_paid, source_type, source_id, unit_id FROM unit_expenses WHERE id = ? FOR UPDATE', [$id])->getRowArray();
            if (!$expense || (int)$expense['unit_id'] !== $unitId) {
                throw new \RuntimeException('Expense changed while processing. Please retry.');
            }
            if ($this->isHousekeepingExpense($expense)) {
                throw new \RuntimeException('This cleaning expense is paid through Cleaner Payments. Record and allocate the payment there.');
            }

            $payments = $db->table('expense_payments')
                ->select('amount')->where('expense_id', $id)->get()->getResultArray();
            $paidCents = 0;
            foreach ($payments as $payment) {
                $paidCents += $this->cents($payment['amount']) ?? 0;
            }

            $cachedPaidCents = $this->cents($expense['amount_paid']) ?? 0;
            $totalCents = $this->cents($expense['amount']) ?? 0;
            if ($paidCents !== $cachedPaidCents || $paidCents > $totalCents) {
                throw new \RuntimeException('Payment records do not match the expense total. Reconcile this expense before adding payments.');
            }
            if ($amountCents > $totalCents - $paidCents) {
                throw new \RuntimeException('Payment exceeds the outstanding expense balance.');
            }

            $now = date('Y-m-d H:i:s');
            $inserted = $db->table('expense_payments')->insert([
                'expense_id' => $id,
                'payment_date' => $date,
                'amount' => $this->money($amountCents),
                'payment_method' => $method,
                'reference' => $reference !== '' ? $reference : null,
                'notes' => $notes !== '' ? $notes : null,
                'recorded_by' => (int) auth()->user()->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if (!$inserted) {
                throw new \RuntimeException('Could not save payment.');
            }

            $newPaid = $paidCents + $amountCents;
            $updated = $db->table('unit_expenses')->where('id', $id)->update([
                'amount_paid' => $this->money($newPaid),
                'payment_status' => $newPaid === $totalCents ? 'paid' : 'partial',
                'paid_at' => $date,
                'payment_method' => $method,
                'payment_reference' => $reference !== '' ? $reference : null,
                'updated_at' => $now,
            ]);
            if (!$updated || $db->transStatus() === false) {
                throw new \RuntimeException('Could not update expense balance.');
            }

            $db->transCommit();
            return redirect()->to(site_url('expenses/' . $id . '/payments'))
                ->with('success', 'Expense payment recorded successfully.');
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Expense payment error: {message}', ['message' => $e->getMessage()]);
            return redirect()->to(site_url('expenses/' . $id . '/payments'))
                ->with('error', $e->getMessage());
        }
    }
}
