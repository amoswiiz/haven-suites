@extends('layouts/main')
@section('styles')
<style>
.hs-booking{--navy:#294780;--orange:#e98d50;color:#243247}.hs-booking .hs-hero{background:linear-gradient(115deg,#20385f,#294780);color:#fff;border-radius:15px;padding:25px;border-bottom:5px solid var(--orange)}.hs-booking .hs-hero h2,.hs-booking .hs-hero p{color:#fff}.hs-booking .hs-card{background:#fff;border:1px solid #e1e7f0;border-radius:13px;padding:22px;height:100%;box-shadow:0 3px 12px rgba(30,50,85,.04)}.hs-booking .hs-card h5{color:var(--navy);font-weight:750;border-bottom:2px solid #f1f3f8;padding-bottom:12px;margin-bottom:16px}.hs-booking .hs-label{font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.04em}.hs-booking .hs-value{font-weight:600;overflow-wrap:anywhere}.hs-booking .hs-metric{border-left:4px solid var(--orange)}.hs-booking .hs-metric strong{font-size:22px;color:var(--navy)}.hs-booking .hs-link{color:var(--navy);font-weight:650}.hs-booking .hs-status{display:inline-block;border-radius:50px;background:#fff1e3;color:#8e4e1e;padding:6px 12px;font-weight:700;font-size:12px}.hs-booking .table thead th{background:#f0f4fa;color:var(--navy);font-size:12px;white-space:nowrap}.hs-booking .table td{vertical-align:middle}.hs-booking .hs-pre{white-space:pre-wrap;overflow-wrap:anywhere}
</style>
@endsection
@section('content')
<?php
$r = $reservation;
$id = (int) ($r['id'] ?? 0);
$start = new \DateTimeImmutable($r['check_in']);
$end = new \DateTimeImmutable($r['check_out']);
$nights = $start->diff($end)->days;
$total = (float) ($r['total_amount'] ?? 0);
$bookingPaid = (float) ($r['amount_paid'] ?? 0);
$recordedPayments = array_sum(array_map(static fn($p) => (float) ($p['amount'] ?? 0), $payments ?? []));
$bookingBalance = max(0, $total - $recordedPayments);
$isPartner = ($r['booking_type'] ?? 'managed') === 'partner';
$apartment = $isPartner ? ($r['partner_name'] ?: 'Partner Apartment') : trim(($r['property_name'] ?? 'Building') . ' — ' . ($r['unit_number'] ?? ''));
$statusLabels = ['pending'=>'Pending','confirmed'=>'Confirmed','checked_in'=>'Checked In','checked_out'=>'Checked Out','cancelled'=>'Cancelled'];
$status = (string) ($r['status'] ?? 'pending');
?>
<div class="hs-booking">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <?php if (haven_can_access(site_url('reservations'), 'GET')): ?><a class="hs-link text-decoration-none" href="<?= site_url('reservations') ?>">&larr; All Reservations</a><?php endif; ?>
    @if (auth()->user()->can('finance.manage'))<a class="btn btn-primary" href="#reservation-payment">Record payment</a>@endif
    <?php if (haven_can_access(site_url('invoices'), 'GET')): ?><a class="btn btn-outline-secondary" href="<?= site_url('invoices') ?>">Invoices</a><?php endif; ?>
  </div>
  <?php if (haven_session()->getFlashdata('success')): ?><div class="alert alert-success"><?= esc(haven_session()->getFlashdata('success')) ?></div><?php endif; ?>
  <?php if (haven_session()->getFlashdata('error')): ?><div class="alert alert-danger"><?= esc(haven_session()->getFlashdata('error')) ?></div><?php endif; ?>
  <div class="hs-hero mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
    <div><div class="small" style="color:#ffbd8c;font-weight:700;letter-spacing:.09em">HAVEN SUITES · RESERVATION</div><h2 class="fw-bold mb-1">Booking #<?= $id ?></h2><p class="mb-0"><?= esc($r['guest_name'] ?? '') ?> · <?= esc($apartment) ?></p></div>
    <div class="text-md-end"><span class="hs-status"><?= esc($statusLabels[$status] ?? ucfirst($status)) ?></span><div class="small mt-2" style="color:#e7eefb"><?= esc(ucfirst($r['booking_type'] ?? 'managed')) ?> Booking</div></div>
  </div>
  <div class="row g-3 mb-4">
    <div class="col-lg-4 col-md-6"><div class="hs-card hs-metric"><div class="hs-label">Booking Total</div><strong>KES <?= number_format($total,2) ?></strong></div></div>
    <div class="col-lg-4 col-md-6"><div class="hs-card hs-metric"><div class="hs-label">Recorded payments</div><strong>KES <?= number_format($recordedPayments,2) ?></strong></div></div>
    <div class="col-lg-4 col-md-6"><div class="hs-card hs-metric"><div class="hs-label">Booking Balance</div><strong>KES <?= number_format($bookingBalance,2) ?></strong></div></div>
  </div>
  <div class="row g-3 mb-4">
    <div class="col-lg-6"><div class="hs-card"><h5>Guest Details</h5>
      <div class="row g-3"><div class="col-12"><div class="hs-label">Guest Name</div><div class="hs-value"><?= esc($r['guest_name'] ?? '—') ?></div></div>
      <div class="col-sm-6"><div class="hs-label">Phone</div><div class="hs-value"><?= esc($r['guest_phone'] ?: '—') ?></div></div>
      <div class="col-sm-6"><div class="hs-label">Email</div><div class="hs-value"><?= esc($r['guest_email'] ?: '—') ?></div></div>
      <div class="col-sm-6"><div class="hs-label">Adults</div><div class="hs-value"><?= (int) ($r['adults'] ?? 0) ?></div></div>
      <div class="col-sm-6"><div class="hs-label">Children</div><div class="hs-value"><?= (int) ($r['children'] ?? 0) ?></div></div></div>
    </div></div>
    <div class="col-lg-6"><div class="hs-card"><h5>Stay & Apartment</h5>
      <div class="row g-3"><div class="col-12"><div class="hs-label">Apartment</div><div class="hs-value"><?= esc($apartment) ?></div></div>
      <div class="col-sm-6"><div class="hs-label">Check-in</div><div class="hs-value"><?= esc($start->format('d M Y')) ?></div></div>
      <div class="col-sm-6"><div class="hs-label">Check-out</div><div class="hs-value"><?= esc($end->format('d M Y')) ?></div></div>
      <div class="col-sm-6"><div class="hs-label">Nights</div><div class="hs-value"><?= $nights ?></div></div>
      <div class="col-sm-6"><div class="hs-label">Booking Source</div><div class="hs-value"><?= esc($r['booking_source'] ?: '—') ?></div></div>
      <div class="col-sm-6"><div class="hs-label">Nightly Rate</div><div class="hs-value">KES <?= number_format((float) ($r['nightly_rate'] ?? 0),2) ?></div></div>
      <?php if ($isPartner): ?><div class="col-sm-6"><div class="hs-label">Partner Owner</div><div class="hs-value"><?= esc($r['partner_owner'] ?: '—') ?></div></div><?php endif; ?>
      </div>
    </div></div>
  </div>
  <?php if (!empty($r['notes'])): ?><div class="hs-card mb-4"><h5>Booking Notes</h5><div class="hs-pre"><?= esc($r['notes']) ?></div></div><?php endif; ?>
  @include('reservations.payment')
  <div class="hs-card mb-4"><div class="d-flex justify-content-between align-items-center flex-wrap gap-2"><h5 class="mb-2">Recorded Guest Payments</h5><span class="text-muted small">Total recorded: KES <?= number_format($recordedPayments,2) ?></span></div>
    <div class="table-responsive"><table class="table table-hover"><thead><tr><th>Payment #</th><th>Date</th><th>Method</th><th>Reference</th><th class="text-end">Amount</th></tr></thead><tbody>
      <?php foreach (($payments ?? []) as $p): ?><tr><td>#<?= (int) $p['id'] ?></td><td><?= esc($p['payment_date'] ?? '—') ?></td><td><?= esc($p['payment_method'] ?? '—') ?></td><td><?= esc(($p['reference'] ?? '') ?: '—') ?></td><td class="text-end text-nowrap">KES <?= number_format((float) ($p['amount'] ?? 0),2) ?></td></tr><?php endforeach; ?>
      <?php if (empty($payments)): ?><tr><td colspan="5" class="text-muted text-center py-3">No guest payments recorded for this reservation.</td></tr><?php endif; ?>
    </tbody></table></div>
    <div class="small text-muted">Payments above reflect the guest payment register. Invoice allocations are available on each invoice.</div>
  </div>
  <div class="hs-card mb-4"><h5>Related Invoices</h5>
    <div class="table-responsive"><table class="table table-hover"><thead><tr><th>Invoice</th><th>Status</th><th>Issue Date</th><th class="text-end">Total</th><th>Action</th></tr></thead><tbody>
      <?php foreach (($invoices ?? []) as $inv): ?><tr><td><?= esc(($inv['invoice_number'] ?? '') ?: 'Draft #' . $inv['id']) ?></td><td><?= esc(ucfirst($inv['status'] ?? 'draft')) ?></td><td><?= esc(($inv['issue_date'] ?? '') ?: '—') ?></td><td class="text-end text-nowrap"><?= esc($inv['currency'] ?? 'KES') ?> <?= number_format((float) ($inv['total_amount'] ?? 0),2) ?></td><td><?php if (haven_can_access(site_url('invoices/' . (int) $inv['id']), 'GET')): ?><a class="btn btn-sm btn-outline-primary" href="<?= site_url('invoices/' . (int) $inv['id']) ?>">View Invoice</a><?php endif; ?></td></tr><?php endforeach; ?>
      <?php if (empty($invoices)): ?><tr><td colspan="5" class="text-muted text-center py-3">No invoices linked to this reservation.</td></tr><?php endif; ?>
    </tbody></table></div>
  </div>
</div>
@endsection
