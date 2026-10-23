@extends('layouts/main')

@section('styles')
<style>
.hs-staff-page .hs-staff-heading { color: #294780; }
.hs-staff-page .hs-staff-accent { width: 42px; height: 3px; background: #e98d50; border-radius: 3px; margin-top: 10px; }
.hs-staff-page .hs-staff-primary { background: #294780; border-color: #294780; color: #fff; }
.hs-staff-page .hs-staff-primary:hover { background: #203968; border-color: #203968; color: #fff; }
.hs-staff-page .hs-staff-card { border: 1px solid #e9edf4; border-radius: 14px; box-shadow: 0 4px 18px rgba(32,57,104,.05); }
.hs-staff-page .hs-staff-stat { border: 1px solid #e9edf4; border-radius: 12px; background: #fff; padding: 18px 20px; height: 100%; }
.hs-staff-page .hs-staff-stat-label { font-size: .82rem; color: #69758a; font-weight: 600; }
.hs-staff-page .hs-staff-stat-value { font-size: 1.65rem; color: #294780; font-weight: 750; margin-top: 5px; }
.hs-staff-page .hs-staff-table thead th { background: #f2f5fa; color: #294780; font-size: .8rem; letter-spacing: .02em; font-weight: 700; border-bottom: 2px solid #e5eaf2; white-space: nowrap; }
.hs-staff-page .hs-staff-table td { padding-top: 14px; padding-bottom: 14px; }
.hs-staff-page .hs-staff-badge { display: inline-block; padding: 5px 10px; border-radius: 999px; font-size: .75rem; font-weight: 700; }
.hs-staff-page .hs-staff-badge-active { background: #e8f6ed; color: #17683a; }
.hs-staff-page .hs-staff-badge-inactive { background: #edf0f4; color: #596579; }
.hs-staff-page .hs-staff-edit { color: #294780; border-color: #b9c7df; }
.hs-staff-page .hs-staff-edit:hover { color: #fff; background: #294780; border-color: #294780; }
.hs-staff-page .hs-staff-search { max-width: 290px; }
@media(max-width:575px) { .hs-staff-page .hs-staff-search { max-width: none; width: 100%; } }
</style>
@endsection

@section('content')
<?php
$staffList = $staff ?? [];
$totalStaff = count($staffList);
$activeStaff = count(array_filter($staffList, static fn($member) => ($member['status'] ?? '') === 'active'));
$inactiveStaff = $totalStaff - $activeStaff;
$roleCount = count(array_unique(array_filter(array_map(static fn($member) => trim((string) ($member['role'] ?? '')), $staffList))));
?>
<div class="hs-staff-page">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h3 class="fw-bold mb-1 hs-staff-heading">Staff Management</h3>
            <p class="text-muted mb-0">Manage Haven Suites team members and assignments.</p>
            <div class="hs-staff-accent" aria-hidden="true"></div>
        </div>
        <a href="<?= site_url('staff/create') ?>" class="btn hs-staff-primary px-3 py-2">+ Add Staff Member</a>
    </div>

    <div class="row g-3 mb-4">
        <?php foreach ([['Total Team Members', $totalStaff], ['Active Staff', $activeStaff], ['Inactive Staff', $inactiveStaff], ['Roles Represented', $roleCount]] as [$label, $value]): ?>
            <div class="col-xl-3 col-md-6">
                <div class="hs-staff-stat">
                    <div class="hs-staff-stat-label"><?= esc($label) ?></div>
                    <div class="hs-staff-stat-value"><?= number_format($value) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card hs-staff-card">
        <div class="card-body p-3 p-md-4">
            <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3">
                <div>
                    <h5 class="fw-bold mb-1 hs-staff-heading">Team Directory</h5>
                    <p class="text-muted small mb-0">Contact information, roles and staff status.</p>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <input type="search" class="form-control hs-staff-search" id="staffSearch" placeholder="Search name, role, location..." aria-label="Search staff">
                    <select class="form-select" id="staffStatusFilter" aria-label="Filter staff by status" style="min-width:145px">
                        <option value="">All Statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle hs-staff-table">
                    <thead>
                        <tr><th>Full Name</th><th>Role</th><th>Location</th><th>Phone</th><th>Email</th><th>Status</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($staffList as $member): ?>
                            <?php $status = (string) ($member['status'] ?? 'inactive'); ?>
                            <tr class="hs-staff-row" data-status="<?= esc($status, 'attr') ?>">
                                <td class="fw-semibold"><?= esc($member['full_name'] ?? '') ?></td>
                                <td><?= esc($member['role'] ?? '') ?></td>
                                <td><?= esc($member['location'] ?? '') ?></td>
                                <td><?= esc($member['phone'] ?? '') ?></td>
                                <td><?= esc($member['email'] ?? '') ?></td>
                                <td><span class="hs-staff-badge <?= $status === 'active' ? 'hs-staff-badge-active' : 'hs-staff-badge-inactive' ?>"><?= esc(ucfirst($status)) ?></span></td>
                                <td><a href="<?= site_url('staff/edit/' . (int) $member['id']) ?>" class="btn btn-sm btn-outline-primary hs-staff-edit">Edit</a></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($staffList)): ?>
                            <tr><td colspan="7" class="text-center text-muted py-5">No staff members registered yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="small text-muted mt-3" id="staffCount">Showing <?= $totalStaff ?> staff member(s)</div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const search = document.getElementById('staffSearch');
    const status = document.getElementById('staffStatusFilter');
    const rows = document.querySelectorAll('.hs-staff-row');
    const count = document.getElementById('staffCount');
    function filterStaff() {
        const query = search.value.trim().toLowerCase();
        const selected = status.value;
        let visible = 0;
        rows.forEach(function (row) {
            const show = row.textContent.toLowerCase().includes(query) && (!selected || row.dataset.status === selected);
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        count.textContent = 'Showing ' + visible + ' of ' + rows.length + ' staff member(s)';
    }
    search.addEventListener('input', filterStaff);
    status.addEventListener('change', filterStaff);
});
</script>
@endsection
