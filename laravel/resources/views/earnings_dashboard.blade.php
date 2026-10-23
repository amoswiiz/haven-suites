@extends('layouts/main')

@section('styles')
<style>
.hs-earnings{--hs-navy:#294780;--hs-orange:#e98d50}
.hs-earnings .hs-card{background:#fff;border:1px solid #e5eaf2;border-radius:14px;box-shadow:0 3px 12px rgba(25,48,90,.04)}
.hs-earnings .hs-stat{padding:20px;min-height:120px}
.hs-earnings .hs-label{font-size:12px;color:#71809a;margin-bottom:10px}
.hs-earnings .hs-value{font-size:25px;font-weight:750;color:#233c6b;overflow-wrap:anywhere}
.hs-earnings .hs-heading{color:#233c6b;font-weight:700}
.hs-earnings .hs-btn{background:var(--hs-navy);border-color:var(--hs-navy);color:white}
.hs-earnings .hs-btn:hover{background:#1f3869;color:white}
.hs-earnings .hs-table{min-width:860px;margin-bottom:0}
.hs-earnings .hs-table th{background:#f1f4f9;color:#344a71;font-size:12px;white-space:nowrap}
.hs-earnings .hs-table td,.hs-earnings .hs-table th{padding:13px;vertical-align:middle}
.hs-earnings .hs-pill{border-radius:30px;padding:5px 10px;font-size:12px;font-weight:650;white-space:nowrap}
.hs-earnings .hs-approved{background:#e0f3e7;color:#17663e}
.hs-earnings .hs-pending{background:#fff0d2;color:#845600}
</style>
@endsection

@section('content')
<?php
$month = (string) ($month ?? date('Y-m'));
$isCleaner = (bool) ($isCleaner ?? false);
$staffId = $staffId ?? null;
$staff = $staff ?? [];
$jobs = $jobs ?? [];
$byCleaner = $byCleaner ?? [];
$totals = array_merge([
    'jobs' => 0, 'pending_jobs' => 0, 'approved_jobs' => 0,
    'submitted_value' => 0, 'approved_earnings' => 0,
    'paid' => 0, 'outstanding' => 0,
], $totals ?? []);
$money = static fn ($value) => 'KES ' . number_format((float) $value, 2);
$monthLabel = DateTimeImmutable::createFromFormat('!Y-m', $month);
?>
<div class="container-fluid py-3 hs-earnings">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h3 class="hs-heading mb-1">Monthly Cleaner Earnings</h3>
            <p class="text-muted mb-0"><?= esc($monthLabel ? $monthLabel->format('F Y') : $month) ?> · Cleaning jobs and payment allocations</p>
        </div>
        <a class="btn btn-outline-secondary" href="<?= site_url('housekeeping/my-cleanings/new') ?>">+ Record Cleaning</a>
    </div>

    <?php if ($message = haven_session()->getFlashdata('error')): ?>
        <div class="alert alert-danger" role="alert"><?= esc($message) ?></div>
    <?php endif; ?>

    <div class="hs-card p-3 p-md-4 mb-4">
        <form method="get" action="<?= site_url('payments/cleaners/earnings') ?>" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="earningsMonth" class="form-label fw-semibold">Cleaning month</label>
                <input id="earningsMonth" name="month" type="month" class="form-control" required value="<?= esc($month, 'attr') ?>">
            </div>
            <?php if (!$isCleaner): ?>
                <div class="col-md-5">
                    <label for="earningsStaff" class="form-label fw-semibold">Cleaner</label>
                    <select id="earningsStaff" name="staff_id" class="form-select">
                        <option value="">All cleaners</option>
                        <?php foreach ($staff as $member): ?>
                            <option value="<?= (int) $member['id'] ?>" <?= (string) $staffId === (string) $member['id'] ? 'selected' : '' ?>><?= esc($member['full_name'] ?? 'Staff') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="col-md-3"><button type="submit" class="btn hs-btn w-100">Apply filters</button></div>
        </form>
    </div>

    <div class="row g-3 mb-4">
        <?php foreach ([
            ['Cleaning jobs', number_format((int) $totals['jobs'])],
            ['Awaiting verification', number_format((int) $totals['pending_jobs'])],
            ['Approved earnings', $money($totals['approved_earnings'])],
            ['Payments allocated', $money($totals['paid'])],
            ['Approved outstanding', $money($totals['outstanding'])],
        ] as [$label, $value]): ?>
            <div class="col-xl col-md-4 col-sm-6">
                <div class="hs-card hs-stat">
                    <div class="hs-label"><?= esc($label) ?></div>
                    <div class="hs-value"><?= esc($value) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <p class="text-muted small mb-4">Earnings are grouped by the month a cleaning was completed. Pending verification is not included in approved earnings. Payments shown are allocations against these cleaning jobs, which may have been made in another month. For a payment-date report, use Cleaner Payments or Statements.</p>

    <?php if (!$isCleaner && $byCleaner): ?>
        <div class="hs-card mb-4 overflow-hidden">
            <div class="p-3 border-bottom"><h5 class="hs-heading mb-0">Earnings by cleaner</h5></div>
            <div class="table-responsive">
                <table class="table hs-table">
                    <thead><tr><th>Cleaner</th><th class="text-end">Jobs</th><th class="text-end">Pending</th><th class="text-end">Approved</th><th class="text-end">Earned</th><th class="text-end">Paid</th><th class="text-end">Outstanding</th></tr></thead>
                    <tbody>
                    <?php foreach ($byCleaner as $person): ?>
                        <tr>
                            <td class="fw-semibold"><?= esc($person['name'] ?? 'Unassigned') ?></td>
                            <td class="text-end"><?= number_format((int) ($person['jobs'] ?? 0)) ?></td>
                            <td class="text-end"><?= number_format((int) ($person['pending'] ?? 0)) ?></td>
                            <td class="text-end"><?= number_format((int) ($person['approved'] ?? 0)) ?></td>
                            <td class="text-end"><?= esc($money($person['earned'] ?? 0)) ?></td>
                            <td class="text-end"><?= esc($money($person['paid'] ?? 0)) ?></td>
                            <td class="text-end fw-semibold"><?= esc($money($person['outstanding'] ?? 0)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <div class="hs-card overflow-hidden">
        <div class="p-3 border-bottom"><h5 class="hs-heading mb-0">Cleaning job details</h5></div>
        <div class="table-responsive">
            <table class="table hs-table">
                <thead><tr><th>Completed</th><?php if (!$isCleaner): ?><th>Cleaner</th><?php endif; ?><th>Apartment</th><th>Type</th><th class="text-end">Bedrooms</th><th>Status</th><th class="text-end">Fee</th><th class="text-end">Paid</th><th class="text-end">Balance</th></tr></thead>
                <tbody>
                    <?php foreach ($jobs as $job): ?>
                        <?php $approved = ($job['status'] ?? '') === 'verified'; ?>
                        <tr>
                            <td><?= esc(!empty($job['completed_at']) ? date('d M Y', strtotime($job['completed_at'])) : '—') ?></td>
                            <?php if (!$isCleaner): ?><td><?= esc($job['cleaner_name'] ?? 'Unassigned') ?></td><?php endif; ?>
                            <td><span class="fw-semibold"><?= esc($job['unit_number'] ?? '—') ?></span><div class="text-muted small"><?= esc($job['property_name'] ?? '') ?></div></td>
                            <td><?= esc(ucwords(str_replace('_', ' ', (string) ($job['cleaning_type'] ?? '')))) ?></td>
                            <td class="text-end"><?= esc((string) ($job['bedrooms_cleaned'] ?? '—')) ?></td>
                            <td><span class="hs-pill <?= $approved ? 'hs-approved' : 'hs-pending' ?>"><?= $approved ? 'Verified' : 'Awaiting verification' ?></span></td>
                            <td class="text-end"><?= esc($money($job['cleaning_charge'] ?? 0)) ?></td>
                            <td class="text-end"><?= esc($money($job['paid_amount'] ?? 0)) ?></td>
                            <td class="text-end fw-semibold"><?= esc($approved ? $money(max(0, (float) ($job['balance'] ?? 0))) : '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$jobs): ?>
                        <tr><td colspan="<?= $isCleaner ? 8 : 9 ?>" class="text-center text-muted py-5">No completed or verified cleaning jobs found for this selection.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
