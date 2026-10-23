<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
@media print {
    .hs-no-print, .hs-sidebar, .hs-topbar, .navbar, header { display: none !important; }
    .hs-statement { width: 100% !important; max-width: none !important; }
    .hs-statement .card { box-shadow: none !important; border: 0 !important; }
    @page { size: A4; margin: 15mm; }
}
</style>

<div class="container-fluid py-3 hs-statement">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4 hs-no-print">
        <div>
            <h3 class="fw-bold mb-1">Cleaner Payment Statement</h3>
            <p class="text-muted mb-0">Charges and payments allocated to completed cleaning tasks.</p>
        </div>
        <?php if ($selectedStaff !== null): ?>
            <button class="btn btn-primary" type="button" onclick="window.print()">Print / Save as PDF</button>
        <?php endif; ?>
    </div>

    <?php if (session()->getFlashdata('error')): ?>
        <div class="alert alert-danger hs-no-print"><?= esc(session()->getFlashdata('error')) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= site_url('payments/cleaners/statements') ?>" class="card border-0 shadow-sm mb-4 hs-no-print">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label for="staff_id" class="form-label fw-semibold">Cleaner / Staff Member</label>
                    <select id="staff_id" name="staff_id" class="form-select" required>
                        <option value="">Select a staff member</option>
                        <?php foreach ($staff as $member): ?>
                            <option value="<?= (int) $member['id'] ?>" <?= $staffId === (int) $member['id'] ? 'selected' : '' ?>><?= esc($member['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="from" class="form-label fw-semibold">From</label>
                    <input id="from" type="date" name="from" class="form-control" value="<?= esc($from, 'attr') ?>" required>
                </div>
                <div class="col-md-3">
                    <label for="to" class="form-label fw-semibold">To</label>
                    <input id="to" type="date" name="to" class="form-control" value="<?= esc($to, 'attr') ?>" required>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Generate</button>
                </div>
            </div>
        </div>
    </form>

    <?php if ($selectedStaff === null): ?>
        <div class="alert alert-info">Select a cleaner and date range to generate a statement.</div>
    <?php else: ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between flex-wrap gap-3 border-bottom pb-3 mb-4">
                    <div>
                        <h2 class="fw-bold mb-1">HAVEN SUITES</h2>
                        <div class="text-muted">BnB Management System</div>
                    </div>
                    <div class="text-md-end">
                        <h4 class="fw-bold">CLEANER STATEMENT</h4>
                        <div><strong><?= esc($selectedStaff['full_name']) ?></strong></div>
                        <div><?= esc($from) ?> to <?= esc($to) ?></div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-4"><div class="border rounded p-3"><div class="text-muted small">Cleaning Charges</div><h4>KES <?= number_format($totals['charges'], 2) ?></h4></div></div>
                    <div class="col-md-4"><div class="border rounded p-3"><div class="text-muted small">Allocated Payments</div><h4>KES <?= number_format($totals['paid'], 2) ?></h4></div></div>
                    <div class="col-md-4"><div class="border rounded p-3"><div class="text-muted small">Remaining Balance</div><h4>KES <?= number_format($totals['balance'], 2) ?></h4></div></div>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Completed</th><th>Task</th><th>Property / Apartment</th><th>Bedrooms</th>
                                <th class="text-end">Charge</th><th class="text-end">Paid</th><th class="text-end">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tasks as $task): ?>
                                <tr>
                                    <td><?= esc($task['completed_at'] ?? '-') ?></td>
                                    <td>#<?= (int) $task['id'] ?><div class="small text-muted"><?= esc(str_replace('_', ' ', (string) $task['cleaning_type'])) ?></div></td>
                                    <td><?= esc(($task['property_name'] ?: '-') . ' / ' . ($task['unit_number'] ?: '-')) ?></td>
                                    <td><?= $task['bedrooms_cleaned'] === null ? '-' : (int) $task['bedrooms_cleaned'] ?></td>
                                    <td class="text-end"><?= number_format((float) $task['cleaning_charge'], 2) ?></td>
                                    <td class="text-end"><?= number_format((float) $task['paid_amount'], 2) ?></td>
                                    <td class="text-end"><?= number_format((float) $task['balance'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($tasks === []): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">No completed cleaning tasks in this period.</td></tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr class="fw-bold">
                                <td colspan="4" class="text-end">TOTAL (KES)</td>
                                <td class="text-end"><?= number_format($totals['charges'], 2) ?></td>
                                <td class="text-end"><?= number_format($totals['paid'], 2) ?></td>
                                <td class="text-end"><?= number_format($totals['balance'], 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p class="small text-muted mb-0">This statement covers tasks completed within the selected dates. Payments allocated to those tasks are counted only when their payment date is on or before <?= esc($to) ?>. It is not a complete account ledger or a historical snapshot of later edits.</p>
            </div>
        </div>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
