<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<?php
$issue = $issue ?? null;
$isEdit = !empty($issue);

$unitList = $units ?? [];
$staffList = $staff ?? [];

$action = $isEdit
    ? site_url('maintenance/update/' . $issue['id'])
    : site_url('maintenance/store');

$value = static function ($field, $default = '') use ($issue) {
    return old($field, $issue[$field] ?? $default);
};

$categories = [
    'plumbing' => 'Plumbing',
    'electrical' => 'Electrical',
    'air_conditioning' => 'Air Conditioning',
    'internet' => 'Internet / Wi-Fi',
    'appliances' => 'Appliances',
    'furniture' => 'Furniture',
    'cleaning' => 'Cleaning',
    'painting' => 'Painting',
    'security' => 'Security',
    'general' => 'General Maintenance',
    'other' => 'Other'
];

$priorities = [
    'low' => 'Low',
    'medium' => 'Medium',
    'high' => 'High',
    'urgent' => 'Urgent'
];

$statuses = [
    'open' => 'Open',
    'assigned' => 'Assigned',
    'in_progress' => 'In Progress',
    'on_hold' => 'On Hold',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled'
];
?>

<div style="max-width:1100px">

    <!-- Page Header -->
    <div class="mb-4">

        <a href="<?= site_url('maintenance') ?>"
           class="text-decoration-none">
            &larr; Back to Maintenance
        </a>

    </div>

    <div class="mb-4">

        <h3 class="fw-bold mb-1">
            <?= $isEdit
                ? 'Edit Maintenance Issue'
                : 'Report Maintenance Issue' ?>
        </h3>

        <p class="text-muted mb-0">
            <?= $isEdit
                ? 'Update issue progress, staff assignment, service provider and repair costs.'
                : 'Record a new apartment maintenance issue and assign responsibility.' ?>
        </p>

    </div>

    <form method="post"
          action="<?= esc($action) ?>"
          id="maintenanceForm">

        <?= csrf_field() ?>

        <!-- Issue Details -->
        <div class="card border-0 shadow-sm mb-4">

            <div class="card-body p-4">

                <h5 class="fw-bold mb-4">
                    <i data-lucide="clipboard-list"
                       class="me-2"
                       style="width:20px;height:20px"></i>
                    Maintenance Issue Details
                </h5>

                <div class="row g-4">

                    <div class="col-md-6">

                        <label class="form-label fw-semibold">
                            Apartment
                            <span class="text-danger">*</span>
                        </label>

                        <select name="unit_id"
                                class="form-select"
                                required>

                            <option value="">
                                Select Apartment
                            </option>

                            <?php foreach ($unitList as $unit): ?>

                                <option
                                    value="<?= esc($unit['id']) ?>"
                                    <?= (string) $value('unit_id') ===
                                        (string) $unit['id']
                                        ? 'selected' : '' ?>>

                                    <?= esc($unit['property_name'] ?? 'Property') ?>
                                    -
                                    <?= esc($unit['unit_number']) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-md-6">

                        <label class="form-label fw-semibold">
                            Issue Category
                        </label>

                        <select name="category"
                                class="form-select">

                            <?php foreach ($categories as $key => $label): ?>

                                <option
                                    value="<?= esc($key) ?>"
                                    <?= $value('category', 'general') === $key
                                        ? 'selected' : '' ?>>

                                    <?= esc($label) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-12">

                        <label class="form-label fw-semibold">
                            Issue Title
                            <span class="text-danger">*</span>
                        </label>

                        <input type="text"
                               name="title"
                               class="form-control"
                               maxlength="180"
                               required
                               placeholder="e.g. Bedroom AC not cooling"
                               value="<?= esc($value('title')) ?>">

                    </div>

                    <div class="col-12">

                        <label class="form-label fw-semibold">
                            Issue Description
                            <span class="text-danger">*</span>
                        </label>

                        <textarea name="description"
                                  class="form-control"
                                  rows="5"
                                  required
                                  placeholder="Describe the maintenance issue, affected equipment, symptoms and any action already taken..."><?= esc($value('description')) ?></textarea>

                    </div>

                </div>

            </div>

        </div>

        <!-- Priority and Status -->
        <div class="card border-0 shadow-sm mb-4">

            <div class="card-body p-4">

                <h5 class="fw-bold mb-4">
                    <i data-lucide="activity"
                       class="me-2"
                       style="width:20px;height:20px"></i>
                    Priority & Progress
                </h5>

                <div class="row g-4">

                    <div class="col-md-6">

                        <label class="form-label fw-semibold">
                            Priority
                        </label>

                        <select name="priority"
                                id="priority"
                                class="form-select">

                            <?php foreach ($priorities as $key => $label): ?>

                                <option
                                    value="<?= esc($key) ?>"
                                    <?= $value('priority', 'medium') === $key
                                        ? 'selected' : '' ?>>

                                    <?= esc($label) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                        <div class="form-text">
                            Use Urgent for issues requiring immediate attention,
                            such as serious water leaks or electrical hazards.
                        </div>

                    </div>

                    <div class="col-md-6">

                        <label class="form-label fw-semibold">
                            Maintenance Status
                        </label>

                        <select name="status"
                                id="maintenanceStatus"
                                class="form-select">

                            <?php foreach ($statuses as $key => $label): ?>

                                <option
                                    value="<?= esc($key) ?>"
                                    <?= $value('status', 'open') === $key
                                        ? 'selected' : '' ?>>

                                    <?= esc($label) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                        <div class="form-text">
                            Progress the issue from Open to Assigned,
                            In Progress and finally Completed.
                        </div>

                    </div>

                </div>

            </div>

        </div>

        <!-- Internal Staff Assignment -->
        <div class="card border-0 shadow-sm mb-4">

            <div class="card-body p-4">

                <h5 class="fw-bold mb-4">
                    <i data-lucide="users"
                       class="me-2"
                       style="width:20px;height:20px"></i>
                    Internal Staff Assignment
                </h5>

                <div class="row g-4">

                    <div class="col-md-8">

                        <label class="form-label fw-semibold">
                            Assigned Haven Suites Staff Member
                        </label>

                        <select name="assigned_staff_id"
                                class="form-select">

                            <option value="">
                                Not Assigned
                            </option>

                            <?php foreach ($staffList as $member): ?>

                                <option
                                    value="<?= esc($member['id']) ?>"
                                    <?= (string) $value('assigned_staff_id') ===
                                        (string) $member['id']
                                        ? 'selected' : '' ?>>

                                    <?= esc($member['full_name']) ?>

                                    <?php if (!empty($member['role'])): ?>
                                        -
                                        <?= esc($member['role']) ?>
                                    <?php endif; ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                        <div class="form-text">
                            Select the Haven Suites team member responsible
                            for following up and coordinating this issue.
                        </div>

                    </div>

                </div>

                <div class="alert alert-light border mt-4 mb-0">

                    <div class="d-flex gap-3">

                        <i data-lucide="info"
                           style="width:20px;height:20px;flex-shrink:0"></i>

                        <div class="small">

                            <strong>How staff assignments work</strong>

                            <p class="mb-0 mt-1">
                                The assigned team member is responsible
                                for coordinating repairs, communicating
                                with external providers and updating
                                the work notes and issue status.
                            </p>

                        </div>

                    </div>

                </div>

            </div>

        </div>

        <!-- External Provider -->
        <div class="card border-0 shadow-sm mb-4">

            <div class="card-body p-4">

                <h5 class="fw-bold mb-4">
                    <i data-lucide="hard-hat"
                       class="me-2"
                       style="width:20px;height:20px"></i>
                    External Technician / Service Provider
                </h5>

                <p class="text-muted small mb-4">
                    Record the external person or company handling
                    the repair. They do not need to be registered
                    as Haven Suites staff.
                </p>

                <div class="row g-4">

                    <div class="col-md-6">

                        <label class="form-label fw-semibold">
                            Technician / Provider Name
                        </label>

                        <input type="text"
                               name="provider_name"
                               class="form-control"
                               maxlength="150"
                               placeholder="e.g. ABC Air Conditioning Services"
                               value="<?= esc($value('provider_name')) ?>">

                    </div>

                    <div class="col-md-6">

                        <label class="form-label fw-semibold">
                            Provider Phone
                        </label>

                        <input type="tel"
                               name="provider_phone"
                               class="form-control"
                               maxlength="40"
                               placeholder="+254 7XX XXX XXX"
                               value="<?= esc($value('provider_phone')) ?>">

                    </div>

                </div>

            </div>

        </div>

        <!-- Work Notes -->
        <div class="card border-0 shadow-sm mb-4">

            <div class="card-body p-4">

                <h5 class="fw-bold mb-4">
                    <i data-lucide="notebook-pen"
                       class="me-2"
                       style="width:20px;height:20px"></i>
                    Work Progress & Notes
                </h5>

                <label class="form-label fw-semibold">
                    Work Notes
                </label>

                <textarea name="work_notes"
                          class="form-control"
                          rows="6"
                          placeholder="Record technician visits, diagnosis, repairs completed, materials required, follow-up actions and progress updates..."><?= esc($value('work_notes')) ?></textarea>

                <div class="form-text mt-2">
                    These notes help the Haven Suites team track
                    the actions taken to resolve the issue.
                </div>

            </div>

        </div>

        <!-- Cost -->
        <div class="card border-0 shadow-sm mb-4">

            <div class="card-body p-4">

                <h5 class="fw-bold mb-4">
                    <i data-lucide="wallet"
                       class="me-2"
                       style="width:20px;height:20px"></i>
                    Maintenance Cost
                </h5>

                <div class="row g-4">

                    <div class="col-md-6">

                        <label class="form-label fw-semibold">
                            Recorded Repair Cost
                        </label>

                        <div class="input-group">

                            <span class="input-group-text">
                                KES
                            </span>

                            <input type="number"
                                   name="cost"
                                   class="form-control"
                                   min="0"
                                   step="0.01"
                                   value="<?= esc($value('cost', 0)) ?>">

                        </div>

                        <div class="form-text">
                            Enter the repair cost in Kenyan shillings.
                            You can update this figure as the work progresses.
                        </div>

                    </div>

                </div>

            </div>

        </div>

        <!-- Actions -->
        <div class="card border-0 shadow-sm">

            <div class="card-body p-4">

                <div class="d-flex flex-wrap gap-3">

                    <button type="submit"
                            class="btn btn-primary px-4">

                        <i data-lucide="save"
                           class="me-1"
                           style="width:17px;height:17px"></i>

                        <?= $isEdit
                            ? 'Update Maintenance Issue'
                            : 'Save Maintenance Issue' ?>

                    </button>

                    <a href="<?= site_url('maintenance') ?>"
                       class="btn btn-outline-secondary px-4">
                        Cancel
                    </a>

                </div>

            </div>

        </div>

    </form>

</div>

<?= $this->endSection() ?>
