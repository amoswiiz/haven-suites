@extends('layouts/main')
@section('content')
<div class="mb-4"><a href="<?= site_url('customers') ?>" class="text-decoration-none small">&larr; All Customers</a><h3 class="fw-bold mt-2">Add Customer</h3><p class="text-muted">Register a customer before linking KYC and invoices.</p></div>
<div class="hs-panel"><form action="<?= site_url('customers') ?>" method="post"><?= csrf_field() ?><div class="row g-3">
<div class="col-md-6"><label for="full_name" class="form-label">Full Name *</label><input id="full_name" name="full_name" class="form-control" maxlength="180" required value="<?= esc(old('full_name') ?? '', 'attr') ?>"></div>
<div class="col-md-6"><label for="primary_phone" class="form-label">Primary Phone *</label><input id="primary_phone" name="primary_phone" type="tel" class="form-control" maxlength="40" required value="<?= esc(old('primary_phone') ?? '', 'attr') ?>"></div>
<div class="col-md-6"><label for="email" class="form-label">Email</label><input id="email" name="email" type="email" class="form-control" maxlength="180" value="<?= esc(old('email') ?? '', 'attr') ?>"></div>
<div class="col-md-6"><label for="alternative_contact" class="form-label">Alternative Contact / Phone</label><input id="alternative_contact" name="alternative_contact" class="form-control" maxlength="180" value="<?= esc(old('alternative_contact') ?? '', 'attr') ?>"></div>
<div class="col-12"><button class="btn btn-primary" type="submit">Save Customer</button> <a class="btn btn-outline-secondary" href="<?= site_url('customers') ?>">Cancel</a></div>
</div></form></div>
@endsection
