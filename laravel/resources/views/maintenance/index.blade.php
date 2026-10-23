@extends('layouts/main')

@section('content')
<?php if (haven_service('request')->getGet('activity') === 'open'): ?>
<div class="alert alert-info d-flex justify-content-between align-items-center gap-3">
    <span>Showing unresolved maintenance across all dates: open, assigned, in progress or on hold.</span>
    <a href="<?= site_url('maintenance') ?>">Show all maintenance</a>
</div>
<?php endif; ?>

<?php
$issues = $maintenance ?? [];

$totalIssues = count($issues);

$openIssues = count(array_filter(
    $issues,
    fn($issue) => in_array(
        $issue['status'],
        ['open', 'assigned', 'in_progress', 'on_hold'],
        true
    )
));

$urgentIssues = count(array_filter(
    $issues,
    fn($issue) =>
        $issue['priority'] === 'urgent' &&
        !in_array(
            $issue['status'],
            ['completed', 'cancelled'],
            true
        )
));

$completedIssues = count(array_filter(
    $issues,
    fn($issue) => $issue['status'] === 'completed'
));

$totalCost = array_sum(array_map(
    fn($issue) => (float) $issue['cost'],
    $issues
));

$statusLabels = [
    'open' => 'Open',
    'assigned' => 'Assigned',
    'in_progress' => 'In Progress',
    'on_hold' => 'On Hold',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled'
];

$priorityLabels = [
    'low' => 'Low',
    'medium' => 'Medium',
    'high' => 'High',
    'urgent' => 'Urgent'
];
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4 hs-maintenance-heading">

    <div>
        <h3 class="fw-bold mb-1">Maintenance Management</h3>

        <p class="text-muted mb-0">
            Track repairs, assign responsibilities and monitor maintenance costs.
        </p>
    </div>

    <a href="<?= site_url('maintenance/create') ?>"
       class="btn btn-primary hs-maintenance-primary">
        + Report Maintenance Issue
    </a>

</div>

