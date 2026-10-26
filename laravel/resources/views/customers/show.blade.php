@extends('layouts/main')
@section('content')
<?php
$customerId = (int) $customer['id'];
$invoicesByBooking = collect($invoices)->groupBy('reservation_id');
$bookingsById = collect($reservations)->keyBy('id');
$apartmentLabel = static function (array $booking): string {
    if (($booking['booking_type'] ?? 'managed') === 'partner') {
        return trim(($booking['partner_building'] ?? '').' / '.($booking['partner_name'] ?? ''), ' /') ?: 'Partner apartment';
    }
    return trim(($booking['property_name'] ?? '').' / '.($booking['unit_number'] ?? ''), ' /') ?: 'Apartment not assigned';
};
$canViewFinance = haven_auth()->user()?->can('finance.view') ?? false;
$canViewKyc = $canViewFinance;
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
  <div><?php if (haven_can_access(site_url('customers'), 'GET')): ?><a href="<?= site_url('customers') ?>" class="text-decoration-none small">&larr; All Customers</a><?php endif; ?>
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
    <thead><tr><th>Booking / Apartment</th><th>Check-in</th><th>Check-out</th><th>Status</th><th class="text-end">Total (KES)</th><th>Booking invoice</th></tr></thead>
    <tbody>
    <?php foreach ($reservations as $booking): ?>
      <tr><td><?php if (haven_can_access(site_url('reservations/'.$booking['id']), 'GET')): ?><a href="<?= site_url('reservations/'.$booking['id']) ?>">#<?= (int) $booking['id'] ?></a><?php else: ?>#<?= (int) $booking['id'] ?><?php endif; ?><br><strong><?= esc($apartmentLabel($booking)) ?></strong></td><td><?= esc($booking['check_in']) ?></td><td><?= esc($booking['check_out']) ?></td>
      <td><?= esc(ucfirst((string) $booking['status'])) ?></td><td class="text-end"><?= number_format((float) $booking['total_amount'], 2) ?></td><td>
      <?php $bookingInvoices = $invoicesByBooking->get($booking['id'], collect()); ?>
      <?php foreach ($bookingInvoices as $bookingInvoice): ?>
        <?php if ($canViewFinance && haven_can_access(site_url('invoices/'.$bookingInvoice['id']), 'GET')): ?><a class="d-block" href="<?= site_url('invoices/'.$bookingInvoice['id']) ?>"><?= esc($bookingInvoice['invoice_number'] ?: 'Draft #'.$bookingInvoice['id']) ?></a><?php endif; ?>
      <?php endforeach; ?>
      <?php if ($bookingInvoices->isEmpty()): ?>
        <?php if ($booking['status'] !== 'cancelled' && haven_can_access(site_url('invoices/create'), 'GET')): ?><a class="btn btn-sm btn-outline-primary" href="<?= site_url('invoices/create?reservation_id='.$booking['id']) ?>">Create invoice</a><?php else: ?><span class="text-muted">No invoice</span><?php endif; ?>
      <?php endif; ?>
      </td></tr>
    <?php endforeach; ?>
    <?php if (!$reservations): ?><tr><td colspan="6" class="text-center text-muted py-4">No linked reservations yet.</td></tr><?php endif; ?>
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
      <td>#<?= (int) $invoice['reservation_id'] ?><br><small><?= esc($apartmentLabel($bookingsById->get($invoice['reservation_id'], []))) ?></small></td><td><?= esc($invoice['issue_date'] ?: '—') ?></td>
      <td><?= esc(ucfirst((string) $invoice['status'])) ?></td>
      <td class="text-end"><?= number_format((float) $invoice['total_amount'], 2) ?></td>
      <td class="text-end"><?php if (haven_can_access(site_url('invoices/' . (int) $invoice['id']), 'GET')): ?><a class="btn btn-sm btn-outline-primary" href="<?= site_url('invoices/' . (int) $invoice['id']) ?>">View Invoice</a><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$invoices): ?><tr><td colspan="6" class="text-center text-muted py-4">No invoices linked to this customer's reservations.</td></tr><?php endif; ?>
    </tbody></table></div>
  <p class="text-muted small mb-0">This section shows invoices linked to this customer's bookings.</p>
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
      <td class="text-end"><?php if ($canViewKyc && !empty($entry['submitted_at'])): ?><?php if (haven_can_access(site_url('staff/kyc/' . (int) $entry['id']), 'GET')): ?><a class="btn btn-sm btn-outline-primary" href="<?= site_url('staff/kyc/' . (int) $entry['id']) ?>">Review</a><?php endif; ?><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$kyc): ?><tr><td colspan="6" class="text-center text-muted py-4">No KYC submissions yet.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>
@endsection
