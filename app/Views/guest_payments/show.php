<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<div class="container-fluid py-3">
    <a href="<?= site_url('payments/guests') ?>" class="btn btn-link px-0 mb-2">&larr; Guest Payments</a>
    <h3 class="fw-bold">Reservation #<?= (int) $reservation['id'] ?> — <?= esc($reservation['guest_name']) ?></h3>
    <p class="text-muted"><?= esc(($reservation['property_name'] ?? '-') . ' / ' . ($reservation['unit_number'] ?? '-')) ?></p>
    <?php if (session('success')): ?><div class="alert alert-success"><?= esc(session('success')) ?></div><?php endif; ?>
    <?php if (session('error')): ?><div class="alert alert-danger"><?= esc(session('error')) ?></div><?php endif; ?>
    <?php if ($total === null): ?><div class="alert alert-warning">The guest total has not been set in booking_financials. Set it before recording payments.</div><?php endif; ?>
    <?php if ($canManage): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h5><?= $total === null ? 'Set Guest Total' : 'Update Guest Total' ?></h5>
            <p class="text-muted small">Set the full amount payable by the guest for this reservation. This does not record a payment.</p>
            <form method="post" action="<?= site_url('payments/guests/' . (int) $reservation['id'] . '/total') ?>" class="row g-3 align-items-end">
                <?= csrf_field() ?>
                <div class="col-md-4">
                    <label for="guest_total" class="form-label">Guest Total (KES)</label>
                    <input id="guest_total" type="number" name="guest_total" min="0.01" max="9999999999.99" step="0.01" class="form-control" required value="<?= esc(old('guest_total', $total ?? ''), 'attr') ?>">
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-primary">Save Guest Total</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($overpaid): ?><div class="alert alert-danger">Recorded collections exceed the reservation total. Review existing records.</div><?php endif; ?>
    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Reservation Total</div><h4>KES <?= $total === null ? 'Not set' : number_format((float) $total, 2) ?></h4></div></div></div>
        <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Collected</div><h4>KES <?= number_format((float) $paid, 2) ?></h4></div></div></div>
        <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Outstanding</div><h4>KES <?= $balance === null ? '—' : number_format((float) $balance, 2) ?></h4></div></div></div>
    </div>
    <?php if ($canManage && $total !== null && !$overpaid && (float) $balance > 0): ?>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body"><h5>Record Guest Payment</h5>
        <form method="post" action="<?= site_url('payments/guests/' . (int) $reservation['id']) ?>" class="row g-3">
            <?= csrf_field() ?>
            <div class="col-md-3"><label class="form-label">Payment Date</label><input type="date" name="payment_date" class="form-control" required value="<?= esc(old('payment_date', date('Y-m-d')), 'attr') ?>"></div>
            <div class="col-md-3"><label class="form-label">Amount (KES)</label><input type="number" name="amount" min="0.01" step="0.01" max="<?= esc($balance, 'attr') ?>" class="form-control" required value="<?= esc(old('amount', ''), 'attr') ?>"></div>
            <div class="col-md-3"><label class="form-label">Payment Method</label><select name="payment_method" class="form-select" required>
                <?php foreach (['mpesa'=>'M-Pesa','bank'=>'Bank','cash'=>'Cash','card'=>'Card','ota'=>'OTA','other'=>'Other'] as $key => $label): ?><option value="<?= esc($key, 'attr') ?>" <?= old('payment_method', 'mpesa') === $key ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?>
            </select></div>
            <div class="col-md-3"><label class="form-label">Reference</label><input name="reference" maxlength="100" class="form-control" value="<?= esc(old('reference', ''), 'attr') ?>"></div>
            <div class="col-md-12"><label class="form-label">Notes</label><textarea name="notes" rows="2" class="form-control"><?= esc(old('notes', '')) ?></textarea></div>
            <div class="col-12"><button class="btn btn-primary">Save Payment</button></div>
        </form>
    </div></div>
    <?php endif; ?>
    <div class="card border-0 shadow-sm"><div class="card-body"><h5>Payment History</h5><div class="table-responsive"><table class="table table-striped align-middle">
        <thead><tr><th>Date</th><th>Method</th><th>Reference</th><th>Notes</th><th class="text-end">Amount (KES)</th></tr></thead>
        <tbody><?php foreach ($payments as $payment): ?><tr><td><?= esc($payment['payment_date']) ?></td><td><?= esc(strtoupper($payment['payment_method'])) ?></td><td><?= esc($payment['reference'] ?? '-') ?></td><td><?= esc($payment['notes'] ?? '-') ?></td><td class="text-end"><?= number_format((float) $payment['amount'], 2) ?></td></tr><?php endforeach; ?>
        <?php if ($payments === []): ?><tr><td colspan="5" class="text-center text-muted py-4">No payments recorded yet.</td></tr><?php endif; ?></tbody>
    </table></div></div></div>
</div>
<?= $this->endSection() ?>
