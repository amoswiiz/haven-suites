<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CleanerDashboardSummary
{
    private function cents(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }

    public function summary(): array
    {
        $user = auth()->user();
        abort_unless($user?->active && $user->inGroup('cleaner') && $user->can('cleaner.dashboard'), 403);
        $company = app('currentTenant');
        $today = CarbonImmutable::now($company->timezone ?: config('app.timezone', 'UTC'))->startOfDay();
        $db = DB::connection('tenant');
        $schema = $db->getSchemaBuilder();
        $legacyId = haven_auth()->id();
        $staffMatches = $legacyId ? $db->table('staff')->where('user_id', $legacyId)->where('status', 'active')->limit(2)->get(['id', 'full_name']) : collect();
        $staff = $staffMatches->count() === 1 ? $staffMatches->first() : null;
        $data = [
            'today' => $today, 'currency' => $company->currency ?: 'KES', 'staff' => $staff,
            'staffLinkIssue' => $staffMatches->count() > 1 ? 'multiple' : ($staff ? null : 'missing'),
            'cleaningEnabled' => $user->can('housekeeping.view'),
            'billsEnabled' => $user->can('bills.submit') && $schema->hasTable('staff_bills'),
            'maintenanceEnabled' => $user->can('maintenance.view') && $schema->hasColumn('maintenance', 'reported_by_user_id'),
            'tasksEnabled' => $user->can('tasks.view'), 'profileEnabled' => $user->can('staff-profile.self'),
            'profileComplete' => false,
            'cleaning' => ['today' => 0, 'pending' => 0, 'overdue' => 0, 'awaitingVerification' => 0, 'disputed' => 0, 'items' => collect()],
            'earnings' => ['verifiedJobs' => 0, 'earnedMinor' => 0, 'paidMinor' => 0, 'outstandingMinor' => 0],
            'bills' => ['pending' => 0, 'returned' => 0, 'reimbursementMinor' => 0, 'items' => collect()],
            'maintenance' => ['open' => 0, 'items' => collect()],
            'tasks' => ['due' => 0, 'items' => collect()],
        ];
        if ($data['profileEnabled']) {
            $data['profileComplete'] = DB::table('staff_profiles')->where('tenant_id', $company->id)->where('user_id', $user->id)
                ->whereNotNull('document_path')->exists();
        }
        if ($staff && $data['cleaningEnabled']) {
            $jobs = $db->table('housekeeping as h')->where('h.assigned_staff_id', $staff->id);
            $pending = (clone $jobs)->whereIn('h.status', ['pending', 'assigned', 'in_progress']);
            $data['cleaning'] = [
                'today' => (clone $jobs)->where('h.scheduled_at', '>=', $today->toDateTimeString())
                    ->where('h.scheduled_at', '<', $today->addDay()->toDateTimeString())->where('h.status', '<>', 'cancelled')->count(),
                'pending' => (clone $pending)->count(),
                'overdue' => (clone $pending)->where('h.scheduled_at', '<', $today->toDateTimeString())->count(),
                'awaitingVerification' => (clone $jobs)->where('h.status', 'completed')->count(),
                'disputed' => (clone $jobs)->where('h.status', 'disputed')->count(),
                'items' => (clone $pending)->leftJoin('units as u', 'u.id', '=', 'h.unit_id')
                    ->leftJoin('properties as p', 'p.id', '=', 'u.property_id')
                    ->where('h.scheduled_at', '<', $today->addDay()->toDateTimeString())
                    ->orderBy('h.scheduled_at')->orderBy('h.id')->limit(5)
                    ->get(['h.id', 'h.status', 'h.scheduled_at', 'u.unit_number', 'p.name as property_name']),
            ];
            $allocations = $db->table('cleaner_payment_allocations')->selectRaw('housekeeping_id, SUM(amount) as paid')->groupBy('housekeeping_id');
            $verified = (clone $jobs)->leftJoinSub($allocations, 'a', 'a.housekeeping_id', '=', 'h.id')
                ->where('h.status', 'verified')->where('h.completed_at', '>=', $today->startOfMonth()->toDateTimeString())
                ->where('h.completed_at', '<', $today->startOfMonth()->addMonth()->toDateTimeString())
                ->get(['h.cleaning_charge', DB::raw('COALESCE(a.paid, 0) as paid')]);
            foreach ($verified as $job) {
                $charge = $this->cents($job->cleaning_charge);
                $paid = $this->cents($job->paid);
                $data['earnings']['verifiedJobs']++;
                $data['earnings']['earnedMinor'] += $charge;
                $data['earnings']['paidMinor'] += $paid;
                $data['earnings']['outstandingMinor'] += max(0, $charge - $paid);
            }
        }
        if ($data['billsEnabled']) {
            $bills = $db->table('staff_bills as b')->where('b.submitted_by', $user->id);
            $data['bills']['pending'] = (clone $bills)->where('b.status', 'pending')->count();
            $data['bills']['returned'] = (clone $bills)->where('b.status', 'returned')->count();
            $approved = (clone $bills)->leftJoin('unit_expenses as e', 'e.id', '=', 'b.expense_id')
                ->where('b.status', 'approved')->where('b.payer', 'personal')->get(['b.amount_minor', 'e.amount_paid']);
            foreach ($approved as $bill) {
                $data['bills']['reimbursementMinor'] += max(0, (int) $bill->amount_minor - $this->cents($bill->amount_paid));
            }
            $data['bills']['items'] = (clone $bills)->orderByDesc('b.id')->limit(5)->get(['b.id', 'b.description', 'b.status']);
        }
        if ($data['maintenanceEnabled']) {
            $reports = $db->table('maintenance')->where('reported_by_user_id', $user->id);
            $data['maintenance']['open'] = (clone $reports)->whereIn('status', ['open', 'assigned', 'in_progress', 'on_hold'])->count();
            $data['maintenance']['items'] = (clone $reports)->orderByDesc('id')->limit(5)->get(['id', 'title', 'status']);
        }
        if ($data['tasksEnabled']) {
            $tasks = app(TaskAccess::class)->scope($user)
                ->where(fn ($query) => $query->where('assigned_to', $user->id)->orWhere('reviewer_id', $user->id))
                ->whereNotIn('status', ['done', 'cancelled'])->where('due_date', '<=', $today->toDateString());
            $data['tasks']['due'] = (clone $tasks)->count();
            $data['tasks']['items'] = (clone $tasks)->orderBy('due_date')->orderBy('id')->limit(5)->get(['id', 'title', 'due_date', 'status']);
        }

        return $data;
    }
}
