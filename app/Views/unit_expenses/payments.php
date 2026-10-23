<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<?php
$e = static fn ($value) => esc((string) ($value ?? ''));
?>
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h3 class="mb-1">Expense Payments #<?= (int) $expense['id'] ?></h3>
            <div class="text-muted"><?= $e($expense['property_name']) ?> / <?= $e($expense['unit_number']) ?> — <?= $e($expense['category_name']) ?></div>
        </div>
        <a href="<?= site_url('expenses') ?>" class="btn btn-outline-secondary">Back to Expense Register</a>
    </div>

    <?php if (session()->getFlashdata('success')): ?>
        <div class="alert alert-success"><?= esc(session()->getFlashdata('success')) ?></div>
    <?php endif; ?>
    <?php if (session()->getFlashdata('error')): ?>
        <div class="alert alert-danger"><?= esc(session()->getFlashdata('error')) ?></div>
    <?php endif; ?>
    <?php if ($reconciliationIssue): ?>
        <div class="alert alert-warning">Payment records do not match the expense's saved payment total, or payments exceed the expense. New payments are blocked until this is reconciled.</div>
    <?php endif; ?>

    <div class="card shadow-sm mb-3"><div class="card-body">
        <h5><?= $e($expense['description']) ?></h5>
        <p class="text-muted mb-3">Expense date: <?= $e($expense['expense_date']) ?> | Supplier: <?= $e($expense['supplier_name'] ?: 'Not specified') ?></p>
        <div class="row g-3">
            <div class="col-md-4"><div class="border rounded p-3"><div class="text-muted">Total Expense</div><h4>KES <?= number_format((float) $total, 2) ?></h4></div></div>
            <div class="col-md-4"><div class="border rounded p-3"><div class="text-muted">Payments Recorded</div><h4>KES <?= number_format((float) $paid, 2) ?></h4></div></div>
            <div class="col-md-4"><div class="border rounded p-3"><div class="text-muted">Outstanding</div><h4>KES <?= number_format((float) $balance, 2) ?></h4></div></div>
        </div>
    </div></div>

    <?php if ($canManage && !$reconciliationIssue && (float) $balance > 0): ?>
    <div class="card shadow-sm mb-3"><div class="card-body">
        <h5 class="mb-3">Record Additional Payment</h5>
        <form method="post" action="<?= site_url('expenses/' . (int) $expense['id'] . '/payments') ?>">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-3"><label class="form-label">Payment Date</label><input type="date" name="payment_date" class="form-control" value="<?= esc(old('payment_date', date('Y-m-d'))) ?>" required></div>
                <div class="col-md-3"><label class="form-label">Amount (KES)</label><input type="number" name="amount" class="form-control" min="0.01" max="<?= esc($balance, 'attr') ?>" step="0.01" value="<?= esc(old('amount')) ?>" required></div>
                <div class="col-md-3"><label class="form-label">Method</label><select name="payment_method" class="form-select" required><option value="">Select</option><?php foreach (['mpesa'=>'M-Pesa','bank'=>'Bank Transfer','cash'=>'Cash'] as $value => $label): ?><option value="<?= esc($value) ?>" <?= old('payment_method') === $value ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-3"><label class="form-label">Reference</label><input type="text" name="reference" maxlength="100" class="form-control" value="<?= esc(old('reference')) ?>"></div>
                <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"><?= esc(old('notes')) ?></textarea></div>
                <div class="col-12"><button type="submit" class="btn btn-primary">Save Payment</button></div>
            </div>
        </form>
    </div></div>
    <?php endif; ?>

    <div class="card shadow-sm"><div class="card-body">
        <h5 class="mb-3">Payment History</h5>
        <div class="table-responsive"><table class="table table-striped align-middle">
            <thead><tr><th>Date</th><th>Method</th><th>Reference</th><th>Notes</th><th class="text-end">Amount (KES)</th></tr></thead>
            <tbody>
            <?php foreach ($payments as $payment): ?>
                <tr><td><?= $e($payment['payment_date']) ?></td><td><?= $e(strtoupper($payment['payment_method'])) ?></td><td><?= $e($payment['reference'] ?: '-') ?></td><td><?= $e($payment['notes'] ?: '-') ?></td><td class="text-end"><?= number_format((float) $payment['amount'], 2) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$payments): ?><tr><td colspan="5" class="text-center text-muted py-4">No payments recorded.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div></div>
</div>
<?= $this->endSection() ?>
