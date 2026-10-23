<?php

namespace App\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * Monthly cleaning work and payment overview.
 * Cleaner: own jobs only. Manager: all cleaners, optional staff filter.
 * Payment allocations are aggregated per task to prevent duplicate totals.
 */
class CleanerEarnings extends BaseController
{
    public function index()
    {
        $user = auth()->user();
        if ($user === null) {
            return redirect()->to(site_url('login'));
        }

        $isCleaner = $user->inGroup('cleaner');
        $canManage = $user->can('finance.view') || $user->can('housekeeping.manage');
        if (!$isCleaner && !$canManage) {
            return redirect()->to(site_url('dashboard'))
                ->with('error', 'You do not have permission to view cleaner earnings.');
        }

        $canVerify = !$isCleaner
            && $user->can('housekeeping.manage')
            && $user->can('housekeeping.verify');

        $db = db_connect();
        $month = trim((string) ($this->request->getGet('month') ?? date('Y-m')));
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return redirect()->to(site_url('payments/cleaners/earnings'))
                ->with('error', 'Please select a valid month.');
        }
        $start = $month . '-01';
        $end = (new \DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');

        $staffId = null;
        $staff = [];
        if ($isCleaner) {
            $linked = $db->table('staff')->select('id, full_name')
                ->where('user_id', $user->id)->get()->getRowArray();
            if (!$linked) {
                return redirect()->to(site_url('dashboard'))
                    ->with('error', 'Your login is not linked to a staff record. Ask an administrator to link your account.');
            }
            $staffId = (int) $linked['id'];
        } else {
            $staff = $db->table('staff')->select('id, full_name')
                ->orderBy('full_name', 'ASC')->get()->getResultArray();
            $input = $this->request->getGet('staff_id');
            if ($input !== null && $input !== '') {
                if (!is_scalar($input) || !ctype_digit((string) $input) || (int) $input < 1) {
                    return redirect()->to(site_url('payments/cleaners/earnings'))
                        ->with('error', 'Invalid cleaner selection.');
                }
                $staffId = (int) $input;
                if (!$db->table('staff')->where('id', $staffId)->countAllResults()) {
                    throw PageNotFoundException::forPageNotFound('Staff member not found.');
                }
            }
        }

        $allocations = '(SELECT housekeeping_id, SUM(amount) AS total_paid '
            . 'FROM cleaner_payment_allocations GROUP BY housekeeping_id) allocations';

        $query = $db->table('housekeeping h')
            ->select('h.id, h.assigned_staff_id, h.completed_at, h.cleaning_type, '
                . 'h.bedrooms_cleaned, h.cleaning_charge, h.status, h.notes, '
                . 's.full_name AS cleaner_name, u.unit_number, p.name AS property_name, '
                . 'COALESCE(allocations.total_paid, 0) AS paid_amount')
            ->join('staff s', 's.id = h.assigned_staff_id', 'left')
            ->join('units u', 'u.id = h.unit_id', 'left')
            ->join('properties p', 'p.id = u.property_id', 'left')
            ->join($allocations, 'allocations.housekeeping_id = h.id', 'left', false)
            ->whereIn('h.status', ['completed', 'verified'])
            ->where('h.completed_at >=', $start . ' 00:00:00')
            ->where('h.completed_at <', $end . ' 00:00:00');

        if ($staffId !== null) {
            $query->where('h.assigned_staff_id', $staffId);
        }
        $jobs = $query->orderBy('h.completed_at', 'DESC')->get()->getResultArray();

        $totals = [
            'jobs' => 0, 'pending_jobs' => 0, 'approved_jobs' => 0,
            'submitted_value' => 0.0, 'approved_earnings' => 0.0,
            'paid' => 0.0, 'outstanding' => 0.0,
        ];
        $byCleaner = [];

        foreach ($jobs as &$job) {
            $charge = round((float) ($job['cleaning_charge'] ?? 0), 2);
            $paid = round((float) ($job['paid_amount'] ?? 0), 2);
            $approved = $job['status'] === 'verified';
            $job['balance'] = round($charge - $paid, 2);
            $totals['jobs']++;
            $totals['submitted_value'] += $charge;
            $totals['paid'] += $paid;
            if ($approved) {
                $totals['approved_jobs']++;
                $totals['approved_earnings'] += $charge;
                $totals['outstanding'] += max(0, $job['balance']);
            } else {
                $totals['pending_jobs']++;
            }

            $key = (string) ($job['assigned_staff_id'] ?? 'unassigned');
            if (!isset($byCleaner[$key])) {
                $byCleaner[$key] = [
                    'name' => $job['cleaner_name'] ?: 'Unassigned',
                    'jobs' => 0, 'pending' => 0, 'approved' => 0,
                    'submitted' => 0.0, 'earned' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0,
                ];
            }
            $byCleaner[$key]['jobs']++;
            $byCleaner[$key]['submitted'] += $charge;
            $byCleaner[$key]['paid'] += $paid;
            if ($approved) {
                $byCleaner[$key]['approved']++;
                $byCleaner[$key]['earned'] += $charge;
                $byCleaner[$key]['outstanding'] += max(0, $job['balance']);
            } else {
                $byCleaner[$key]['pending']++;
            }
        }
        unset($job);

        return view('cleaner_payments/earnings_dashboard', [
            'pageTitle' => 'Monthly Cleaner Earnings',
            'month' => $month,
            'isCleaner' => $isCleaner,
            'staffId' => $staffId,
            'staff' => $staff,
            'jobs' => $jobs,
            'totals' => $totals,
            'byCleaner' => array_values($byCleaner),
            'canVerify' => $canVerify,
        ]);
    }
}
