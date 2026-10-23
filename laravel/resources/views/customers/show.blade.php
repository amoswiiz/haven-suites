@extends('layouts/main')
@section('content')
<?php
// Self-contained customer profile enhancement. Existing Customers controller
// can continue passing only $customer and $kyc.
$db = db_connect();
$customerId = (int) $customer['id'];
$reservations = $db->table('reservations')
    ->select('id, check_in, check_out, status, total_amount, guest_name')
    ->where('customer_id', $customerId)
    ->orderBy('id', 'DESC')->get()->getResultArray();
$reservationIds = array_map(static fn($r) => (int) $r['id'], $reservations);
$invoices = [];
if ($reservationIds) {
    $invoices = $db->table('invoices')
        ->select('id, invoice_number, reservation_id, status, total_amount, issue_date')
        ->whereIn('reservation_id', $reservationIds)
        ->where('status !=', 'void')
        ->orderBy('id', 'DESC')->get()->getResultArray();
}
$canViewFinance = haven_auth()->user()?->can('finance.view') ?? false;
$canViewKyc = $canViewFinance;
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
  <div><a href="<?= site_url('customers') ?>" class="text-decoration-none small">&larr; All Customers</a>
    <h3 class="fw-bold mt-2 mb-1"><?= esc($customer['full_name']) ?></h3>
    <p class="text-muted mb-0">Customer profile #<?= $customerId ?></p>
  </div>
</div>
<div class="hs-panel mb-4">
  <h5 class="mb-3">Contact Information</h5>
  <div class="row g-3">
    <div class="col-md-4"><div class="text-muted small">Primary Phone</div><div><?= esc($customer['primary_phone']) ?></div></div>
    <div class="col-md-4"><div class="text-muted small">Email</div><div><?= esc($customer['email'] ?: '—') ?></div></div>
    <div class="col-md-4"><div class="text-muted small">Alternative Contact</div><div><?= esc($customer['alternative_contact'] ?: '—') ?></div></div>
  </div>
</div>
<div class="hs-panel mb-4">
  <h5 class="mb-3">Reservations</h5>
  <div class="table-responsive"><table class="table table-hover align-middle">
    <thead><tr><th>Booking</th><th>Check-in</th><th>Check-out</th><th>Status</th><th class="text-end">Total (KES)</th></tr></thead>
    <tbody>
    <?php foreach ($reservations as $booking): ?>
      <tr><td>#<?= (int) $booking['id'] ?></td><td><?= esc($booking['check_in']) ?></td><td><?= esc($booking['check_out']) ?></td>
      <td><?= esc(ucfirst((string) $booking['status'])) ?></td><td class="text-end"><?= number_format((float) $booking['total_amount'], 2) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$reservations): ?><tr><td colspan="5" class="text-center text-muted py-4">No linked reservations yet.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>
<?php if ($canViewFinance): ?>
<div class="hs-panel mb-4">
  <h5 class="mb-3">Invoices</h5>
  <div class="table-responsive"><table class="table table-hover align-middle">
    <thead><tr><th>Invoice</th><th>Reservation</th><th>Issue Date</th><th>Status</th><th class="text-end">Total (KES)</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($invoices as $invoice): ?>
      <tr><td><?= esc($invoice['invoice_number'] ?: 'Draft #' . $invoice['id']) ?></td>
      <td>#<?= (int) $invoice['reservation_id'] ?></td><td><?= esc($invoice['issue_date'] ?: '—') ?></td>
      <td><?= esc(ucfirst((string) $invoice['status'])) ?></td>
      <td class="text-end"><?= number_format((float) $invoice['total_amount'], 2) ?></td>
      <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= site_url('invoices/' . (int) $invoice['id']) ?>">View Invoice</a></td></tr>
    <?php endforeach; ?>
    <?php if (!$invoices): ?><tr><td colspan="6" class="text-center text-muted py-4">No invoices linked to this customer's reservations.</td></tr><?php endif; ?>
    </tbody></table></div>
  <p class="text-muted small mb-0">Standalone invoices are not shown because the current invoices table has no customer ID link.</p>
</div>
<?php endif; ?>
<div class="hs-panel mb-4">
  <h5 class="mb-3">KYC Submissions</h5>
  <div class="table-responsive"><table class="table table-hover align-middle">
    <thead><tr><th>Submission</th><th>Reservation</th><th>Status</th><th>Submitted</th><th>Reviewed</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($kyc as $entry): ?>
      <tr><td>#<?= (int) $entry['id'] ?></td>
      <td><?= !empty($entry['reservation_id']) ? '#' . (int) $entry['reservation_id'] : '—' ?></td>
      <td><?= esc(ucfirst((string) $entry['status'])) ?></td>
      <td><?= esc($entry['submitted_at'] ?: '—') ?></td><td><?= esc($entry['reviewed_at'] ?: '—') ?></td>
      <td class="text-end"><?php if ($canViewKyc && !empty($entry['submitted_at'])): ?><a class="btn btn-sm btn-outline-primary" href="<?= site_url('staff/kyc/' . (int) $entry['id']) ?>">Review</a><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$kyc): ?><tr><td colspan="6" class="text-center text-muted py-4">No KYC submissions yet.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>
@endsection
