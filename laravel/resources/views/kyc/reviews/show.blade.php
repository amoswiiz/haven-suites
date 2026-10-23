@extends('layouts/main')
@section('content')
<?php
$record = $record ?? [];
$id = (int) ($record['id'] ?? 0);
$status = (string) ($record['status'] ?? 'pending');
$styles = ['pending' => 'warning text-dark', 'approved' => 'success', 'rejected' => 'danger'];
$documentTypes = ['national_id' => 'National ID', 'passport' => 'Passport', 'alien_id' => 'Alien ID', 'driving_licence' => 'Driving Licence'];
?>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
  <div>
    <h3 class="fw-bold mb-1">KYC Review #<?= $id ?></h3>
    <p class="text-muted mb-0">Guest identity verification and staff decision.</p>
  </div>
  <a href="<?= site_url('staff/kyc') ?>" class="btn btn-outline-secondary">Back to KYC Reviews</a>
</div>
<?php if (haven_session()->getFlashdata('success')): ?>
  <div class="alert alert-success" role="alert"><?= esc(haven_session()->getFlashdata('success')) ?></div>
<?php endif; ?>
<?php if (haven_session()->getFlashdata('error')): ?>
  <div class="alert alert-danger" role="alert"><?= esc(haven_session()->getFlashdata('error')) ?></div>
<?php endif; ?>
<div class="row g-4">
  <div class="col-lg-7">
    <div class="hs-panel mb-4">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h5 class="fw-bold mb-0">Customer Details</h5>
        <span class="badge bg-<?= esc($styles[$status] ?? 'secondary') ?>"><?= esc(ucfirst($status)) ?></span>
      </div>
      <dl class="row mb-0">
        <dt class="col-sm-5 text-muted">Full legal name</dt><dd class="col-sm-7"><?= esc($record['full_name'] ?? '—') ?></dd>
        <dt class="col-sm-5 text-muted">Primary phone</dt><dd class="col-sm-7"><?= esc($record['primary_phone'] ?? '—') ?></dd>
        <dt class="col-sm-5 text-muted">Email</dt><dd class="col-sm-7"><?= esc($record['email'] ?? '—') ?></dd>
        <dt class="col-sm-5 text-muted">Alternative contact</dt><dd class="col-sm-7"><?= esc($record['alternative_contact'] ?? '—') ?></dd>
        <dt class="col-sm-5 text-muted">Customer ID</dt><dd class="col-sm-7">#<?= (int) ($record['customer_id'] ?? 0) ?></dd>
        <dt class="col-sm-5 text-muted">Reservation ID</dt><dd class="col-sm-7"><?= !empty($record['reservation_id']) ? '#' . (int) $record['reservation_id'] : '—' ?></dd>
        <dt class="col-sm-5 text-muted">Submitted at</dt><dd class="col-sm-7"><?= esc($record['submitted_at'] ?? '—') ?></dd>
      </dl>
    </div>
    <div class="hs-panel">
      <h5 class="fw-bold mb-3">Identification Document</h5>
      <p class="mb-2"><strong>Type:</strong> <?= esc($documentTypes[$record['document_type'] ?? ''] ?? ($record['document_type'] ?? '—')) ?></p>
      <p class="mb-3"><strong>Uploaded filename:</strong> <?= esc($record['document_original_name'] ?? '—') ?></p>
      <?php if (!empty($record['document_storage_key'])): ?>
        <a class="btn btn-outline-primary" href="<?= site_url('staff/kyc/' . $id . '/document') ?>" target="_blank" rel="noopener noreferrer">View Identification Document</a>
        <p class="small text-muted mt-2 mb-0">Opens through a staff-authorized route. Do not share document links with guests.</p>
      <?php else: ?>
        <div class="alert alert-warning mb-0">No identification document is recorded.</div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-5">
    <?php if (!empty($record['submitted_at']) && !empty($record['reservation_id'])): ?>
    <div class="hs-panel mb-4">
      <h5 class="fw-bold mb-2">Reservation Invoice</h5>
      <p class="text-muted mb-3">Prepare an invoice using the submitted KYC details and reservation #<?= (int) $record['reservation_id'] ?>. If an active invoice already exists, it will be opened instead of creating a duplicate.</p>
      <?php if (haven_auth()->user()?->can('finance.manage')): ?>
        <form method="post" action="<?= site_url('staff/kyc/' . $id . '/invoice') ?>">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-primary">Create / Open Invoice</button>
        </form>
      <?php else: ?>
        <p class="text-muted small mb-0">Invoice creation requires finance management permission.</p>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="hs-panel">
      <h5 class="fw-bold mb-3">Staff Review</h5>
      <?php if ($status === 'pending'): ?>
        <p class="text-muted">Check the document against the submitted guest details before making a decision.</p>
        <form method="post" action="<?= site_url('staff/kyc/' . $id . '/decide') ?>">
          <?= csrf_field() ?>
          <label class="form-label" for="review_notes">Review notes</label>
          <textarea class="form-control mb-2" id="review_notes" name="review_notes" rows="5" maxlength="5000" placeholder="Record any verification notes or reason for rejection."><?= esc(old('review_notes')) ?></textarea>
          <p class="small text-muted">A reason is required when rejecting a submission.</p>
          <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-success" type="submit" name="decision" value="approved" onclick="return confirm('Approve this KYC submission?')">Approve</button>
            <button class="btn btn-outline-danger" type="submit" name="decision" value="rejected" onclick="return confirm('Reject this KYC submission?')">Reject</button>
          </div>
        </form>
      <?php else: ?>
        <p><strong>Decision:</strong> <?= esc(ucfirst($status)) ?></p>
        <p><strong>Reviewed at:</strong> <?= esc($record['reviewed_at'] ?? '—') ?></p>
        <p><strong>Reviewer ID:</strong> <?= !empty($record['reviewed_by']) ? '#' . (int) $record['reviewed_by'] : '—' ?></p>
        <p class="mb-1"><strong>Review notes:</strong></p>
        <div class="border rounded p-3" style="white-space:pre-wrap"><?= esc($record['review_notes'] ?: 'No notes recorded.') ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>
@endsection
