@extends('layouts/main')

@section('content')

<?php
$unitList = $units ?? [];

// Presentation only: preserve existing unit data and actions.

$totalUnits = count($unitList);

$activeUnits = count(array_filter(
    $unitList,
    fn($unit) => $unit['status'] === 'active'
));

$maintenanceUnits = count(array_filter(
    $unitList,
    fn($unit) => $unit['status'] === 'maintenance'
));

$inactiveUnits = count(array_filter(
    $unitList,
    fn($unit) => $unit['status'] === 'inactive'
));

// Support either property_name or name from the controller JOIN.
$getPropertyName = static function (array $unit): string {
    return (string) (
        $unit['property_name']
        ?? $unit['name']
        ?? 'Property #' . $unit['property_id']
    );
};

?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3 hs-page-heading">

    <div>
        <h3 class="fw-bold mb-1">Apartment Units</h3>

        <p class="text-muted mb-0">
            Manage all Haven Suites apartments in one place.
        </p>
    </div>

    <?php if (haven_can_access(site_url('units/create'), 'GET')): ?><?php if (haven_auth()->user()?->can('units.manage')): ?><a href="<?= site_url('units/create') ?>"
       class="btn hs-unit-primary-btn">
        <i data-lucide="plus" aria-hidden="true"></i> Add Apartment
    </a><?php endif; ?><?php endif; ?>

</div>

<!-- Statistics -->
<div class="row g-3 mb-4">

    <?php
    $stats = [
        ['Total Apartments', $totalUnits, 'building-2'],
        ['Active Apartments', $activeUnits, 'check-circle'],
        ['Under Maintenance', $maintenanceUnits, 'wrench'],
        ['Inactive Apartments', $inactiveUnits, 'pause-circle'],
    ];
    ?>

    <?php foreach ($stats as [$label, $count, $icon]): ?>

        <div class="col-xl-3 col-md-6">

            <div class="hs-stat-card">

                <div class="d-flex justify-content-between align-items-start">

                    <div>
                        <div class="hs-stat-label">
                            <?= esc($label) ?>
                        </div>

                        <div class="hs-stat-value">
                            <?= number_format($count) ?>
                        </div>
                    </div>

                    <div class="hs-stat-icon">
                        <i data-lucide="<?= esc($icon) ?>"></i>
                    </div>

                </div>

            </div>

        </div>

    <?php endforeach; ?>

</div>

