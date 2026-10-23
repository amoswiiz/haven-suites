@extends('layouts/main')
@section('content')
<?php
$records = $records ?? [];
$selectedStatus = $selectedStatus ?? '';
$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
foreach ($records as $entry) {
    if (isset($counts[$entry['status']])) {
        $counts[$entry['status']]++;
    }
}
$statuses = ['pending' => ['warning', 'Pending'], 'approved' => ['success', 'Approved'], 'rejected' => ['danger', 'Rejected']];
?>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
  <div>
    <h3 class="fw-bold mb-1">KYC Reviews</h3>
    <p class="text-muted mb-0">Review submitted guest identity details and documents.</p>
  </div>
  <?php if (haven_can_access(site_url('customers'), 'GET')): ?><a href="<?= site_url('customers') ?>" class="btn btn-outline-secondary">Customer Directory</a><?php endif; ?>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3"><div class="hs-stat-card"><div class="hs-stat-label">Shown Submissions</div><div class="fs-4 fw-bold"><?= count($records) ?></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="hs-stat-card"><div class="hs-stat-label">Pending</div><div class="fs-4 fw-bold text-warning"><?= $counts['pending'] ?></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="hs-stat-card"><div class="hs-stat-label">Approved</div><div class="fs-4 fw-bold text-success"><?= $counts['approved'] ?></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="hs-stat-card"><div class="hs-stat-label">Rejected</div><div class="fs-4 fw-bold text-danger"><?= $counts['rejected'] ?></div></div></div>
</div>

<div class="hs-panel mb-3">
  <?php if (haven_can_access(site_url('staff/kyc'), 'GET')): ?><form method="get" action="<?= site_url('staff/kyc') ?>" class="row g-3 align-items-end">
    <div class="col-md-4">
      <label class="form-label" for="kyc-status">Verification status</label>
      <select class="form-select" id="kyc-status" name="status">
        <option value="" <?= $selectedStatus === '' ? 'selected' : '' ?>>All submitted</option>
        <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $value => $label): ?>
          <option value="<?= esc($value) ?>" <?= $selectedStatus === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-auto"><button class="btn btn-primary" type="submit">Apply Filter</button></div>
    <div class="col-md-auto"><?php if (haven_can_access(site_url('staff/kyc'), 'GET')): ?><a href="<?= site_url('staff/kyc') ?>" class="btn btn-outline-secondary">Clear</a><?php endif; ?></div>
  </form><?php endif; ?>
  <p class="text-muted small mb-0 mt-3">Showing up to 200 recent submissions. Counts reflect only the records displayed.</p>
</div>

<div class="hs-panel">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>KYC #</th><th>Guest</th><th>Phone</th><th>Reservation</th><th>Submitted</th><th>Status</th><th class="text-end">Action</th></tr></thead>
      <tbody>
        <?php foreach ($records as $record): ?>
          <?php $status = (string) ($record['status'] ?? 'pending'); $style = $statuses[$status] ?? ['secondary', ucfirst($status)]; ?>
          <tr>
            <td class="fw-semibold">#<?= (int) $record['id'] ?></td>
            <td><?= esc($record['full_name'] ?: 'Unknown customer') ?></td>
            <td><?= esc($record['primary_phone'] ?: '—') ?></td>
            <td><?= !empty($record['reservation_id']) ? '#' . (int) $record['reservation_id'] : '—' ?></td>
            <td><?= esc($record['submitted_at'] ?: '—') ?></td>
            <td><span class="badge bg-<?= esc($style[0]) ?><?= $style[0] === 'warning' ? ' text-dark' : '' ?>"><?= esc($style[1]) ?></span></td>
            <td class="text-end"><?php if (haven_can_access(site_url('staff/kyc/' . (int) $record['id']), 'GET')): ?><a class="btn btn-sm btn-outline-primary" href="<?= site_url('staff/kyc/' . (int) $record['id']) ?>">Review</a><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$records): ?>
          <tr><td colspan="7" class="text-center text-muted py-5">No submitted KYC records found for this filter.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
@endsection
