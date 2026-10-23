@extends('layouts/main')
@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
        <div>
            <h3 class="fw-bold mb-1">Cleaner Outstanding Balances</h3>
            <p class="text-muted mb-0">Completed cleaning charges less payments allocated to those tasks.</p>
        </div>
        <a class="btn btn-outline-primary" href="<?= site_url('payments/cleaners') ?>">Payment History</a>
    </div>

    <?php if (session('error')): ?>
        <div class="alert alert-danger"><?= esc(session('error')) ?></div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm mb-4"><div class="card-body">
        <form method="get" action="<?= site_url('payments/cleaners/balances') ?>" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="balanceMonth">Completion month</label>
                <input id="balanceMonth" type="month" name="month" class="form-control" value="<?= esc($month, 'attr') ?>">
                <div class="form-text">Leave blank to include all completed tasks.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="balanceStaff">Cleaner</label>
                <select id="balanceStaff" name="staff_id" class="form-select">
                    <option value="">All staff</option>
                    <?php foreach ($staff as $member): ?>
                        <option value="<?= (int) $member['id'] ?>" <?= $staffId === (int) $member['id'] ? 'selected' : '' ?>><?= esc($member['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Apply Filters</button>
                <a href="<?= site_url('payments/cleaners/balances') ?>" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div></div>

    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted">Cleaning Charges</div><h4 class="fw-bold mb-0">KES <?= number_format($totals['charges'], 2) ?></h4></div></div></div>
        <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted">Allocated Payments</div><h4 class="fw-bold mb-0">KES <?= number_format($totals['paid'], 2) ?></h4></div></div></div>
        <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted">Outstanding</div><h4 class="fw-bold mb-0">KES <?= number_format($totals['outstanding'], 2) ?></h4></div></div></div>
    </div>

    <div class="card border-0 shadow-sm mb-4"><div class="card-body">
        <h5 class="fw-bold mb-3">Balances by Cleaner</h5>
        <div class="table-responsive"><table class="table align-middle">
            <thead><tr><th>Cleaner</th><th>Tasks</th><th class="text-end">Charges (KES)</th><th class="text-end">Paid (KES)</th><th class="text-end">Outstanding (KES)</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($byCleaner as $item): ?>
                <tr>
                    <td><?= esc($item['name']) ?></td><td><?= (int) $item['tasks'] ?></td>
                    <td class="text-end"><?= number_format($item['charges'], 2) ?></td>
                    <td class="text-end"><?= number_format($item['paid'], 2) ?></td>
                    <td class="text-end fw-semibold"><?= number_format($item['outstanding'], 2) ?></td>
                    <td class="text-end">
                        <?php if ($item['staff_id'] !== null && $item['outstanding'] > 0): ?>
                            <a class="btn btn-sm btn-outline-primary" href="<?= site_url('payments/cleaners/create') . '?staff_id=' . (int) $item['staff_id'] ?>">Record Payment</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($byCleaner)): ?><tr><td colspan="6" class="text-center text-muted py-4">No eligible completed tasks match these filters.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div></div>

    <div class="card border-0 shadow-sm"><div class="card-body">
        <h5 class="fw-bold mb-3">Tasks Awaiting Full Payment</h5>
        <div class="table-responsive"><table class="table table-striped align-middle">
            <thead><tr><th>Completed</th><th>Cleaner</th><th>Property / Apartment</th><th>Type</th><th class="text-end">Charge (KES)</th><th class="text-end">Paid (KES)</th><th class="text-end">Balance (KES)</th></tr></thead>
            <tbody>
            <?php foreach ($outstandingTasks as $task): ?>
                <tr>
                    <td><?= esc($task['completed_at'] ?? '-') ?></td>
                    <td><?= esc($task['cleaner_name'] ?? 'Unassigned') ?></td>
                    <td><?= esc(($task['property_name'] ?: 'Unknown Property') . ' / ' . ($task['unit_number'] ?: 'Unknown Unit')) ?></td>
                    <td><?= esc(ucwords(str_replace('_', ' ', $task['cleaning_type'] ?? ''))) ?></td>
                    <td class="text-end"><?= number_format((float) $task['cleaning_charge'], 2) ?></td>
                    <td class="text-end"><?= number_format((float) $task['paid_amount'], 2) ?></td>
                    <td class="text-end fw-semibold"><?= number_format($task['balance'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($outstandingTasks)): ?><tr><td colspan="7" class="text-center text-muted py-4">No outstanding cleaning charges match these filters.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div></div>

    <p class="text-muted small mt-3">Payments shown are allocations against the selected completed tasks, regardless of when those payments were made. This is not a cash-flow report. Pending and in-progress tasks are excluded.</p>
</div>
@endsection
