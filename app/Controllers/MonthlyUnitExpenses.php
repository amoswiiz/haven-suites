<?php

namespace App\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;

class MonthlyUnitExpenses extends BaseController
{
    public function index()
    {
        $user = auth()->user();
        if ($user === null || !$user->can('finance.view')) {
            return service('response')->setStatusCode(403)->setBody('Permission denied.');
        }

        $db = db_connect();
        $month = (string) ($this->request->getGet('month') ?: date('Y-m'));
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $month = date('Y-m');
        }
        $start = $month . '-01';
        $end = date('Y-m-t', strtotime($start));
        $propertyId = max(0, (int) $this->request->getGet('property_id'));
        $unitId = max(0, (int) $this->request->getGet('unit_id'));

        $properties = $db->table('properties')->select('id, name')->orderBy('name')->get()->getResultArray();
        $units = $db->table('units u')
            ->select('u.id, u.unit_number, u.property_id, p.name AS property_name')
            ->join('properties p', 'p.id = u.property_id', 'left')
            ->orderBy('p.name')->orderBy('u.unit_number')->get()->getResultArray();

        // Payment totals are calculated as of month-end, not from the current cached balance.
        // Cleaner payments are sourced only from their allocations; other expenses use expense_payments.
        $ordinaryPaid = '(SELECT ep.expense_id, SUM(CASE WHEN ep.payment_date <= ' . $db->escape($end) . ' THEN ep.amount ELSE 0 END) AS paid_to_end, '
            . 'SUM(CASE WHEN ep.payment_date BETWEEN ' . $db->escape($start) . ' AND ' . $db->escape($end) . ' THEN ep.amount ELSE 0 END) AS paid_in_month '
            . 'FROM expense_payments ep GROUP BY ep.expense_id)';
        $cleanerPaid = '(SELECT cpa.housekeeping_id, SUM(CASE WHEN cp.payment_date <= ' . $db->escape($end) . ' THEN cpa.amount ELSE 0 END) AS paid_to_end, '
            . 'SUM(CASE WHEN cp.payment_date BETWEEN ' . $db->escape($start) . ' AND ' . $db->escape($end) . ' THEN cpa.amount ELSE 0 END) AS paid_in_month '
            . 'FROM cleaner_payment_allocations cpa INNER JOIN cleaner_payments cp ON cp.id = cpa.payment_id GROUP BY cpa.housekeeping_id)';

        $builder = $db->table('unit_expenses e')
            ->select('e.id, e.unit_id, e.expense_date, e.description, e.supplier_name, e.amount, e.source_type, e.source_id, '
                . 'u.unit_number, p.name AS property_name, c.name AS category_name, '
                . 'CASE WHEN e.source_type = \'housekeeping\' THEN COALESCE(hp.paid_to_end, 0) ELSE COALESCE(op.paid_to_end, 0) END AS paid_to_end, '
                . 'CASE WHEN e.source_type = \'housekeeping\' THEN COALESCE(hp.paid_in_month, 0) ELSE COALESCE(op.paid_in_month, 0) END AS paid_in_month', false)
            ->join('units u', 'u.id = e.unit_id', 'left')
            ->join('properties p', 'p.id = u.property_id', 'left')
            ->join('expense_categories c', 'c.id = e.category_id', 'left')
            ->join($ordinaryPaid . ' op', 'op.expense_id = e.id', 'left', false)
            ->join($cleanerPaid . ' hp', 'hp.housekeeping_id = e.source_id AND e.source_type = \'housekeeping\'', 'left', false)
            ->where('e.expense_date >=', $start)
            ->where('e.expense_date <=', $end);

        if ($propertyId > 0) {
            $builder->where('u.property_id', $propertyId);
        }
        if ($unitId > 0) {
            $builder->where('e.unit_id', $unitId);
        }

        $expenses = $builder->orderBy('e.expense_date', 'ASC')->orderBy('e.id', 'ASC')->get()->getResultArray();
        $totals = ['incurred' => 0.0, 'paidByEnd' => 0.0, 'outstandingAtEnd' => 0.0, 'paidInMonthForTheseExpenses' => 0.0];
        $categories = [];
        foreach ($expenses as &$expense) {
            $amount = round((float) $expense['amount'], 2);
            $paid = round((float) $expense['paid_to_end'], 2);
            $expense['balance_at_end'] = round($amount - $paid, 2);
            $totals['incurred'] += $amount;
            $totals['paidByEnd'] += $paid;
            $totals['outstandingAtEnd'] += $expense['balance_at_end'];
            $totals['paidInMonthForTheseExpenses'] += (float) $expense['paid_in_month'];
            $category = $expense['category_name'] ?: 'Uncategorized';
            $categories[$category] = ($categories[$category] ?? 0) + $amount;
        }
        unset($expense);
        ksort($categories);

        // This is not a full cash-outflow report: payments against prior-month expenses
        // are deliberately excluded from the "paid in month for these expenses" figure.
        return view('reports/monthly_unit_expenses', [
            'pageTitle' => 'Monthly Unit Expense Report',
            'month' => $month,
            'propertyId' => $propertyId,
            'unitId' => $unitId,
            'properties' => $properties,
            'units' => $units,
            'expenses' => $expenses,
            'totals' => $totals,
            'categories' => $categories,
            'reportEnd' => $end,
        ]);
    }
}
