@extends('layouts/main')

@section('content')

<?php
$task = $task ?? null;
$isEdit = !empty($task);

$unitList = $units ?? [];
$staffList = $staff ?? [];
$reservationList = $reservations ?? [];
$cleaningRates = $cleaningRates ?? [];
$selectedBedrooms = (string) old('bedrooms_cleaned', $task['bedrooms_cleaned'] ?? '');
$overrideCharge = (string) old('cleaning_charge_override', '');
$existingCharge = $task['cleaning_charge'] ?? null;
$canVerify = haven_auth()->user() && haven_auth()->user()->can('housekeeping.verify');
$previousStatus = $task['status'] ?? null;

$action = $isEdit
    ? site_url('housekeeping/update/' . $task['id'])
    : site_url('housekeeping/store');

$value = static function ($field, $default = '') use ($task) {
    return old($field, $task[$field] ?? $default);
};

$cleaningTypes = [
    'checkout' => 'Check-out Cleaning',
    'pre_checkin' => 'Pre Check-in Cleaning',
    'routine' => 'Routine Cleaning',
    'deep' => 'Deep Cleaning'
];

$statuses = [
    'pending' => 'Pending',
    'assigned' => 'Assigned',
    'in_progress' => 'In Progress',
    'completed' => 'Completed',
    'verified' => 'Verified',
    'cancelled' => 'Cancelled'
];

$scheduledValue = (string) $value('scheduled_at');

if ($scheduledValue !== '') {
    $scheduledValue = str_replace(' ', 'T', substr($scheduledValue, 0, 16));
}

$selectedUnitId = (string) $value('unit_id');
$selectedReservationId = (string) $value('reservation_id');
$selectedStaffId = (string) $value('assigned_staff_id');

?>

