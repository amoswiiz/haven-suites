<?php

namespace App\Http\Controllers\Haven;

use App\Models\Haven\UnitExpenseModel;

class UnitExpenses extends BaseController
{
    private function allowed(string $permission): bool
    {
        $user = haven_auth()->user();
        return $user !== null && $user->can($permission);
    }

    private function forbidden()
    {
        return haven_service('response')->setStatusCode(403)->setContent('Permission denied.');
    }

    private function moneyToCents($value): ?int
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);
        if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $value)) {
            return null;
        }
        $parts = explode('.', $value, 2);
        return (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    private function centsToMoney(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    public function create()
    {
        if (!$this->allowed('finance.manage')) {
            return $this->forbidden();
        }
        $db = db_connect();
        $units = $db->table('units u')
            ->select('u.id, u.unit_number, p.name AS property_name')
            ->join('properties p', 'p.id = u.property_id', 'left')
            ->orderBy('p.name', 'ASC')
            ->orderBy('u.unit_number', 'ASC')
            ->get()->getResultArray();
        $categories = $db->table('expense_categories')
            ->select('id, name')->where('is_active', 1)
            ->orderBy('name', 'ASC')->get()->getResultArray();

        return haven_view('unit_expenses/create', [
            'pageTitle' => 'Record Unit Expense',
            'units' => $units,
            'categories' => $categories,
        ]);
    }

    public function store()
    {
        if (!$this->allowed('finance.manage')) {
            return $this->forbidden();
        }
        $unitId = filter_var($this->request->getPost('unit_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $categoryId = filter_var($this->request->getPost('category_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $date = trim((string) $this->request->getPost('expense_date'));
        $description = trim((string) $this->request->getPost('description'));
        $supplier = trim((string) $this->request->getPost('supplier_name'));
        $notes = trim((string) $this->request->getPost('notes'));
        $amount = $this->moneyToCents($this->request->getPost('amount'));
        $paid = $this->moneyToCents($this->request->getPost('amount_paid'));
        $method = trim((string) $this->request->getPost('payment_method'));
        $reference = trim((string) $this->request->getPost('payment_reference'));
        $paidAt = trim((string) $this->request->getPost('paid_at'));

        $validDate = static function (string $value): bool {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            return $parsed !== false && $parsed->format('Y-m-d') === $value;
        };
        $fail = static function (string $message) {
            return redirect()->back()->withInput()->with('error', $message);
        };

        if (!$unitId || !$categoryId || !$validDate($date) || $description === '' || mb_strlen($description) > 255
            || $amount === null || $amount <= 0 || $paid === null || $paid < 0 || $paid > $amount
            || mb_strlen($supplier) > 150 || mb_strlen($reference) > 100 || mb_strlen($notes) > 10000) {
            return $fail('Please check the expense date, description, amounts and other details.');
        }

        if ($paid > 0 && (!in_array($method, ['mpesa', 'bank', 'cash', 'other'], true)
            || !$validDate($paidAt) || $paidAt < $date)) {
            return $fail('Enter a valid payment method and payment date for the amount paid.');
        }
        if ($paid === 0 && ($method !== '' || $paidAt !== '' || $reference !== '')) {
            return $fail('Payment details must be blank for an unpaid expense.');
        }

        $db = db_connect();
        if (!$db->table('units')->where('id', $unitId)->countAllResults()) {
            return $fail('The selected unit does not exist.');
        }
        if (!$db->table('expense_categories')->where('id', $categoryId)->where('is_active', 1)->countAllResults()) {
            return $fail('The selected expense category is unavailable.');
        }

        $db->transBegin();
        try {
            if (!$db->query('SELECT id FROM units WHERE id = ? FOR UPDATE', [(int)$unitId])->getRowArray()) {
                throw new \RuntimeException('Unit not found.');
            }
            $guard = new \App\Services\AccountingPeriodGuard($db);
            $guard->assertOpen((int)$unitId, $date);
            // A payment included with a new expense also affects its cash-payment month.
            if ($paid > 0) {
                $guard->assertOpen((int)$unitId, $paidAt);
            }

            $status = $paid === 0 ? 'unpaid' : ($paid === $amount ? 'paid' : 'partial');
            $model = new UnitExpenseModel();
            $saved = $model->insert([
                'unit_id' => (int)$unitId,
                'category_id' => (int)$categoryId,
                'expense_date' => $date,
                'description' => $description,
                'amount' => $this->centsToMoney($amount),
                'payment_status' => $status,
                'amount_paid' => $this->centsToMoney($paid),
                'paid_at' => $paid > 0 ? $paidAt : null,
                'payment_method' => $paid > 0 ? $method : null,
                'payment_reference' => $paid > 0 && $reference !== '' ? $reference : null,
                'supplier_name' => $supplier !== '' ? $supplier : null,
                'notes' => $notes !== '' ? $notes : null,
                'source_type' => 'manual',
                'source_id' => null,
                'recorded_by' => (int)haven_auth()->user()->id,
            ]);
            if (!$saved || $db->transStatus() === false) {
                throw new \RuntimeException('Expense could not be saved.');
            }
            $db->transCommit();
            return redirect()->to(site_url('expenses/create'))
                ->with('success', 'Expense recorded successfully.');
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Expense insert failed: {message}', ['message' => $e->getMessage()]);
            return $fail($e->getMessage());
        }
    }
}
