<?php

namespace App\Controllers;

class ExpenseRegister extends BaseController
{
    public function index()
    {
        $user = auth()->user();
        if ($user === null || !$user->can('finance.view')) {
            return service('response')->setStatusCode(403)->setBody('Permission denied.');
        }

        $db = db_connect();
        $month = trim((string) $this->request->getGet('month'));
        $propertyId = filter_var($this->request->getGet('property_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $unitId = filter_var($this->request->getGet('unit_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $categoryId = filter_var($this->request->getGet('category_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $status = trim((string) $this->request->getGet('status'));
        $search = trim((string) $this->request->getGet('q'));

        if ($month !== '' && (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month))) {
            $month = '';
        }
        if (!in_array($status, ['', 'paid', 'partial', 'unpaid'], true)) {
            $status = '';
        }
        $search = mb_substr($search, 0, 100);

        $builder = $db->table('unit_expenses e')
            ->select('e.*, u.unit_number, p.name AS property_name, p.id AS property_id, c.name AS category_name')
            ->join('units u', 'u.id = e.unit_id', 'left')
            ->join('properties p', 'p.id = u.property_id', 'left')
            ->join('expense_categories c', 'c.id = e.category_id', 'left');

        if ($month !== '') {
            $start = $month . '-01';
            $end = (new \DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');
            $builder->where('e.expense_date >=', $start)->where('e.expense_date <', $end);
        }
        if ($propertyId !== null) {
            $builder->where('p.id', $propertyId);
        }
        if ($unitId !== null) {
            $builder->where('e.unit_id', $unitId);
        }
        if ($categoryId !== null) {
            $builder->where('e.category_id', $categoryId);
        }
        if ($status !== '') {
            $builder->where('e.payment_status', $status);
        }
        if ($search !== '') {
            $builder->groupStart()
                ->like('e.description', $search)
                ->orLike('e.supplier_name', $search)
                ->orLike('e.payment_reference', $search)
                ->groupEnd();
        }

        $expenses = $builder->orderBy('e.expense_date', 'DESC')->orderBy('e.id', 'DESC')->get()->getResultArray();
        $totals = ['expenses' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0, 'count' => count($expenses)];
        foreach ($expenses as $expense) {
            $amount = (float) $expense['amount'];
            $paid = (float) $expense['amount_paid'];
            $totals['expenses'] += $amount;
            $totals['paid'] += $paid;
            $totals['outstanding'] += max(0, $amount - $paid);
        }

        return view('unit_expenses/index', [
            'pageTitle' => 'Expense Register',
            'expenses' => $expenses,
            'totals' => $totals,
            'filters' => compact('month', 'propertyId', 'unitId', 'categoryId', 'status', 'search'),
            'properties' => $db->table('properties')->select('id, name')->orderBy('name')->get()->getResultArray(),
            'units' => $db->table('units u')->select('u.id, u.unit_number, u.property_id, p.name AS property_name')
                ->join('properties p', 'p.id = u.property_id', 'left')
                ->orderBy('p.name')->orderBy('u.unit_number')->get()->getResultArray(),
            'categories' => $db->table('expense_categories')->select('id, name')->orderBy('name')->get()->getResultArray(),
        ]);
    }
}
