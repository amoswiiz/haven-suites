@extends('layouts/main')

@section('content')

<?php
// Supports both add and edit operations.
$property = $property ?? null;
$isEdit = !empty($property);

$action = $isEdit
    ? site_url('properties/update/' . $property['id'])
    : site_url('properties/store');

// Preserve submitted values if validation fails.
$value = static function ($field, $default = '') use ($property) {
    return old($field, $property[$field] ?? $default);
};
?>

<div style="max-width:950px">

    <!-- Back navigation -->
    <div class="mb-4">
        <?php if (haven_can_access(site_url('properties'), 'GET')): ?><a href="<?= site_url('properties') ?>"
           class="text-decoration-none">
            &larr; Back to Properties
        </a><?php endif; ?>
    </div>

    <!-- Page heading -->
    <div class="mb-4">
        <h3 class="fw-bold mb-1">
            <?= $isEdit ? 'Edit Property' : 'Add New Property' ?>
        </h3>

        <p class="text-muted">
            <?= $isEdit
                ? 'Update the property details and management status.'
                : 'Register a new building or property under Haven Suites.' ?>
        </p>
    </div>

    <!-- Property form -->
    <div class="card border-0 shadow-sm">

        <div class="card-body p-4">

            <h5 class="fw-bold mb-4">
                <i data-lucide="building-2"
                   style="width:20px;height:20px"
                   class="me-2"></i>
                Property Information
            </h5>

            <form method="post" action="<?= esc($action) ?>">

                <?= csrf_field() ?>

                <div class="row g-4">

                    <!-- Property name -->
                    <div class="col-md-6">

                        <label class="form-label fw-semibold">
                            Property Name
                            <span class="text-danger">*</span>
                        </label>

                        <input
                            type="text"
                            name="name"
                            class="form-control"
                            maxlength="150"
                            required
                            placeholder="e.g. Coral Apartments"
                            value="<?= esc($value('name')) ?>"
                        >

                        <div class="form-text">
                            Enter the building or property name.
                        </div>

                    </div>

                    <!-- Location -->
                    <div class="col-md-6">

                        <label class="form-label fw-semibold">
                            Location
                            <span class="text-danger">*</span>
                        </label>

                        <input
                            type="text"
                            name="location"
                            class="form-control"
                            required
                            placeholder="e.g. Nyali, Mombasa"
                            value="<?= esc($value('location')) ?>"
                        >

                    </div>

                    <!-- Address -->
                    <div class="col-12">

                        <label class="form-label fw-semibold">
                            Property Address
                        </label>

                        <input
                            type="text"
                            name="address"
                            class="form-control"
                            placeholder="e.g. First Avenue, Nyali, behind City Mall"
                            value="<?= esc($value('address')) ?>"
                        >

                        <div class="form-text">
                            Include the road, street or nearby landmark.
                        </div>

                    </div>

                    <!-- Description -->
                    <div class="col-12">

                        <label class="form-label fw-semibold">
                            Property Description
                        </label>

                        <textarea
                            name="description"
                            class="form-control"
                            rows="5"
                            placeholder="Describe the building, amenities, access and other useful details."
                        ><?= esc($value('description')) ?></textarea>

                    </div>

                    <!-- Property status -->
                    <div class="col-md-6">

                        <label class="form-label fw-semibold">
                            Property Status
                        </label>

                        <select name="status"
                                class="form-select">

                            <option value="active"
                                <?= $value('status', 'active') === 'active'
                                    ? 'selected' : '' ?>>
                                Active
                            </option>

                            <option value="inactive"
                                <?= $value('status', 'active') === 'inactive'
                                    ? 'selected' : '' ?>>
                                Inactive
                            </option>

                        </select>

                        <div class="form-text">
                            Inactive properties remain in the system
                            but are marked as unavailable for operations.
                        </div>

                    </div>

                </div>

                <!-- Actions -->
                <hr class="my-4">

                <div class="d-flex gap-3 flex-wrap">

                    <button type="submit"
                            class="btn btn-primary px-4">

                        <i data-lucide="save"
                           style="width:17px;height:17px"
                           class="me-1"></i>

                        <?= $isEdit
                            ? 'Update Property'
                            : 'Save Property' ?>

                    </button>

                    <?php if (haven_can_access(site_url('properties'), 'GET')): ?><a href="<?= site_url('properties') ?>"
                       class="btn btn-outline-secondary px-4">
                        Cancel
                    </a><?php endif; ?>

                </div>

            </form>

        </div>

    </div>

    <!-- Information panel -->
    <div class="alert alert-light border mt-4">

        <div class="d-flex align-items-start gap-3">

            <i data-lucide="info"
               style="width:20px;height:20px"
               class="text-primary flex-shrink-0"></i>

            <div>
                <strong>About Properties</strong>

                <p class="mb-0 mt-1 text-muted small">
                    A property represents a building or development.
                    After registering it, you can add individual
                    apartment units under that property using
                    Apartment Units Management.
                </p>
            </div>

        </div>

    </div>

</div>

@endsection
