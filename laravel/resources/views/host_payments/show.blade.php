@extends('layouts/main')
@section('content')
<div class="container-fluid py-3">
    <a href="<?= site_url('payments/hosts') ?>">&larr; Host Payments</a>
    <h2 class="mt-2">Reservation #<?= esc($reservation['id']) ?> — Host Payments</h2>
    <p><?= esc($reservation['property_name'] ?? '') ?> / <?= esc($reservation['unit_number'] ?? '') ?></p>
    <?php if (session('success')): ?><div class="alert alert-success"><?= esc(session('success')) ?></div><?php endif ?>
    <?php if (session('error')): ?><div class="alert alert-danger"><?= esc(session('error')) ?></div><?php endif ?>
    <?php if ($needsReview): ?><div class="alert alert-warning">Agreement or payment reconciliation required. New payments are blocked until the records are corrected.</div><?php endif ?>
    <div class="row mb-4">
        <div class="col-md-4"><div class="card card-body"><small>Commission earned</small><h4>KES <?= esc($earned) ?></h4></div></div>
        <div class="col-md-4"><div class="card card-body"><small>Host payments</small><h4>KES <?= esc($paid) ?></h4></div></div>
        <div class="col-md-4"><div class="card card-body"><small>Outstanding</small><h4>KES <?= esc($balance) ?></h4></div></div>
    </div>
    <h4>Commission from guest collections</h4>
    <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Received</th><th>Host</th><th>Rate</th><th class="text-end">Collection (KES)</th><th class="text-end">Commission (KES)</th></tr></thead><tbody>
        <?php foreach ($collections as $item): ?><tr><td><?= esc($item['payment_date']) ?></td><td><?= esc($item['host_name']) ?></td><td><?= esc($item['rate']) ?>%</td><td class="text-end"><?= esc($item['collection']) ?></td><td class="text-end"><?= esc($item['commission']) ?></td></tr><?php endforeach ?>
        <?php if (!$collections): ?><tr><td colspan="5">No matched collections yet.</td></tr><?php endif ?>
    </tbody></table></div>
    <h4>Payments made to host</h4>
    <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Date</th><th>Method</th><th>Reference</th><th>Notes</th><th class="text-end">Amount (KES)</th></tr></thead><tbody>
        <?php foreach ($payments as $payment): ?><tr><td><?= esc($payment['payment_date']) ?></td><td><?= esc($payment['payment_method']) ?></td><td><?= esc($payment['reference'] ?? '') ?></td><td><?= esc($payment['notes'] ?? '') ?></td><td class="text-end"><?= esc($payment['amount']) ?></td></tr><?php endforeach ?>
        <?php if (!$payments): ?><tr><td colspan="5">No host payments recorded.</td></tr><?php endif ?>
    </tbody></table></div>
    <?php if ($canManage && !$needsReview && (float) $balance > 0): ?>
        <div class="card mt-4"><div class="card-body"><h4>Record host payment</h4>
            <form method="post" action="<?= site_url('payments/hosts/' . $reservation['id']) ?>">
                <?= csrf_field() ?>
                <div class="row g-3">
                    <div class="col-md-3"><label class="form-label">Payment date</label><input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></div>
                    <div class="col-md-3"><label class="form-label">Amount (KES)</label><input type="number" name="amount" min="0.01" step="0.01" class="form-control" required></div>
                    <div class="col-md-3"><label class="form-label">Method</label><select name="payment_method" class="form-select" required><option value="mpesa">M-Pesa</option><option value="bank">Bank</option><option value="cash">Cash</option></select></div>
                    <div class="col-md-3"><label class="form-label">Reference</label><input type="text" name="reference" maxlength="100" class="form-control"></div>
                    <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" rows="2" class="form-control"></textarea></div>
                    <div class="col-12"><button class="btn btn-primary">Save host payment</button></div>
                </div>
            </form>
        </div></div>
    <?php endif ?>
</div>
@endsection
