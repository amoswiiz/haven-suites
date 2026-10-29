@extends('layouts/main')
@section('styles')
<style>
.hs-edit{--navy:#294780;--orange:#e98d50;max-width:1180px;margin:auto}.hs-edit .hs-head{background:var(--navy);color:white;border-radius:14px;padding:25px}.hs-edit .hs-head h3{color:white}.hs-edit .hs-panel{background:white;border:1px solid #e2e8f0;border-radius:13px;padding:24px;margin-bottom:20px;box-shadow:0 3px 12px rgba(22,40,70,.035)}.hs-edit h5{color:#203454;font-weight:700;border-bottom:2px solid #f0f3f8;padding-bottom:12px;margin-bottom:20px}.hs-edit label{font-weight:600;font-size:13px;color:#304466;margin-bottom:6px}.hs-edit .form-control:focus,.hs-edit .form-select:focus{border-color:#829aca;box-shadow:0 0 0 .2rem rgba(41,71,128,.12)}.hs-edit .btn-save{background:var(--navy);border-color:var(--navy);color:white;font-weight:700}.hs-edit .btn-save:hover{background:#1d3562;color:white}.hs-edit .hs-total{font-size:27px;font-weight:800;color:var(--navy)}.hs-edit .hs-note{border-left:4px solid var(--orange);background:#fff8f2;padding:12px 15px;border-radius:6px}
</style>
@endsection
@section('content')
<?php
$r = $reservation;
$id = (int) $r['id'];
$type = ($r['booking_type'] ?? 'managed') === 'partner' ? 'partner' : 'managed';
$val = static function (string $key, $fallback = '') use ($r) { $old = old($key); return $old !== null ? $old : ($r[$key] ?? $fallback); };
$ownerNightly = (float) ($r['owner_net_amount'] ?? 0) / max(1, (int) (new DateTimeImmutable($r['check_in']))->diff(new DateTimeImmutable($r['check_out']))->days);
$ownerValue = old('owner_nightly_rate') ?? number_format($ownerNightly, 2, '.', '');
?>
<div class="hs-edit">
  <div class="hs-head d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div><div class="small text-white-50 mb-1">HAVEN SUITES · RESERVATION #<?= $id ?></div><h3 class="fw-bold mb-1">Edit Reservation</h3><div class="small">Update guest, stay and apartment information</div></div>
    <?php if (haven_can_access(site_url('reservations/' . $id), 'GET')): ?><a class="btn btn-light" href="<?= site_url('reservations/' . $id) ?>">Back to Booking</a><?php endif; ?>
  </div>
  <?php if (haven_session()->getFlashdata('error')): ?><div class="alert alert-danger" role="alert"><?= esc(haven_session()->getFlashdata('error')) ?></div><?php endif; ?>
  <?php if (haven_session()->getFlashdata('success')): ?><div class="alert alert-success" role="status"><?= esc(haven_session()->getFlashdata('success')) ?></div><?php endif; ?>
  <?php if (haven_can_access(site_url('reservations/update/' . $id), 'POST')): ?><form method="post" action="<?= site_url('reservations/update/' . $id) ?>" id="reservationEditForm">
    <?= csrf_field() ?>
    <div class="hs-panel"><h5>Guest Details</h5><div class="row g-3">
      <div class="col-md-6"><label for="guest_name">Guest Name *</label><input required maxlength="150" class="form-control" id="guest_name" name="guest_name" value="<?= esc($val('guest_name'), 'attr') ?>"></div>
      <div class="col-md-6"><label for="guest_phone">Phone Number *</label><input required maxlength="40" class="form-control" id="guest_phone" name="guest_phone" value="<?= esc($val('guest_phone'), 'attr') ?>"></div>
      <div class="col-md-6"><label for="guest_email">Email Address</label><input type="email" maxlength="150" class="form-control" id="guest_email" name="guest_email" value="<?= esc($val('guest_email'), 'attr') ?>"></div>
      <div class="col-md-3"><label for="adults">Adults *</label><input type="number" min="1" required class="form-control" id="adults" name="adults" value="<?= esc($val('adults', 1), 'attr') ?>"></div>
      <div class="col-md-3"><label for="children">Children *</label><input type="number" min="0" required class="form-control" id="children" name="children" value="<?= esc($val('children', 0), 'attr') ?>"></div>
    </div></div>
    <div class="hs-panel"><h5>Apartment & Stay</h5><div class="hs-note mb-3">Booking type: <strong><?= $type === 'partner' ? 'Partner Apartment' : 'Managed Apartment' ?></strong>. To change booking type, create a separate reservation.</div><div class="row g-3">
      <?php if ($type === 'partner'): ?>
        <div class="col-12"><label for="partner_apartment_id">Partner Apartment *</label><select required class="form-select" id="partner_apartment_id" name="partner_apartment_id"><option value="">Select apartment</option><?php foreach ($partners as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (string) $val('partner_apartment_id') === (string) $p['id'] ? 'selected' : '' ?>><?= esc($p['apartment_name'] . (!empty($p['location']) ? ' — ' . $p['location'] : '')) ?></option><?php endforeach; ?></select></div>
      <?php else: ?>
        <div class="col-12"><label for="unit_id">Managed Apartment *</label><select required class="form-select" id="unit_id" name="unit_id"><option value="">Select apartment</option><?php foreach ($units as $u): ?><option value="<?= (int) $u['id'] ?>" <?= (string) $val('unit_id') === (string) $u['id'] ? 'selected' : '' ?>><?= esc(($u['property_name'] ?? 'Building') . ' — ' . ($u['unit_number'] ?? 'Unit')) ?></option><?php endforeach; ?></select></div>
      <?php endif; ?>
      <div class="col-md-6"><label for="check_in">Check-in *</label><input type="date" required class="form-control" id="check_in" name="check_in" value="<?= esc($val('check_in'), 'attr') ?>"></div>
      <div class="col-md-6"><label for="check_out">Check-out *</label><input type="date" required class="form-control" id="check_out" name="check_out" value="<?= esc($val('check_out'), 'attr') ?>"></div>
      <div class="col-md-6"><label for="status">Booking Status *</label><select required class="form-select" id="status" name="status"><?php foreach (['pending'=>'Pending','confirmed'=>'Confirmed','checked_in'=>'Checked In','checked_out'=>'Checked Out','cancelled'=>'Cancelled'] as $k=>$label): ?><option value="<?= $k ?>" <?= $val('status') === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
      <div class="col-md-6"><label for="booking_source">Booking Source</label><input maxlength="100" class="form-control" id="booking_source" name="booking_source" value="<?= esc($val('booking_source', 'Direct'), 'attr') ?>"></div>
    </div></div>
    <div class="hs-panel"><h5>Rates & Financial Summary</h5><div class="row g-3 align-items-end">
      <div class="col-md-6"><label for="nightly_rate">Guest Nightly Rate (KES) *</label><input type="number" required min="<?= $type === 'partner' ? '0.01' : '0' ?>" step="0.01" class="form-control" id="nightly_rate" name="nightly_rate" value="<?= esc($val('nightly_rate'), 'attr') ?>"></div>
      <?php if ($type === 'partner'): ?><div class="col-md-6"><label for="owner_nightly_rate">Owner Nightly Rate (KES) *</label><input type="number" required min="0" step="0.01" class="form-control" id="owner_nightly_rate" name="owner_nightly_rate" value="<?= esc($ownerValue, 'attr') ?>"></div><?php endif; ?>
      <div class="col-md-4"><div class="text-muted small">Nights</div><div class="fw-bold" id="previewNights">—</div></div><div class="col-md-4"><div class="text-muted small">Estimated Booking Total</div><div class="hs-total" id="previewTotal">—</div></div><div class="col-md-4"><div class="text-muted small">Recorded Paid Amount</div><div class="fw-bold">KES <?= number_format((float) ($r['recorded_paid'] ?? 0), 2) ?></div></div>
    </div><p class="small text-muted mt-3 mb-0">Payments are not editable here. The server checks availability and prevents totals below recorded payments. Non-draft invoices may restrict changes to booking totals.</p></div>
    <div class="hs-panel"><h5>Internal Notes</h5><label for="notes">Booking Notes</label><textarea class="form-control" id="notes" name="notes" rows="4"><?= esc($val('notes')) ?></textarea></div>
    <div class="d-flex justify-content-end gap-2 flex-wrap mb-4"><?php if (haven_can_access(site_url('reservations/' . $id), 'GET')): ?><a href="<?= site_url('reservations/' . $id) ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?><button type="submit" class="btn btn-save px-4">Save Changes</button></div>
  </form><?php endif; ?>
</div>
@endsection
@section('scripts')
<script>
document.addEventListener('DOMContentLoaded',function(){
  const start=document.getElementById('check_in'),end=document.getElementById('check_out'),rate=document.getElementById('nightly_rate'),nights=document.getElementById('previewNights'),total=document.getElementById('previewTotal');
  function refresh(){const a=start.value?new Date(start.value+'T12:00:00Z'):null,b=end.value?new Date(end.value+'T12:00:00Z'):null;const n=a&&b?Math.round((b-a)/86400000):0;nights.textContent=n>0?n:'Invalid dates';total.textContent=n>0?'KES '+(n*(Number(rate.value)||0)).toLocaleString('en-KE',{minimumFractionDigits:2,maximumFractionDigits:2}):'—';}
  [start,end,rate].forEach(x=>x.addEventListener('input',refresh));refresh();
  document.getElementById('reservationEditForm').addEventListener('submit',function(e){if(!start.value||!end.value||end.value<=start.value){e.preventDefault();alert('Check-out must be later than check-in.');return;}const owner=document.getElementById('owner_nightly_rate');if(owner&&Number(owner.value)>Number(rate.value)){e.preventDefault();alert('Owner nightly rate cannot exceed the guest nightly rate.');}});
});
</script>
@endsection
