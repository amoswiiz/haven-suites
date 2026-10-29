@extends('layouts/main')
@section('content')
<?php
$isEdit = !empty($member);
$action = $isEdit ? site_url('staff/update/' . $member['id']) : site_url('staff/store');
$value = static function ($field, $default = '') use ($member) {
    return old($field, $member[$field] ?? $default);
};
$hasLoginAccount = (bool) ($hasLoginAccount ?? false);
$canResetPassword = (bool) ($canResetPassword ?? false);
$canCreateLogin = !$isEdit && haven_auth()->user() !== null
    && haven_auth()->user()->can('staff.accounts.manage');
?>
<div style="max-width:900px">
    <?php if (haven_can_access(site_url('staff'), 'GET')): ?><a href="<?= site_url('staff') ?>" class="text-decoration-none">&larr; Back to Staff</a><?php endif; ?>
    <div class="card border-0 shadow-sm mt-3">
        <div class="card-body p-4">
            <h3 class="fw-bold mb-4"><?= $isEdit ? 'Edit Staff Member' : 'Add Staff Member' ?></h3>
            <?php if (session('error')): ?>
                <div class="alert alert-danger" role="alert"><?= esc(session('error')) ?></div>
            <?php endif; ?>
            <form method="post" action="<?= esc($action, 'attr') ?>">
                <?= csrf_field() ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="full_name">Full Name</label>
                        <input id="full_name" name="full_name" class="form-control" maxlength="150" required value="<?= esc($value('full_name'), 'attr') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="phone">Phone</label>
                        <input id="phone" name="phone" class="form-control" maxlength="30" required value="<?= esc($value('phone'), 'attr') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="email">Email</label>
                        <input id="email" type="email" name="email" class="form-control" maxlength="150" value="<?= esc($value('email'), 'attr') ?>" <?= $canCreateLogin ? 'required' : '' ?>>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="role">Role</label>
                        <select id="role" name="role" class="form-select" required>
                            <option value="">Select Role</option>
                            <?php foreach (['Manager','Operations','Housekeeping','Sales','Maintenance Coordinator','Customer Relations','Administrator','Other'] as $role): ?>
                                <option value="<?= esc($role, 'attr') ?>" <?= $value('role') === $role ? 'selected' : '' ?>><?= esc($role) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="location">Location</label>
                        <select id="location" name="location" class="form-select" required>
                            <option value="">Select Location</option>
                            <?php foreach (['Nairobi','Mombasa','Diani','Nanyuki','Other'] as $location): ?>
                                <option value="<?= esc($location, 'attr') ?>" <?= $value('location') === $location ? 'selected' : '' ?>><?= esc($location) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="status">Status</label>
                        <select id="status" name="status" class="form-select">
                            <option value="active" <?= $value('status', 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $value('status', 'active') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="notes">Notes</label>
                        <textarea id="notes" name="notes" rows="4" class="form-control"><?= esc($value('notes')) ?></textarea>
                    </div>
                </div>
                <?php if ($canCreateLogin): ?>
                    <hr class="my-4">
                    <h5 class="fw-bold mb-2">Login Details</h5>
                    <p class="text-muted small">Set a password now. Save Staff creates the staff record and login together using the email above. No email invitation is required.</p>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="username">Username</label>
                            <input id="username" name="username" class="form-control" minlength="3" maxlength="30" pattern="[a-zA-Z0-9_-]+" autocomplete="off" value="<?= esc(old('username', ''), 'attr') ?>" required>
                        </div>
                        <div class="col-md-6 d-flex align-items-center">
                            <p class="text-muted small mb-0">Login permissions follow the Role selected above: Manager, Operations, Housekeeping (Cleaner), or Sales. Administrator access can only be assigned by a company superadministrator.</p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="password">Password</label>
                            <input type="password" id="password" name="password" class="form-control" minlength="5" maxlength="128" autocomplete="new-password" required>
                            <small class="text-muted">Use at least 5 characters.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="password_confirmation">Confirm Password</label>
                            <input type="password" id="password_confirmation" name="password_confirmation" class="form-control" minlength="5" maxlength="128" autocomplete="new-password" required>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($isEdit): ?>
                    <hr class="my-4">
                    <h5 class="fw-bold mb-2">Account Security</h5>
                    <?php if ($canResetPassword): ?>
                        <p class="text-muted small">Change this staff member's login password. Leave both fields empty to keep the current password.</p>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="new_password">New Password</label>
                                <input type="password" id="new_password" name="new_password" class="form-control" minlength="5" maxlength="128" autocomplete="new-password">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="confirm_password">Confirm New Password</label>
                                <input type="password" id="confirm_password" name="confirm_password" class="form-control" minlength="5" maxlength="128" autocomplete="new-password">
                            </div>
                        </div>
                    <?php elseif (!$hasLoginAccount): ?>
                        <p class="text-muted mb-2">This staff member does not have a linked login.</p>
<?php if (haven_can_access(site_url('staff/' . $member['id'] . '/account/create'), 'GET')): ?>
    <a class="btn btn-outline-primary"
       href="<?= esc(site_url('staff/' . $member['id'] . '/account/create'), 'attr') ?>">
        Create login
    </a>
    <p class="text-muted small mt-2 mb-0">
        Save any staff changes first. You can set a password directly; no email invitation is required.
    </p>
<?php endif; ?>
                    <?php else: ?>
                        <p class="text-muted mb-0">You do not have permission to reset staff passwords.</p>
                    <?php endif; ?>
                <?php endif; ?>
                <div class="mt-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Update Staff' : 'Save Staff' ?></button>
                    <?php if (haven_can_access(site_url('staff'), 'GET')): ?><a href="<?= site_url('staff') ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