<style>
/* Haven Suites maintenance page: scoped to this module */
.hs-maintenance-stat { border: 1px solid #e7ebf3; border-radius: 14px; background: white; padding: 22px 24px; min-height: 120px; }
.hs-maintenance-stat .hs-stat-label { color: #73809a; font-size: 13px; margin-bottom: 12px; }
.hs-maintenance-stat .hs-stat-value { color: #20375f; font-weight: 700; }
.hs-maintenance-stat .hs-stat-icon { display: flex; align-items: center; justify-content: center; width: 44px; height: 44px; background: #eef3fc; color: #3567e8; border-radius: 12px; }

.hs-maintenance-heading h3, .hs-maintenance-directory h5 { color: #20375f; }
.hs-maintenance-primary { background: #304a82; border-color: #304a82; font-weight: 600; }
.hs-maintenance-primary:hover { background: #20375f; border-color: #20375f; }
.hs-maintenance-directory { border-radius: 14px; overflow: hidden; }
.hs-maintenance-directory .card-body { padding: 24px; }
.hs-maintenance-table { min-width: 1120px; }
.hs-maintenance-table thead th { background: #f0f3f8; color: #20375f; font-size: 11px; letter-spacing: .045em; text-transform: uppercase; padding: 15px 12px; border-bottom: 1px solid #d8e0ed; white-space: nowrap; }
.hs-maintenance-table tbody td { padding: 15px 12px; border-color: #e9edf4; font-size: 13px; }
.hs-maintenance-table .badge { padding: 7px 10px; border-radius: 30px; font-weight: 600; }
.hs-maintenance-directory .btn-outline-primary { color: #304a82; border-color: #a8b8d4; }
.hs-maintenance-directory .btn-outline-primary:hover { background: #304a82; border-color: #304a82; color: white; }
.hs-maintenance-directory .form-control:focus, .hs-maintenance-directory .form-select:focus { border-color: #304a82; box-shadow: 0 0 0 .2rem rgba(48,74,130,.12); }
.hs-maintenance-directory .card-body > .d-flex { gap: 16px; }
@media (max-width: 767px) { .hs-maintenance-directory .card-body { padding: 16px; } .hs-maintenance-directory input, .hs-maintenance-directory select { max-width: 100%; } }
</style>

<!-- Summary Cards -->
<div class="row g-3 mb-4">

    <?php
    $stats = [
        ['Total Issues', $totalIssues, 'clipboard-list'],
        ['Open Issues', $openIssues, 'wrench'],
        ['Urgent Issues', $urgentIssues, 'alert-triangle'],
        ['Total Recorded Cost', 'KES ' . number_format($totalCost, 0), 'wallet']
    ];
    ?>

    <?php foreach ($stats as [$label, $value, $icon]): ?>

        <div class="col-xl-3 col-md-6">

            <div class="hs-stat-card hs-maintenance-stat">

                <div class="d-flex justify-content-between align-items-start">

                    <div>
                        <div class="hs-stat-label">
                            <?= esc($label) ?>
                        </div>

                        <div class="hs-stat-value"
                             style="font-size:<?= $label === 'Total Recorded Cost'
                                 ? '24px'
                                 : '30px' ?>">
                            <?= esc($value) ?>
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

<!-- Maintenance Directory -->
<div class="card border-0 shadow-sm hs-maintenance-directory">

    <div class="card-body">

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">

            <div>
                <h5 class="fw-bold mb-1">Maintenance Issues</h5>

                <p class="text-muted small mb-0">
                    <?= number_format($completedIssues) ?>
                    completed issues recorded.
                </p>
            </div>

            <div class="d-flex flex-wrap gap-2">

                <input type="search"
                       id="maintenanceSearch"
                       class="form-control"
                       style="min-width:200px"
                       placeholder="Search maintenance..."
                       aria-label="Search maintenance issues">

                <select id="priorityFilter"
                        class="form-select"
                        style="min-width:140px"
                        aria-label="Filter by priority">

                    <option value="">All Priorities</option>

                    <?php foreach ($priorityLabels as $key => $label): ?>
                        <option value="<?= esc($key) ?>">
                            <?= esc($label) ?>
                        </option>
                    <?php endforeach; ?>

                </select>

                <select id="statusFilter"
                        class="form-select"
                        style="min-width:150px"
                        aria-label="Filter by status">

                    <option value="">All Statuses</option>

                    <?php foreach ($statusLabels as $key => $label): ?>
                        <option value="<?= esc($key) ?>">
                            <?= esc($label) ?>
                        </option>
                    <?php endforeach; ?>

                </select>

            </div>

        </div>

        <div class="table-responsive">

            <table class="table table-hover align-middle hs-maintenance-table">

                <thead>
                    <tr>
                        <th>Issue</th>
                        <th>Apartment</th>
                        <th>Category</th>
                        <th>Priority</th>
                        <th>Assigned Staff</th>
                        <th>External Provider</th>
                        <th>Status</th>
                        <th>Cost</th>
                        <th>Reported</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($issues as $issue): ?>

                        <?php
                        $priority = $issue['priority'];
                        $status = $issue['status'];

                        $priorityClass = match ($priority) {
                            'urgent' => 'bg-danger',
                            'high' => 'bg-warning text-dark',
                            'medium' => 'bg-info text-dark',
                            default => 'bg-secondary'
                        };

                        $statusClass = match ($status) {
                            'completed' => 'bg-success',
                            'in_progress' => 'bg-primary',
                            'assigned' => 'bg-info text-dark',
                            'on_hold' => 'bg-warning text-dark',
                            'cancelled' => 'bg-secondary',
                            default => 'bg-danger'
                        };
                        ?>

                        <tr class="maintenance-row"
                            data-priority="<?= esc($priority, 'attr') ?>"
                            data-status="<?= esc($status, 'attr') ?>">

                            <td>
                                <div class="fw-semibold">
                                    <?= esc($issue['title']) ?>
                                </div>

                                <div class="small text-muted">
                                    Issue #<?= esc($issue['id']) ?>
                                </div>

                                <?php if (!empty($issue['description'])): ?>
                                    <div class="small text-muted">
                                        <?= esc(mb_strimwidth(
                                            $issue['description'],
                                            0,
                                            65,
                                            '...'
                                        )) ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="fw-semibold">
                                    <?= esc($issue['unit_number'] ?? '-') ?>
                                </div>

                                <div class="small text-muted">
                                    <?= esc($issue['property_name'] ?? '-') ?>
                                </div>
                            </td>

                            <td>
                                <?= esc(
                                    ucwords(str_replace(
                                        '_',
                                        ' ',
                                        $issue['category'] ?: 'Other'
                                    ))
                                ) ?>
                            </td>

                            <td>
                                <span class="badge <?= $priorityClass ?>">
                                    <?= esc(
                                        $priorityLabels[$priority]
                                        ?? ucfirst($priority)
                                    ) ?>
                                </span>
                            </td>

                            <td>
                                <?php if (!empty($issue['assigned_staff_name'])): ?>

                                    <div class="fw-semibold">
                                        <?= esc($issue['assigned_staff_name']) ?>
                                    </div>

                                <?php else: ?>

                                    <span class="text-muted">
                                        Unassigned
                                    </span>

                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if (!empty($issue['provider_name'])): ?>

                                    <div>
                                        <?= esc($issue['provider_name']) ?>
                                    </div>

                                    <?php if (!empty($issue['provider_phone'])): ?>
                                        <div class="small text-muted">
                                            <?= esc($issue['provider_phone']) ?>
                                        </div>
                                    <?php endif; ?>

                                <?php else: ?>

                                    <span class="text-muted">-</span>

                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="badge <?= $statusClass ?>">
                                    <?= esc(
                                        $statusLabels[$status]
                                        ?? ucfirst($status)
                                    ) ?>
                                </span>
                            </td>

                            <td class="fw-semibold">
                                KES <?= number_format(
                                    (float) $issue['cost'],
                                    0
                                ) ?>
                            </td>

                            <td>
                                <?= !empty($issue['reported_at'])
                                    ? esc(date(
                                        'd M Y',
                                        strtotime($issue['reported_at'])
                                    ))
                                    : '-' ?>
                            </td>

                            <td>
                                <a href="<?= site_url(
                                    'maintenance/edit/' . $issue['id']
                                ) ?>"
                                   class="btn btn-sm btn-outline-primary">
                                    Edit
                                </a>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                    <?php if (empty($issues)): ?>
                        <tr>
                            <td colspan="10"
                                class="text-center text-muted py-5">

                                <p>No maintenance issues recorded yet.</p>

                                <a href="<?= site_url('maintenance/create') ?>"
                                   class="btn btn-primary">
                                    Report First Issue
                                </a>

                            </td>
                        </tr>
                    <?php endif; ?>

                </tbody>

            </table>

        </div>

        <div class="d-flex justify-content-between align-items-center mt-3">

            <span class="text-muted small" id="maintenanceCount">
                Showing <?= $totalIssues ?> issues
            </span>

            <a href="<?= site_url('staff') ?>"
               class="small text-decoration-none">
                Manage Staff &rarr;
            </a>

        </div>

    </div>

</div>

@endsection


@section('scripts')

<script>
document.addEventListener('DOMContentLoaded', function () {

    const search = document.getElementById('maintenanceSearch');
    const priority = document.getElementById('priorityFilter');
    const status = document.getElementById('statusFilter');
    const rows = document.querySelectorAll('.maintenance-row');
    const count = document.getElementById('maintenanceCount');

    function filterIssues() {

        const query = search.value.trim().toLowerCase();
        const selectedPriority = priority.value;
        const selectedStatus = status.value;

        let visible = 0;

        rows.forEach(function (row) {

            const matchesSearch =
                row.textContent.toLowerCase().includes(query);

            const matchesPriority =
                !selectedPriority ||
                row.dataset.priority === selectedPriority;

            const matchesStatus =
                !selectedStatus ||
                row.dataset.status === selectedStatus;

            const show =
                matchesSearch &&
                matchesPriority &&
                matchesStatus;

            row.style.display = show ? '' : 'none';

            if (show) {
                visible++;
            }

        });

        count.textContent =
            'Showing ' + visible + ' of ' +
            rows.length + ' issues';
    }

    search.addEventListener('input', filterIssues);
    priority.addEventListener('change', filterIssues);
    status.addEventListener('change', filterIssues);

});
</script>

@endsection
