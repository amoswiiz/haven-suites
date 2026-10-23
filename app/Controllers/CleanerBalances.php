<?php

namespace App\Controllers;

class CleanerBalances extends BaseController
{
    private function allowed(): bool
    {
        $user = auth()->user();
        return $user !== null && $user->can('finance.view');
    }

    public function index()
    {
        if (!$this->allowed()) {
            return service('response')->setStatusCode(403)->setBody('Permission denied.');
        }

        $month = trim((string) ($this->request->getGet('month') ?? ''));
        if ($month !== '' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return redirect()->to(site_url('payments/cleaners/balances'))
                ->with('error', 'Invalid month.');
        }

        $staffId = filter_var($this->request->getGet('staff_id'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]) ?: null;

        $db = db_connect();
        $staff = $db->table('staff')
            ->select('id, full_name')
            ->orderBy('full_name', 'ASC')
            ->get()->getResultArray();

        // Aggregate allocations before joining: one housekeeping task must appear once.
        $paidSql = '(SELECT housekeeping_id, SUM(amount) AS allocated '
            . 'FROM cleaner_payment_allocations GROUP BY housekeeping_id) paid';

        $builder = $db->table('housekeeping h')
            ->select('h.id, h.assigned_staff_id, h.completed_at, h.cleaning_type, '
                . 'h.cleaning_charge, h.status, s.full_name AS cleaner_name, '
                . 'u.unit_number, p.name AS property_name, '
                . 'COALESCE(paid.allocated, 0) AS paid_amount')
            ->join('staff s', 's.id = h.assigned_staff_id', 'left')
            ->join('units u', 'u.id = h.unit_id', 'left')
            ->join('properties p', 'p.id = u.property_id', 'left')
            ->join($paidSql, 'paid.housekeeping_id = h.id', 'left', false)
            ->whereIn('h.status', ['completed', 'verified'])
            ->where('h.cleaning_charge >', 0);

        if ($month !== '') {
            $start = $month . '-01';
            $end = date('Y-m-d', strtotime($start . ' +1 month'));
            $builder->where('h.completed_at >=', $start)
                ->where('h.completed_at <', $end);
        }
        if ($staffId !== null) {
            $builder->where('h.assigned_staff_id', $staffId);
        }

        $tasks = $builder->orderBy('h.completed_at', 'DESC')->get()->getResultArray();
        $byCleaner = [];
        $totals = ['charges' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0, 'tasks' => 0];
        $outstandingTasks = [];

        foreach ($tasks as &$task) {
            $charge = round((float) $task['cleaning_charge'], 2);
            $paid = round((float) $task['paid_amount'], 2);
            $outstanding = max(0, round($charge - $paid, 2));
            $task['balance'] = $outstanding;
            $key = $task['assigned_staff_id'] === null
                ? 'unassigned' : 'staff-' . $task['assigned_staff_id'];
            if (!isset($byCleaner[$key])) {
                $byCleaner[$key] = [
                    'staff_id' => $task['assigned_staff_id'],
                    'name' => $task['cleaner_name'] ?: 'Unassigned',
                    'charges' => 0.0,
                    'paid' => 0.0,
                    'outstanding' => 0.0,
                    'tasks' => 0,
                ];
            }
            $byCleaner[$key]['charges'] += $charge;
            $byCleaner[$key]['paid'] += $paid;
            $byCleaner[$key]['outstanding'] += $outstanding;
            $byCleaner[$key]['tasks']++;
            $totals['charges'] += $charge;
            $totals['paid'] += $paid;
            $totals['outstanding'] += $outstanding;
            $totals['tasks']++;
            if ($outstanding > 0) {
                $outstandingTasks[] = $task;
            }
        }
        unset($task);

        uasort($byCleaner, static fn ($a, $b) => $b['outstanding'] <=> $a['outstanding']);

        return view('cleaner_payments/balances', [
            'pageTitle' => 'Cleaner Outstanding Balances',
            'month' => $month,
            'staffId' => $staffId,
            'staff' => $staff,
            'totals' => $totals,
            'byCleaner' => $byCleaner,
            'outstandingTasks' => $outstandingTasks,
        ]);
    }
}
