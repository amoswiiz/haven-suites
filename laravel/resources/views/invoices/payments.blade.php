@extends('layouts/main')
@section('content')
<?php
$invoiceId = (int) ($invoice['id'] ?? 0);
$currency = esc($invoice['currency'] ?? 'KES');
$total = (float) ($invoice['total_amount'] ?? 0);
$balance = (float) ($invoiceBalance ?? 0);
$paid = max(0, $total - $balance);
$isIssued = ($invoice['status'] ?? '') === 'issued';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
  <div>
    <?php if (haven_can_access(site_url('invoices/' . $invoiceId), 'GET')): ?><a class="text-decoration-none small" href="<?= site_url('invoices/' . $invoiceId) ?>">&larr; Back to Invoice</a><?php endif; ?>
    <h3 class="fw-bold mt-2 mb-1">Invoice Payment Allocations</h3>
    <p class="text-muted mb-0">Invoice <?= esc($invoice['invoice_number'] ?: '#' . $invoiceId . ' (Draft)') ?> &middot; <?= esc($invoice['customer_name'] ?? '') ?></p>
  </div>
  <?php if (haven_can_access(site_url('invoices'), 'GET')): ?><a class="btn btn-outline-secondary" href="<?= site_url('invoices') ?>">All Invoices</a><?php endif; ?>
</div>
<?php if (haven_session()->getFlashdata('success')): ?>
  <div class="alert alert-success" role="alert"><?= esc(haven_session()->getFlashdata('success')) ?></div>
<?php endif; ?>
<?php if (haven_session()->getFlashdata('error')): ?>
  <div class="alert alert-danger" role="alert"><?= esc(haven_session()->getFlashdata('error')) ?></div>
<?php endif; ?>
<div class="row g-3 mb-4">
  <div class="col-md-4"><div class="hs-panel h-100"><div class="text-muted small">Invoice Total</div><h4 class="fw-bold mb-0"><?= $currency ?> <?= number_format($total, 2) ?></h4></div></div>
  <div class="col-md-4"><div class="hs-panel h-100"><div class="text-muted small">Allocated to Invoice</div><h4 class="fw-bold mb-0 text-success"><?= $currency ?> <?= number_format($paid, 2) ?></h4></div></div>
  <div class="col-md-4"><div class="hs-panel h-100"><div class="text-muted small">Outstanding Balance</div><h4 class="fw-bold mb-0"><?= $currency ?> <?= number_format($balance, 2) ?></h4></div></div>
</div>
<div class="hs-panel mb-4">
  <h5 class="fw-bold mb-2">Allocate an Existing Guest Payment</h5>
  <p class="text-muted small">This links a payment already recorded against reservation #<?= (int) ($invoice['reservation_id'] ?? 0) ?>. It does not create a new payment.</p>
  <?php if (! $isIssued): ?>
    <div class="alert alert-warning mb-0">Only issued invoices can receive payment allocations.</div>
  <?php elseif ($balance <= 0): ?>
    <div class="alert alert-success mb-0">This invoice has no outstanding balance.</div>
  <?php else: ?>
    <?php $hasAvailable = false; foreach ($payments as $p) { if ((float) $p['available_amount'] > 0) { $hasAvailable = true; break; } } ?>
    <?php if (! $hasAvailable): ?>
      <div class="alert alert-info mb-0">No unallocated guest payments are available for this reservation. Record the payment in Guest Payments first.</div>
    <?php else: ?>
      <?php if (haven_can_access(site_url('invoices/' . $invoiceId . '/payments'), 'POST')): ?><form method="post" action="<?= site_url('invoices/' . $invoiceId . '/payments') ?>" class="row g-3 align-items-end">
        <?= csrf_field() ?>
        <div class="col-lg-7">
          <label for="guest_payment_id" class="form-label">Existing Guest Payment</label>
          <select class="form-select" id="guest_payment_id" name="guest_payment_id" required>
            <option value="">Select a payment</option>
            <?php foreach ($payments as $payment): ?>
              <?php if ((float) $payment['available_amount'] <= 0) continue; ?>
              <option value="<?= (int) $payment['id'] ?>">
                #<?= (int) $payment['id'] ?> &mdash; <?= esc($payment['payment_date']) ?> &mdash; <?= esc($payment['payment_method']) ?><?= !empty($payment['reference']) ? ' (' . esc($payment['reference']) . ')' : '' ?> &mdash; Available <?= $currency ?> <?= number_format((float) $payment['available_amount'], 2) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-lg-3">
          <label for="amount" class="form-label">Amount (<?= $currency ?>)</label>
          <input class="form-control" type="number" id="amount" name="amount" min="0.01" max="<?= number_format($balance, 2, '.', '') ?>" step="0.01" required placeholder="0.00">
        </div>
        <div class="col-lg-2"><button type="submit" class="btn btn-primary w-100" onclick="return confirm('Allocate this existing payment to the invoice?')">Allocate</button></div>
      </form><?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</div>
<div class="hs-panel mb-4">
  <h5 class="fw-bold mb-3">Guest Payments for This Reservation</h5>
  <div class="table-responsive"><table class="table table-hover align-middle">
    <thead><tr><th>Payment #</th><th>Date</th><th>Method</th><th>Reference</th><th class="text-end">Payment</th><th class="text-end">Already Allocated</th><th class="text-end">Available</th></tr></thead>
    <tbody>
    <?php foreach ($payments as $payment): ?>
      <tr>
        <td>#<?= (int) $payment['id'] ?></td>
        <td><?= esc($payment['payment_date']) ?></td>
        <td><?= esc($payment['payment_method']) ?></td>
        <td><?= esc($payment['reference'] ?: '—') ?></td>
        <td class="text-end"><?= number_format((float) $payment['amount'], 2) ?></td>
        <td class="text-end"><?= number_format((float) $payment['allocated_amount'], 2) ?></td>
        <td class="text-end fw-semibold"><?= number_format((float) $payment['available_amount'], 2) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($payments)): ?><tr><td colspan="7" class="text-center text-muted py-4">No guest payments recorded for this reservation yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<div class="hs-panel">
  <h5 class="fw-bold mb-3">Allocation History</h5>
  <div class="table-responsive"><table class="table table-hover align-middle">
    <thead><tr><th>Allocation #</th><th>Allocated At</th><th>Payment Date</th><th>Method</th><th>Reference</th><th class="text-end">Amount (<?= $currency ?>)</th><th class="text-end">Receipt</th></tr></thead>
    <tbody>
      <?php foreach ($history as $allocation): ?>
        <tr>
          <td>#<?= (int) $allocation['id'] ?></td>
          <td><?= esc($allocation['allocated_at'] ?: '—') ?></td>
          <td><?= esc($allocation['payment_date']) ?></td>
          <td><?= esc($allocation['payment_method']) ?></td>
          <td><?= esc($allocation['reference'] ?: '—') ?></td>
          <td class="text-end"><?= number_format((float) $allocation['amount'], 2) ?></td>
          <td class="text-end"><?php if (haven_can_access(site_url('receipts/' . (int) $allocation['id']), 'GET')): ?><a class="btn btn-sm btn-outline-primary text-nowrap" href="<?= site_url('receipts/' . (int) $allocation['id']) ?>">View Receipt</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($history)): ?><tr><td colspan="7" class="text-center text-muted py-4">No payments have been allocated to this invoice.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
@endsection
