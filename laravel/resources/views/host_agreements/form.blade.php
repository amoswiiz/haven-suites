@extends('layouts/main')
@section('content')
<?php $v = static function (string $key, $default = '') use ($agreement) { return old($key, $agreement[$key] ?? $default); }; ?>
<div class="container-fluid py-3"><div class="d-flex justify-content-between align-items-center mb-4"><h3 class="fw-bold mb-0"><?= esc($pageTitle) ?></h3><?php if (haven_can_access(site_url('host-agreements'), 'GET')): ?><a href="<?= site_url('host-agreements') ?>" class="btn btn-outline-secondary">Back</a><?php endif; ?></div>
<?php if (session('error')): ?><div class="alert alert-danger"><?= esc(session('error')) ?></div><?php endif; ?>
<form action="<?= esc($action, 'attr') ?>" method="post" class="card border-0 shadow-sm"><div class="card-body row g-3">
<?= csrf_field() ?>
<div class="col-md-6"><label for="unit_id" class="form-label">Apartment *</label><select id="unit_id" name="unit_id" class="form-select" required>
<option value="">Select apartment</option>
<?php foreach ($units as $unit): ?><option value="<?= (int) $unit['id'] ?>" <?= (int) $v('unit_id') === (int) $unit['id'] ? 'selected' : '' ?>><?= esc(($unit['property_name'] ?? '') . ' / ' . $unit['unit_number']) ?></option><?php endforeach; ?>
</select><?php if ($agreement): ?><div class="form-text">The apartment cannot be changed after creation.</div><?php endif; ?></div>
<div class="col-md-6"><label for="host_name" class="form-label">Host Name *</label><input id="host_name" name="host_name" class="form-control" maxlength="150" required value="<?= esc($v('host_name'), 'attr') ?>"></div>
<div class="col-md-6"><label for="host_phone" class="form-label">Host Phone</label><input id="host_phone" name="host_phone" class="form-control" maxlength="50" value="<?= esc($v('host_phone'), 'attr') ?>"></div>
<div class="col-md-6"><label for="commission_rate" class="form-label">Commission Rate (%) *</label><input type="number" min="0" max="100" step="0.01" id="commission_rate" name="commission_rate" class="form-control" required value="<?= esc($v('commission_rate', '15.00'), 'attr') ?>"></div>
<div class="col-md-6"><label for="effective_from" class="form-label">Effective From *</label><input type="date" id="effective_from" name="effective_from" class="form-control" required value="<?= esc($v('effective_from'), 'attr') ?>"></div>
<div class="col-md-6"><label for="effective_to" class="form-label">Effective To (optional)</label><input type="date" id="effective_to" name="effective_to" class="form-control" value="<?= esc($v('effective_to'), 'attr') ?>"><div class="form-text">Leave empty if the agreement is ongoing. Periods for the same apartment cannot overlap.</div></div>
<div class="col-12"><label for="notes" class="form-label">Notes</label><textarea id="notes" name="notes" rows="3" class="form-control" maxlength="10000"><?= esc($v('notes')) ?></textarea></div>
<div class="col-12"><button type="submit" class="btn btn-primary">Save Agreement</button></div>
</div></form></div>
@endsection
