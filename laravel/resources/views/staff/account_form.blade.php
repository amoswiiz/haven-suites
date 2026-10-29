<?php

?>@extends('layouts/main')<?php
?>@section('content')<?php

?>

<div class="container-fluid">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1">Create Staff Login Account</h4>
            <p class="text-muted mb-0">
                <?= esc($member['full_name']) ?>
            </p>
        </div>

        <?php if (haven_can_access(site_url('staff'), 'GET')): ?><a href="<?= site_url('staff') ?>" class="btn btn-outline-secondary">
            Back to Staff
        </a><?php endif; ?>
    </div>

    <?php if (haven_session()->getFlashdata('error')): ?>
        <div class="alert alert-danger">
            <?= esc(haven_session()->getFlashdata('error')) ?>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">

            <?php if (haven_can_access(site_url('staff/' . $member['id'] . '/account/store'), 'POST')): ?><form method="post"
                  action="<?= site_url('staff/' . $member['id'] . '/account/store') ?>">

                <?= csrf_field() ?>

                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input type="text"
                           name="username"
                           class="form-control"
                           value="<?= esc(old('username')) ?>"
                           required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Email Address</label>
                    <input type="email"
                           name="email"
                           class="form-control"
                           value="<?= esc(old('email', $member['email'])) ?>"
                           required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input type="password"
                           name="password"
                           class="form-control"
                           minlength="5"
                           autocomplete="new-password"
                           required>
                    <small class="text-muted">
                        Use at least 5 characters.
                    </small>
                </div>

                <div class="mb-4">
                    <label class="form-label">System Role</label>
                    <select name="group" class="form-select" required>
                        <option value="">Select Role</option>

                        <?php foreach ($groups as $group): ?>
                            <option value="<?= esc($group) ?>"
                                <?= old('group') === $group ? 'selected' : '' ?>>
                                <?= esc(ucfirst($group)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary">
                    Create Login Account
                </button>

            </form><?php endif; ?>

        </div>
    </div>

</div>

@endsection
