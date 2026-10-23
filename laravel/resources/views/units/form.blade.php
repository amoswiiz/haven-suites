@extends('layouts/main')

@section('content')

<?php
// Support the existing Units controller.
$unit = $unit ?? null;
$isEdit = !empty($unit);

$action = $isEdit
    ? site_url('units/update/' . $unit['id'])
    : site_url('units/store');

$value = static function ($field, $default = '') use ($unit) {
    return old($field, $unit[$field] ?? $default);
};

$propertyList = $properties ?? [];
?>

<div style="max-width:1000px">

    <div class="mb-4">
        <a href="<?= site_url('units') ?>"
           class="text-decoration-none">
            &larr; Back to Apartment Units
        </a>
    </div>

    <div class="mb-4">
        <h3 class="fw-bold mb-1">
            <?= $isEdit ? 'Edit Apartment' : 'Add New Apartment' ?>
        </h3>

        <p class="text-muted">
            <?= $isEdit
                ? 'Update apartment information, pricing and operational status.'
                : 'Register an apartment and select or add its building in one place.' ?>
        </p>
    </div>

    <form method="post" action="<?= esc($action) ?>">
        <?= csrf_field() ?>

        <!-- Apartment Details -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">

                <h5 class="fw-bold mb-4">
                    <i data-lucide="house"
                       class="me-2"
                       style="width:20px;height:20px"></i>
                    Apartment Information
                </h5>

                <div class="row g-4">

                     <div class="col-md-6">
                         <label class="form-label fw-semibold">Building <span class="text-danger">*</span></label>
                         <select name="property_id" id="buildingSelect" class="form-select" required>
                             <option value="">Select building</option>
                             <?php foreach ($propertyList as $property): ?>
                                 <option value="<?= (int) $property['id'] ?>" <?= (string) $value('property_id') === (string) $property['id'] ? 'selected' : '' ?>>
                                     <?= esc($property['name']) ?><?= !empty($property['location']) ? ' — ' . esc($property['location']) : '' ?>
                                 </option>
                             <?php endforeach; ?>
                             <option value="new" <?= (string) old('property_id', '') === 'new' ? 'selected' : '' ?>>+ Add new building</option>
                         </select>
                         <div class="form-text">Choose an existing building or add one here.</div>
                         <div id="newBuildingFields" class="mt-3" style="display:none">
                             <label for="buildingName" class="form-label">New building name</label>
                             <input type="text" id="buildingName" name="building_name" maxlength="150" class="form-control mb-2" placeholder="e.g. Coral Gables" value="<?= esc(old('building_name', ''), 'attr') ?>">
                             <label for="buildingLocation" class="form-label">Building location</label>
                             <input type="text" id="buildingLocation" name="building_location" maxlength="150" class="form-control" placeholder="e.g. Nyali, Mombasa" value="<?= esc(old('building_location', ''), 'attr') ?>">
                         </div>
                     </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Apartment / Unit Number
                            <span class="text-danger">*</span>
                        </label>

                        <input type="text"
                               name="unit_number"
                               class="form-control"
                               maxlength="50"
                               required
                               placeholder="e.g. F5, F6, 1503"
                               value="<?= esc($value('unit_number')) ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">
                            Bedrooms
                        </label>

                        <input type="number"
                               name="bedrooms"
                               class="form-control"
                               min="0"
                               max="30"
                               required
                               value="<?= esc($value('bedrooms', 1)) ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">
                            Bathrooms
                        </label>

                        <input type="number"
                               name="bathrooms"
                               class="form-control"
                               min="0"
                               max="30"
                               required
                               value="<?= esc($value('bathrooms', 1)) ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">
                            Floor Number
                        </label>

                        <input type="number"
                               name="floor_number"
                               class="form-control"
                               min="-5"
                               max="200"
                               placeholder="e.g. 5"
                               value="<?= esc($value('floor_number')) ?>">
                    </div>

                </div>
            </div>
        </div>

        <!-- Pricing -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">

                <h5 class="fw-bold mb-4">
                    <i data-lucide="wallet"
                       class="me-2"
                       style="width:20px;height:20px"></i>
                    Apartment Pricing
                </h5>

                <div class="row g-4">

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Standard Nightly Rate (KES)
                            <span class="text-danger">*</span>
                        </label>

                        <div class="input-group">
                            <span class="input-group-text">KES</span>

                            <input type="number"
                                   name="nightly_rate"
                                   class="form-control"
                                   min="0"
                                   step="0.01"
                                   required
                                   placeholder="e.g. 8500"
                                   value="<?= esc($value('nightly_rate', 0)) ?>">
                        </div>

                        <div class="form-text">
                            This is the default rate used when creating
                            reservations. Individual reservations may
                            have different negotiated rates.
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- Ownership -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">

                <h5 class="fw-bold mb-4">
                    <i data-lucide="user-round"
                       class="me-2"
                       style="width:20px;height:20px"></i>
                    Owner Information
                </h5>

                <div class="row g-4">

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Owner Name
                        </label>

                        <input type="text"
                               name="owner_name"
                               class="form-control"
                               maxlength="150"
                               placeholder="Enter property owner's name"
                               value="<?= esc($value('owner_name')) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Owner Phone
                        </label>

                        <input type="tel"
                               name="owner_phone"
                               class="form-control"
                               maxlength="40"
                               placeholder="e.g. +254 7XX XXX XXX"
                               value="<?= esc($value('owner_phone')) ?>">
                    </div>

                </div>
            </div>
        </div>

        <!-- Operational Status -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">

                <h5 class="fw-bold mb-4">
                    <i data-lucide="settings"
                       class="me-2"
                       style="width:20px;height:20px"></i>
                    Operational Details
                </h5>

                <div class="row g-4">

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Apartment Status
                        </label>

                        <select name="status" class="form-select">

                            <option value="active"
                                <?= $value('status', 'active') === 'active'
                                    ? 'selected' : '' ?>>
                                Active
                            </option>

                            <option value="maintenance"
                                <?= $value('status', 'active') === 'maintenance'
                                    ? 'selected' : '' ?>>
                                Under Maintenance
                            </option>

                            <option value="inactive"
                                <?= $value('status', 'active') === 'inactive'
                                    ? 'selected' : '' ?>>
                                Inactive
                            </option>

                        </select>

                        <div class="form-text">
                            Use Under Maintenance when the apartment
                            requires repairs or is temporarily unavailable.
                        </div>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold">
                            Internal Notes
                        </label>

                        <textarea name="notes"
                                  class="form-control"
                                  rows="5"
                                  placeholder="Apartment features, access information, operational notes or special instructions..."><?= esc($value('notes')) ?></textarea>

                        <div class="form-text">
                            These notes are intended for the Haven Suites
                            operations team.
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">

                <div class="d-flex gap-3 flex-wrap">

                    <button type="submit"
                            class="btn btn-primary px-4">
                        <i data-lucide="save"
                           class="me-1"
                           style="width:17px;height:17px"></i>

                        <?= $isEdit
                            ? 'Update Apartment'
                            : 'Save Apartment' ?>
                    </button>

                    <a href="<?= site_url('units') ?>"
                       class="btn btn-outline-secondary px-4">
                        Cancel
                    </a>

                </div>

            </div>
        </div>

    </form>

</div>

@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const select = document.getElementById('buildingSelect');
    const fields = document.getElementById('newBuildingFields');
    const name = document.getElementById('buildingName');
    const location = document.getElementById('buildingLocation');
    function toggleBuilding() {
        const isNew = select.value === 'new';
        fields.style.display = isNew ? '' : 'none';
        name.required = isNew;
        location.required = isNew;
    }
    select.addEventListener('change', toggleBuilding);
    toggleBuilding();
});
</script>
@endsection
