<?php

namespace App\Http\Controllers\Haven;

class CleanerStatements extends BaseController
{
    public function index()
    {
        $user = haven_auth()->user();
        if ($user === null || !$user->can('finance.view')) {
            return haven_service('response')->setStatusCode(403)->setContent('Permission denied.');
        }

        $db = db_connect();
        $staff = $db->table('staff')->select('id, full_name')->orderBy('full_name', 'ASC')->get()->getResultArray();
        $staffId = filter_var($this->request->getGet('staff_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $from = trim((string) ($this->request->getGet('from') ?? date('Y-m-01')));
        $to = trim((string) ($this->request->getGet('to') ?? date('Y-m-t')));
        $validDate = static function (string $value): bool {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            return $date !== false && $date->format('Y-m-d') === $value;
        };
        if (!$validDate($from) || !$validDate($to) || $from > $to) {
            return redirect()->to(site_url('payments/cleaners/statements'))
                ->with('error', 'Choose a valid date range.');
        }

        $selectedStaff = null;
        foreach ($staff as $member) {
            if ((int) $member['id'] === $staffId) {
                $selectedStaff = $member;
                break;
            }
        }
        if ($staffId !== null && $selectedStaff === null) {
            return redirect()->to(site_url('payments/cleaners/statements'))
                ->with('error', 'Cleaner not found.');
        }

        $tasks = [];
        $totals = ['charges' => 0.0, 'paid' => 0.0, 'balance' => 0.0];
        if ($selectedStaff !== null) {
            // Select work completed within the requested period. Payments are
            // allocated to those tasks and counted only up to the period end.
            $paidSql = '(SELECT a.housekeeping_id, SUM(a.amount) AS paid '
                . 'FROM cleaner_payment_allocations a '
                . 'INNER JOIN cleaner_payments cp ON cp.id = a.payment_id '
                . 'WHERE cp.payment_date <= ' . $db->escape($to) . ' '
                . 'GROUP BY a.housekeeping_id) paid';

            $tasks = $db->table('housekeeping h')
                ->select('h.id, h.completed_at, h.cleaning_type, h.bedrooms_cleaned, '
                    . 'h.cleaning_charge, h.status, u.unit_number, p.name AS property_name, '
                    . 'COALESCE(paid.paid, 0) AS paid_amount')
                ->join('units u', 'u.id = h.unit_id', 'left')
                ->join('properties p', 'p.id = u.property_id', 'left')
                ->join($paidSql, 'paid.housekeeping_id = h.id', 'left', false)
                ->where('h.assigned_staff_id', $staffId)
                ->whereIn('h.status', ['completed', 'verified'])
                ->where('h.completed_at >=', $from)
                ->where('h.completed_at <', (new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d'))
                ->orderBy('h.completed_at', 'ASC')
                ->get()->getResultArray();

            foreach ($tasks as &$task) {
                $charge = round((float) ($task['cleaning_charge'] ?? 0), 2);
                $paid = round((float) ($task['paid_amount'] ?? 0), 2);
                $task['balance'] = round($charge - $paid, 2);
                $totals['charges'] += $charge;
                $totals['paid'] += $paid;
                $totals['balance'] += $task['balance'];
            }
            unset($task);
        }

        return haven_view('cleaner_payments/statement', [
            'pageTitle' => 'Cleaner Payment Statement',
            'staff' => $staff,
            'staffId' => $staffId,
            'selectedStaff' => $selectedStaff,
            'from' => $from,
            'to' => $to,
            'tasks' => $tasks,
            'totals' => $totals,
        ]);
    }
}