<div style="max-width:1100px">

    <div class="mb-4">
        <?php if (haven_can_access(site_url('housekeeping'), 'GET')): ?><a href="<?= site_url('housekeeping') ?>"
           class="text-decoration-none">
            &larr; Back to Housekeeping
        </a><?php endif; ?>
    </div>

    <div class="mb-4">
        <h3 class="fw-bold mb-1">
            <?= $isEdit
                ? 'Edit Housekeeping Task'
                : 'Schedule Cleaning' ?>
        </h3>

        <p class="text-muted mb-0">
            <?= $isEdit
                ? 'Update the cleaning assignment, progress and verification details.'
                : 'Schedule apartment cleaning and assign a Haven Suites team member.' ?>
        </p>
    </div>

    <form method="post"
          action="<?= esc($action, 'attr') ?>"
          id="housekeepingForm">

        <?= csrf_field() ?>

        <!-- Apartment and Reservation -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">

                <h5 class="fw-bold mb-4">
                    <i data-lucide="building-2"
                       class="me-2"
                       style="width:20px;height:20px"></i>
                    Apartment & Reservation
                </h5>

                <div class="row g-4">

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Apartment
                            <span class="text-danger">*</span>
                        </label>

                        <select name="unit_id"
                                id="unit_id"
                                class="form-select"
                                required>

                            <option value="">Select Apartment</option>

                            <?php foreach ($unitList as $unit): ?>
                                <option
                                    value="<?= esc($unit['id'], 'attr') ?>"
                                     data-bedrooms="<?= (int) ($unit['bedrooms'] ?? 0) ?>"
                                    <?= $selectedUnitId === (string) $unit['id']
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
                            Related Guest Reservation
                        </label>

                        <select name="reservation_id"
                                id="reservation_id"
                                class="form-select">

                            <option value="">
                                No Linked Reservation
                            </option>

                            <?php foreach ($reservationList as $reservation): ?>
                                <option
                                    value="<?= esc($reservation['id'], 'attr') ?>"
                                    data-unit="<?= esc($reservation['unit_id'], 'attr') ?>"
                                    <?= $selectedReservationId === (string) $reservation['id']
                                        ? 'selected' : '' ?>>

                                    #<?= esc($reservation['id']) ?>
                                    -
                                    <?= esc($reservation['guest_name']) ?>
                                    (<?= esc($reservation['check_in']) ?>
                                    to
                                    <?= esc($reservation['check_out']) ?>)

                                </option>
                            <?php endforeach; ?>

                        </select>

                        <div class="form-text">
                            Optional. Link cleaning to a guest check-in
                            or check-out. Only reservations belonging
                            to the selected apartment can be used.
                        </div>
                    </div>

                </div>

            </div>
        </div>

        <!-- Cleaning Schedule -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">

                <h5 class="fw-bold mb-4">
                    <i data-lucide="calendar-clock"
                       class="me-2"
                       style="width:20px;height:20px"></i>
                    Cleaning Schedule
                </h5>

                <div class="row g-4">

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Cleaning Type
                            <span class="text-danger">*</span>
                        </label>

                        <select name="cleaning_type"
                                class="form-select"
                                required>

                            <?php foreach ($cleaningTypes as $key => $label): ?>
                                <option
                                    value="<?= esc($key, 'attr') ?>"
                                    <?= $value('cleaning_type', 'checkout') === $key
                                        ? 'selected' : '' ?>>
                                    <?= esc($label) ?>
                                </option>
                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Scheduled Date & Time
                            <span class="text-danger">*</span>
                        </label>

                        <input type="datetime-local"
                               name="scheduled_at"
                               class="form-control"
                               required
                               value="<?= esc($scheduledValue, 'attr') ?>">

                        <div class="form-text">
                            Select when the cleaning team should
                            arrive at the apartment.
                        </div>
                    </div>

                </div>

            </div>
        </div>

        <!-- Cleaning Scope and Billing -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <h5 class="fw-bold mb-3">Cleaning Scope &amp; Billing</h5>
                <p class="text-muted small">Select the actual bedrooms to be cleaned. Rates are retrieved from the Haven Suites cleaning rate settings.</p>
                <div class="row g-4">
                    <div class="col-md-6">
                        <label for="bedrooms_cleaned" class="form-label fw-semibold">Bedrooms Cleaned</label>
                        <select name="bedrooms_cleaned" id="bedrooms_cleaned" class="form-select">
                            <option value="">Not recorded yet</option>
                            <?php foreach ($cleaningRates as $bedrooms => $rate): ?>
                                <option value="<?= (int) $bedrooms ?>"
                                        data-rate="<?= esc((string) $rate, 'attr') ?>"
                                        <?= $selectedBedrooms === (string) $bedrooms ? 'selected' : '' ?>>
                                    <?= (int) $bedrooms ?>BR — KES <?= number_format((float) $rate, 2) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">The selection cannot exceed the apartment's bedroom capacity.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Standard Cleaning Rate</label>
                        <div class="form-control bg-light" id="standardCleaningRate">Select bedrooms</div>
                        <div class="form-text">The saved charge may differ if a manager adjusted it previously.</div>
                    </div>
                    <div class="col-md-6">
                        <label for="cleaning_charge_override" class="form-label fw-semibold">Override Cleaning Charge (KES)</label>
                        <input type="number" name="cleaning_charge_override" id="cleaning_charge_override"
                               class="form-control" min="0" max="99999999.99" step="0.01"
                               value="<?= esc($overrideCharge, 'attr') ?>"
                               placeholder="Leave blank to use or preserve the saved rate">
                        <div class="form-text">Optional. Only use this for an approved exception.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Previously Saved Charge</label>
                        <div class="form-control bg-light">
                            <?= $existingCharge !== null ? 'KES ' . number_format((float) $existingCharge, 2) : 'Not recorded' ?>
                        </div>
                        <?php if (!empty($task['charge_adjusted_by'])): ?>
                            <div class="form-text">A manager previously adjusted this charge.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Staff Assignment -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">

                <h5 class="fw-bold mb-4">
                    <i data-lucide="users"
                       class="me-2"
                       style="width:20px;height:20px"></i>
                    Staff Assignment
                </h5>

                <div class="row g-4">

                    <div class="col-md-8">
                        <label class="form-label fw-semibold">
                            Assigned Cleaner / Staff Member
                        </label>

                        <select name="assigned_staff_id"
                                class="form-select">

                            <option value="">
                                Not Assigned
                            </option>

                            <?php foreach ($staffList as $member): ?>
                                <option
                                    value="<?= esc($member['id'], 'attr') ?>"
                                    <?= $selectedStaffId === (string) $member['id']
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
                            Select the team member responsible for
                            completing the cleaning task.
                        </div>
                    </div>

                </div>

                <div class="alert alert-light border mt-4 mb-0">
                    <div class="d-flex gap-3">

                        <i data-lucide="info"
                           style="width:20px;height:20px;flex-shrink:0"></i>

                        <div class="small">
                            <strong>Staff responsibility</strong>

                            <p class="mb-0 mt-1">
                                The assigned cleaner is responsible for
                                completing the cleaning and updating
                                the task notes. A supervisor can then
                                verify that the apartment is ready.
                            </p>
                        </div>

                    </div>
                </div>

            </div>
        </div>

        <!-- Progress -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">

                <h5 class="fw-bold mb-4">
                    <i data-lucide="activity"
                       class="me-2"
                       style="width:20px;height:20px"></i>
                    Cleaning Progress
                </h5>

                <div class="row g-4">

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Task Status
                        </label>

                        <select name="status"
                                id="taskStatus"
                                class="form-select">

                            <?php foreach ($statuses as $key => $label): ?>
                                 <?php if ($key === 'verified' && (!$canVerify || !$isEdit || $previousStatus !== 'completed')) continue; ?>
                                 <?php if ($previousStatus === 'verified' && $key !== 'verified') continue; ?>
                                <option
                                    value="<?= esc($key, 'attr') ?>"
                                    <?= $value('status', 'pending') === $key
                                        ? 'selected' : '' ?>>
                                    <?= esc($label) ?>
                                </option>
                            <?php endforeach; ?>

                        </select>

                        <div class="form-text">
                            Update the task as cleaning progresses.
                        </div>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold">
                            Cleaning Notes
                        </label>

                        <textarea name="notes"
                                  class="form-control"
                                  rows="6"
                                  placeholder="Record cleaning instructions, guest requests, supplies needed, damages found and work completed..."><?= esc($value('notes')) ?></textarea>

                        <div class="form-text mt-2">
                            Include anything the next team member
                            or supervisor should know.
                        </div>
                    </div>

                </div>

            </div>
        </div>

        <!-- Verification -->
        <div class="card border-0 shadow-sm mb-4" id="verificationSection">
            <div class="card-body p-4">
                <h5 class="fw-bold mb-3">Cleaning Verification</h5>
                <p class="text-muted mb-3">Only an authorized staff member can verify a completed task. The system records the logged-in verifier automatically.</p>
                <?php if ($isEdit): ?>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="rounded-3 p-3 bg-light">
                                <div class="small text-muted">Completed At</div>
                                <div class="fw-semibold"><?= !empty($task['completed_at']) ? esc(date('d M Y, h:i A', strtotime($task['completed_at']))) : 'Not completed' ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="rounded-3 p-3 bg-light">
                                <div class="small text-muted">Verified At</div>
                                <div class="fw-semibold"><?= !empty($task['verified_at']) ? esc(date('d M Y, h:i A', strtotime($task['verified_at']))) : 'Not verified' ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="rounded-3 p-3 bg-light">
                                <div class="small text-muted">Verifier Staff ID</div>
                                <div class="fw-semibold"><?= !empty($task['verified_by_staff_id']) ? (int) $task['verified_by_staff_id'] : 'Not verified' ?></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
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
                            ? 'Update Cleaning Task'
                            : 'Save Cleaning Task' ?>

                    </button>

                    <?php if (haven_can_access(site_url('housekeeping'), 'GET')): ?><a href="<?= site_url('housekeeping') ?>"
                       class="btn btn-outline-secondary px-4">
                        Cancel
                    </a><?php endif; ?>

                </div>

            </div>
        </div>

    </form>