<!-- Apartment directory -->
<div class="card border-0 shadow-sm hs-unit-directory">

    <div class="card-body">

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4 hs-unit-directory-header">

            <div>
                <h5 class="fw-bold mb-1">Apartment Directory</h5>

                <p class="text-muted small mb-0">
                    Browse and manage your apartment inventory.
                </p>
            </div>

            <div class="d-flex flex-wrap gap-2">

                <input
                    type="search"
                    id="unitSearch"
                    class="form-control"
                    style="min-width:200px"
                    placeholder="Search apartments..."
                    aria-label="Search apartments"
                >

                <select id="statusFilter"
                        class="form-select"
                        style="min-width:160px"
                        aria-label="Filter by status">

                    <option value="">All Statuses</option>
                    <option value="active">Active</option>
                    <option value="maintenance">Maintenance</option>
                    <option value="inactive">Inactive</option>

                </select>

            </div>

        </div>

        <div class="table-responsive">

            <table class="table table-hover align-middle hs-unit-table" id="unitsTable">

                <thead>
                    <tr>
                        <th>Apartment</th>
                        <th>Property</th>
                        <th>Bedrooms</th>
                        <th>Bathrooms</th>
                        <th>Floor</th>
                        <th>Nightly Rate</th>
                        <th>Owner</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($unitList as $unit): ?>

                        <?php
                        $status = $unit['status'];

                        $badgeClass = match ($status) {
                            'active' => 'bg-success',
                            'maintenance' => 'bg-warning text-dark',
                            default => 'bg-secondary'
                        };

                        $propertyName = $getPropertyName($unit);
                        ?>

                        <tr class="unit-row"
                            data-property="<?= esc($unit['property_id'], 'attr') ?>"
                            data-status="<?= esc($status, 'attr') ?>">

                            <td>
                                <div class="fw-semibold">
                                    <?= esc($propertyName . ' – ' . $unit['unit_number']) ?>
                                </div>

                                <?php if (!empty($unit['notes'])): ?>
                                    <div class="text-muted small">
                                        <?= esc(mb_strimwidth(
                                            $unit['notes'],
                                            0,
                                            65,
                                            '...'
                                        )) ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td><?= esc($propertyName) ?></td>

                            <td>
                                <?= esc($unit['bedrooms']) ?>
                            </td>

                            <td>
                                <?= esc($unit['bathrooms']) ?>
                            </td>

                            <td>
                                <?= esc($unit['floor_number'] ?? '-') ?>
                            </td>

                            <td class="fw-semibold">
                                KES <?= number_format(
                                    (float) $unit['nightly_rate'],
                                    0
                                ) ?>
                            </td>

                            <td>
                                <?= esc($unit['owner_name'] ?: '-') ?>

                                <?php if (!empty($unit['owner_phone'])): ?>
                                    <div class="small text-muted">
                                        <?= esc($unit['owner_phone']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="badge hs-unit-status <?= $badgeClass ?>">
                                    <?= esc(ucwords(str_replace(
                                        '_',
                                        ' ',
                                        $status
                                    ))) ?>
                                </span>
                            </td>

                            <td>
                                <?php if (haven_can_access(site_url('units/edit/' . $unit['id']), 'GET')): ?><?php if (haven_auth()->user()?->can('units.manage')): ?><a href="<?= site_url('units/edit/' . $unit['id']) ?>"
                                   class="btn btn-sm btn-outline-primary hs-unit-edit">
                                    Edit
                                </a><?php endif; ?><?php endif; ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                    <?php if (empty($unitList)): ?>

                        <tr>
                            <td colspan="9"
                                class="text-center text-muted py-5">

                                <p>No apartment units registered yet.</p>

                                <?php if (haven_can_access(site_url('units/create'), 'GET')): ?><?php if (haven_auth()->user()?->can('units.manage')): ?><a href="<?= site_url('units/create') ?>"
                                   class="btn btn-primary">
                                    Add Your First Apartment
                                </a><?php endif; ?><?php endif; ?>

                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

        <div class="d-flex justify-content-between align-items-center mt-3">

            <span class="text-muted small" id="unitCount">
                Showing <?= count($unitList) ?> apartments
            </span>

        </div>

    </div>

</div>

@endsection


@section('styles')
<style>
/* Apartment Units: local styling, no changes to shared pages */
.hs-page-heading h3 { color: #1d2f50; letter-spacing: -.025em; }
.hs-unit-primary-btn { display:inline-flex; align-items:center; gap:.5rem; background:#304a80; color:#fff; border:1px solid #304a80; font-weight:600; padding:.68rem 1rem; border-radius:.55rem; }
.hs-unit-primary-btn:hover, .hs-unit-primary-btn:focus { background:#243b69; color:#fff; border-color:#243b69; }
.hs-unit-primary-btn svg { width:17px; height:17px; }
.hs-unit-directory { border-radius:14px; overflow:hidden; }
.hs-unit-directory .card-body { padding:1.4rem; }
.hs-unit-directory-header h5 { color:#1d2f50; }
.hs-unit-directory .form-control, .hs-unit-directory .form-select { border-color:#e0e6ee; border-radius:7px; }
.hs-unit-directory .form-control:focus, .hs-unit-directory .form-select:focus { border-color:#304a80; box-shadow:0 0 0 .2rem rgba(48,74,128,.12); }
.hs-unit-table { margin-bottom:0; min-width:940px; }
.hs-unit-table thead th { background:#f0f3f8; color:#283f68; font-size:.74rem; letter-spacing:.035em; text-transform:uppercase; font-weight:700; padding:1rem .8rem; border-bottom:1px solid #dce3ee; white-space:nowrap; }
.hs-unit-table tbody td { padding:.92rem .8rem; border-color:#e9edf3; vertical-align:middle; }
.hs-unit-table tbody tr:hover td { background:#f8faff; }
.hs-unit-status { padding:.43rem .65rem; border-radius:20px; font-weight:600; }
.hs-unit-edit { border-color:#304a80; color:#304a80; border-radius:6px; }
.hs-unit-edit:hover { background:#304a80; border-color:#304a80; color:white; }
@media(max-width:767px) { .hs-unit-directory .card-body { padding:1rem; } .hs-unit-directory-header > div:last-child { width:100%; } .hs-unit-directory-header input, .hs-unit-directory-header select { flex:1 1 100%; width:100%; } }
</style>
@endsection

@section('scripts')

<script>
document.addEventListener('DOMContentLoaded', function () {

    const searchInput = document.getElementById('unitSearch');
    const statusFilter = document.getElementById('statusFilter');
    const rows = document.querySelectorAll('.unit-row');
    const unitCount = document.getElementById('unitCount');

    function filterUnits() {

        const search = searchInput.value.trim().toLowerCase();
        const status = statusFilter.value;

        let visible = 0;

        rows.forEach(function (row) {

            const matchesSearch =
                row.textContent.toLowerCase().includes(search);

            const matchesStatus =
                !status || row.dataset.status === status;

            const show =
                matchesSearch && matchesStatus;

            row.style.display = show ? '' : 'none';

            if (show) {
                visible++;
            }

        });

        unitCount.textContent =
            'Showing ' + visible + ' of ' +
            rows.length + ' apartments';
    }

    searchInput.addEventListener('input', filterUnits);
    statusFilter.addEventListener('change', filterUnits);

});
</script>

@endsection
