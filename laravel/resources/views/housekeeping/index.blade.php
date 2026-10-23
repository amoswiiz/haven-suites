@extends('layouts/main')
@section('styles')
<style>
.hs-housekeeping .hs-hk-card { background:#fff; border:1px solid #e6ebf2; border-radius:14px; box-shadow:0 3px 12px rgba(28,45,78,.035); }
.hs-housekeeping .hs-hk-summary { padding:20px 22px; min-height:115px; }
.hs-housekeeping .hs-hk-label { font-size:12px; color:#75839b; margin-bottom:9px; }
.hs-housekeeping .hs-hk-value { font-size:26px; font-weight:750; color:#20304d; line-height:1.2; }
.hs-housekeeping .hs-hk-icon { display:grid; place-items:center; width:42px; height:42px; border-radius:12px; background:#eef3ff; color:#345b9f; }
.hs-housekeeping .hs-hk-icon svg { width:21px; height:21px; }
.hs-housekeeping .hs-hk-heading { color:#20304d; font-weight:700; }
.hs-housekeeping .hs-hk-table { min-width:950px; margin-bottom:0; }
.hs-housekeeping .hs-hk-table thead th { background:#f1f4f9; color:#2a426d; font-size:11px; text-transform:uppercase; letter-spacing:.045em; border-bottom:1px solid #dbe3ee; padding:15px 12px; white-space:nowrap; }
.hs-housekeeping .hs-hk-table tbody td { padding:15px 12px; border-color:#edf1f5; vertical-align:middle; }
.hs-housekeeping .hs-hk-table tbody tr:hover > td { background:#f9fbff; }
.hs-housekeeping .hs-hk-status { font-size:11px; font-weight:700; border-radius:30px; padding:7px 10px; display:inline-block; white-space:nowrap; }
.hs-housekeeping .hs-hk-status-pending { background:#fff0c6; color:#765000; }
.hs-housekeeping .hs-hk-status-assigned { background:#e8efff; color:#2753a3; }
.hs-housekeeping .hs-hk-status-in_progress { background:#e7efff; color:#244da4; }
.hs-housekeeping .hs-hk-status-completed { background:#dcf5e7; color:#176b42; }
.hs-housekeeping .hs-hk-status-verified { background:#d9f2e4; color:#12633d; }
.hs-housekeeping .hs-hk-status-cancelled { background:#e9edf2; color:#586575; }
.hs-housekeeping .hs-hk-status-unknown { background:#e9edf2; color:#586575; }
.hs-housekeeping .btn-primary { background:#30477e; border-color:#30477e; }
.hs-housekeeping .btn-primary:hover { background:#233967; border-color:#233967; }
.hs-housekeeping .btn-outline-primary { color:#30477e; border-color:#a9b8d3; }
.hs-housekeeping .btn-outline-primary:hover { background:#30477e; color:#fff; border-color:#30477e; }
.hs-housekeeping .form-label { font-size:13px; font-weight:600; color:#34445f; }
.hs-housekeeping .hs-hk-filter-title { font-size:15px; font-weight:700; color:#20304d; }
@media(max-width:767px){ .hs-housekeeping .hs-hk-summary { min-height:100px; padding:16px; } }
</style>
@endsection
@section('content')
<?php if (haven_service('request')->getGet('activity') === 'pending'): ?>
<div class="alert alert-info d-flex justify-content-between align-items-center gap-3">
    <span>Showing pending, assigned and in-progress cleanings.</span>
    <a href="<?= site_url('housekeeping') ?>">Show all cleanings</a>
</div>
<?php endif; ?>
<?php
$tasks = $tasks ?? [];
$isCleaner = (bool) ($isCleaner ?? false);
$filters = array_merge(['month' => '', 'property_id' => '', 'unit_id' => '', 'staff_id' => ''], $filters ?? []);
$properties = $properties ?? [];
$units = $units ?? [];
$staff = $staff ?? [];
$statuses = ['pending' => 'Pending', 'assigned' => 'Assigned', 'in_progress' => 'In Progress', 'completed' => 'Completed', 'verified' => 'Verified', 'cancelled' => 'Cancelled'];
$statusCounts = array_count_values(array_map(static fn ($task) => (string) ($task['status'] ?? 'pending'), $tasks));
$activeTasks = ($statusCounts['pending'] ?? 0) + ($statusCounts['assigned'] ?? 0) + ($statusCounts['in_progress'] ?? 0);
$completedTasks = ($statusCounts['completed'] ?? 0) + ($statusCounts['verified'] ?? 0);
$types = ['checkout' => 'Check-out', 'pre_checkin' => 'Pre check-in', 'routine' => 'Routine', 'deep' => 'Deep'];
?>
<div class="container-fluid py-3 hs-housekeeping">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h3 class="fw-bold mb-1">Housekeeping</h3>
            <p class="text-muted mb-0">Manage cleaning schedules, assignments and progress.</p>
        </div>
        <?php if (!$isCleaner && haven_auth()->user()?->can('housekeeping.manage')): ?>
            <a class="btn btn-primary px-3" href="<?= site_url('housekeeping/create') ?>">+ Schedule Cleaning</a>
        <?php endif; ?>
    </div>

    <?php if (haven_session()->getFlashdata('success')): ?>
        <div class="alert alert-success" role="alert"><?= esc(haven_session()->getFlashdata('success')) ?></div>
    <?php endif; ?>
    <?php if (haven_session()->getFlashdata('error')): ?>
        <div class="alert alert-danger" role="alert"><?= esc(haven_session()->getFlashdata('error')) ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <?php foreach ([
            ['Total Tasks', count($tasks), 'clipboard-list'],
            ['Pending', $statusCounts['pending'] ?? 0, 'clock-3'],
            ['Active Tasks', $activeTasks, 'list-checks'],
            ['Completed / Verified', $completedTasks, 'check-circle-2'],
        ] as [$label, $value, $icon]): ?>
            <div class="col-xl-3 col-md-6">
                <div class="hs-hk-card hs-hk-summary d-flex align-items-start justify-content-between gap-3">
                    <div><div class="hs-hk-label"><?= esc($label) ?></div><div class="hs-hk-value"><?= number_format($value) ?></div></div>
                    <span class="hs-hk-icon"><i data-lucide="<?= esc($icon, 'attr') ?>"></i></span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="hs-hk-card mb-4"><div class="card-body">
        <div class="hs-hk-filter-title mb-3">Filter Cleaning Tasks</div>
        <form method="get" action="<?= site_url('housekeeping') ?>">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label" for="taskMonth">Scheduled month</label>
                    <input class="form-control" id="taskMonth" type="month" name="month" value="<?= esc((string) $filters['month'], 'attr') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="taskProperty">Property</label>
                    <select class="form-select" id="taskProperty" name="property_id">
                        <option value="">All properties</option>
                        <?php foreach ($properties as $property): ?>
                            <option value="<?= (int) $property['id'] ?>" <?= (string) $filters['property_id'] === (string) $property['id'] ? 'selected' : '' ?>><?= esc($property['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="taskUnit">Apartment</label>
                    <select class="form-select" id="taskUnit" name="unit_id">
                        <option value="">All apartments</option>
                        <?php foreach ($units as $unit): ?>
                            <option value="<?= (int) $unit['id'] ?>" data-property="<?= (int) $unit['property_id'] ?>" <?= (string) $filters['unit_id'] === (string) $unit['id'] ? 'selected' : '' ?>><?= esc(($unit['property_name'] ?? '') . ' / ' . ($unit['unit_number'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="taskStaff">Assigned staff</label>
                    <select class="form-select" id="taskStaff" name="staff_id">
                        <option value="">All staff</option>
                        <?php foreach ($staff as $member): ?>
                            <option value="<?= (int) $member['id'] ?>" <?= (string) $filters['staff_id'] === (string) $member['id'] ? 'selected' : '' ?>><?= esc($member['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary" type="submit">Apply Filters</button>
                    <a class="btn btn-outline-secondary" href="<?= site_url('housekeeping') ?>">Reset</a>
                </div>
            </div>
        </form>
    </div></div>

    <div class="hs-hk-card"><div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <h5 class="hs-hk-heading mb-0">Cleaning Tasks</h5>
            <span class="text-muted"><?= count($tasks) ?> task(s)</span>
        </div>
        <div class="table-responsive">
            <table class="table hs-hk-table align-middle">
                <thead><tr>
                    <th>Scheduled</th><th>Apartment</th><th>Cleaning</th><th>Assigned to</th>
                    <th>Bedrooms</th><th>Status</th><th>Charge (KES)</th><th>Action</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($tasks as $task): ?>
                        <?php $status = (string) ($task['status'] ?? 'pending'); ?>
                        <tr>
                            <td><?= esc($task['scheduled_at'] ?? '-') ?></td>
                            <td><?= esc(($task['property_name'] ?? '-') . ' / ' . ($task['unit_number'] ?? '-')) ?></td>
                            <td><?= esc($types[$task['cleaning_type'] ?? ''] ?? ucwords(str_replace('_', ' ', (string) ($task['cleaning_type'] ?? '-')))) ?></td>
                            <td><?= esc($task['assigned_staff_name'] ?? 'Unassigned') ?></td>
                            <td><?= isset($task['bedrooms_cleaned']) && $task['bedrooms_cleaned'] !== null ? (int) $task['bedrooms_cleaned'] : '-' ?></td>
                            <td><span class="hs-hk-status hs-hk-status-<?= esc(array_key_exists($status, $statuses) ? $status : 'unknown', 'attr') ?>"><?= esc($statuses[$status] ?? ucfirst($status)) ?></span></td>
                            <td><?= $task['cleaning_charge'] === null ? '-' : number_format((float) $task['cleaning_charge'], 2) ?></td>
                            <td>
                                <?php if (!$isCleaner && haven_auth()->user()?->can('housekeeping.manage')): ?>
                                    <a class="btn btn-sm btn-outline-primary" href="<?= site_url('housekeeping/edit/' . (int) $task['id']) ?>">Edit</a>
                                <?php elseif ($isCleaner && in_array($status, ['pending', 'assigned', 'in_progress'], true)): ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#progress-<?= (int) $task['id'] ?>" aria-expanded="false">Update Progress</button>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if ($isCleaner && in_array($status, ['pending', 'assigned', 'in_progress'], true)): ?>
                            <tr><td colspan="8" class="p-0 border-0">
                                <div class="collapse p-3 border rounded mb-2" id="progress-<?= (int) $task['id'] ?>">
                                    <form method="post" action="<?= site_url('housekeeping/progress/' . (int) $task['id']) ?>">
                                        <?= csrf_field() ?>
                                        <div class="row g-3">
                                            <div class="col-md-3">
                                                <label class="form-label">Status</label>
                                                <select name="status" class="form-select" required>
                                                    <?php if ($status !== 'in_progress'): ?>
                                                        <option value="in_progress">In Progress</option>
                                                    <?php else: ?>
                                                        <option value="in_progress">In Progress</option>
                                                        <option value="completed">Completed</option>
                                                    <?php endif; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Bedrooms cleaned</label>
                                                <select name="bedrooms_cleaned" class="form-select" required>
                                                    <option value="">Select bedrooms</option>
                                                    <?php for ($i = 1; $i <= (int) ($task['unit_bedrooms'] ?? 0); $i++): ?>
                                                        <option value="<?= $i ?>" <?= (int) ($task['bedrooms_cleaned'] ?? 0) === $i ? 'selected' : '' ?>><?= $i ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Notes</label>
                                                <textarea class="form-control" name="notes" rows="2" maxlength="5000"><?= esc($task['notes'] ?? '') ?></textarea>
                                            </div>
                                            <div class="col-12"><button class="btn btn-primary" type="submit">Save Progress</button></div>
                                        </div>
                                    </form>
                                </div>
                            </td></tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (!$tasks): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">No housekeeping tasks match your filters.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const property = document.getElementById('taskProperty');
    const unit = document.getElementById('taskUnit');
    if (!property || !unit) return;
    function syncUnits() {
        const selected = property.value;
        for (const option of unit.options) {
            if (!option.value) continue;
            const matches = !selected || option.dataset.property === selected;
            option.hidden = !matches;
            option.disabled = !matches;
            if (!matches && option.selected) unit.value = '';
        }
    }
    property.addEventListener('change', syncUnits);
    syncUnits();
});
</script>
@endsection