</div>

@endsection


@section('scripts')

<script>
document.addEventListener('DOMContentLoaded', function () {

    const unitSelect = document.getElementById('unit_id');
    const reservationSelect = document.getElementById('reservation_id');

    const bedroomSelect = document.getElementById('bedrooms_cleaned');
    const standardRate = document.getElementById('standardCleaningRate');
    const overrideInput = document.getElementById('cleaning_charge_override');

    function updateBedroomOptions() {
        const unitOption = unitSelect.options[unitSelect.selectedIndex];
        const capacity = Number(unitOption?.dataset.bedrooms || 0);
        let valid = false;
        Array.from(bedroomSelect.options).forEach(option => {
            if (!option.value) return;
            const allowed = Number(option.value) <= capacity;
            option.hidden = !allowed;
            option.disabled = !allowed;
            if (option.selected && allowed) valid = true;
        });
        if (!valid) bedroomSelect.value = '';
        updateRate();
    }

    function updateRate() {
        const option = bedroomSelect.options[bedroomSelect.selectedIndex];
        const rate = option?.dataset.rate;
        standardRate.textContent = rate === undefined
            ? 'Select bedrooms'
            : 'KES ' + Number(rate).toLocaleString('en-KE', {minimumFractionDigits:2, maximumFractionDigits:2});
        overrideInput.disabled = !bedroomSelect.value;
    }

    // Only show reservations belonging to the selected apartment.
    function filterReservations() {
        const selectedUnit = unitSelect.value;
        const selectedReservation = reservationSelect.value;
        let selectionAvailable = false;
        Array.from(reservationSelect.options).forEach(function (option) {
            if (!option.value) {
                option.hidden = false;
                return;
            }
            const matches = selectedUnit !== '' && option.dataset.unit === selectedUnit;
            option.hidden = !matches;
            option.disabled = !matches;
            if (matches && option.value === selectedReservation) selectionAvailable = true;
        });
        if (!selectionAvailable) reservationSelect.value = '';
    }

    unitSelect.addEventListener('change', function () {
        filterReservations();
        updateBedroomOptions();
    });
    bedroomSelect.addEventListener('change', updateRate);
    filterReservations();
    updateBedroomOptions();
});
</script>

@endsection
