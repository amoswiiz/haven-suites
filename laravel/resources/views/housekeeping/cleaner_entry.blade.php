@extends('layouts/main')

@section('styles')
<style>
.hs-cleaner-entry{--navy:#294780;--orange:#e98d50;max-width:1000px;margin:auto}
.hs-cleaner-entry .hs-panel{background:#fff;border:1px solid #e5eaf2;border-radius:16px;box-shadow:0 4px 18px rgba(25,48,90,.05)}
.hs-cleaner-entry .hs-title{color:var(--navy);font-weight:750}
.hs-cleaner-entry .hs-primary{background:var(--navy);border:1px solid var(--navy);color:#fff;font-weight:650}
.hs-cleaner-entry .hs-primary:hover{background:#203967;color:#fff}
.hs-cleaner-entry .hs-fee{background:#f1f5fb;border-left:4px solid var(--orange);border-radius:10px;padding:18px}
.hs-cleaner-entry .form-label{font-weight:650;color:#354761}
.hs-cleaner-entry .form-control:focus,.hs-cleaner-entry .form-select:focus{border-color:var(--orange);box-shadow:0 0 0 .2rem rgba(233,141,80,.15)}
</style>
@endsection

@section('content')
<?php
$unitOptions = [];
foreach (($units ?? []) as $unit) {
    $id = (int) ($unit['id'] ?? 0);
    $bedrooms = (int) ($unit['bedrooms'] ?? 0);
    if ($id <= 0 || $bedrooms <= 0) continue;
    $property = trim((string) ($unit['property_name'] ?? ''));
    $number = trim((string) ($unit['unit_number'] ?? ''));
    $unitOptions[] = [
        'id' => $id,
        'bedrooms' => $bedrooms,
        'label' => trim(($property !== '' ? $property : 'Property') . ' — ' . ($number !== '' ? $number : 'Unit #' . $id)),
    ];
}
// Rate options are used for a fee preview only. The controller recalculates on submission.
$rateMap = [];
foreach (($cleaningRates ?? []) as $key => $rate) {
    if (is_array($rate)) {
        $bedrooms = $rate['bedrooms'] ?? $rate['bedroom_count'] ?? $rate['bedrooms_cleaned'] ?? (is_numeric($key) ? $key : null);
        $amount = $rate['rate'] ?? $rate['amount'] ?? $rate['cleaning_rate'] ?? $rate['price'] ?? null;
    } else {
        $bedrooms = $key;
        $amount = $rate;
    }
    if (is_numeric($bedrooms) && (int) $bedrooms > 0 && is_numeric($amount)) {
        $rateMap[(int) $bedrooms] = (float) $amount;
    }
}
$oldUnit = (string) (old('unit_id') ?? '');
$oldBedrooms = (string) (old('bedrooms_cleaned') ?? '');
$isManagerEntry = !empty($canManageCleanerEntry);
$oldCleaner = (string) (old('assigned_staff_id') ?? '');
?>
<div class="hs-cleaner-entry">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
        <div>
            <?php if (haven_can_access(site_url('housekeeping'), 'GET')): ?><a href="<?= site_url('housekeeping') ?>" class="text-decoration-none small">&larr; My Cleaning Tasks</a><?php endif; ?>
            <h3 class="hs-title mt-2 mb-1">Record a Completed Cleaning</h3>
            <p class="text-muted mb-0">HAVEN SUITES · BNBs and STR Homes</p>
        </div>
    </div>

    <?php if ($message = haven_session()->getFlashdata('error')): ?>
        <div class="alert alert-danger" role="alert"><?= esc($message) ?></div>
    <?php endif; ?>
    <?php if ($message = haven_session()->getFlashdata('success')): ?>
        <div class="alert alert-success" role="alert"><?= esc($message) ?></div>
    <?php endif; ?>

    <div class="hs-panel p-4 p-md-5">
        <p class="text-muted mb-4">Select the apartment you cleaned. Your cleaning fee is determined by the configured rate for the number of bedrooms actually cleaned. Your submission will await management verification.</p>
        <?php if (haven_can_access(site_url('housekeeping/my-cleanings'), 'POST')): ?><form method="post" action="<?= site_url('housekeeping/my-cleanings') ?>" id="hs-cleaning-entry-form">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label" for="hs-unit">Apartment / House <span class="text-danger">*</span></label>
                    <select class="form-select" id="hs-unit" name="unit_id" required>
                        <option value="">Select an apartment</option>
                        <?php foreach ($unitOptions as $unit): ?>
                            <option value="<?= $unit['id'] ?>" data-bedrooms="<?= $unit['bedrooms'] ?>" <?= $oldUnit === (string) $unit['id'] ? 'selected' : '' ?>><?= esc($unit['label']) ?> (<?= $unit['bedrooms'] ?> BR)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($isManagerEntry): ?>
                <div class="col-md-6">
                    <label class="form-label" for="hs-cleaner">Cleaner Who Performed the Work <span class="text-danger">*</span></label>
                    <select class="form-select" id="hs-cleaner" name="assigned_staff_id" required>
                        <option value="">Select cleaner</option>
                        <?php foreach (($cleanerStaff ?? []) as $member): ?>
                            <?php $memberId = (int) ($member['id'] ?? 0); if ($memberId <= 0) continue; ?>
                            <option value="<?= $memberId ?>" <?= $oldCleaner === (string) $memberId ? 'selected' : '' ?>><?= esc($member['full_name'] ?? ('Staff #' . $memberId)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-6">
                    <label class="form-label" for="hs-bedrooms">Bedrooms Actually Cleaned <span class="text-danger">*</span></label>
                    <select class="form-select" id="hs-bedrooms" name="bedrooms_cleaned" data-old-value="<?= esc($oldBedrooms, 'attr') ?>" required disabled>
                        <option value="">Select an apartment first</option>
                    </select>
                    <div class="form-text" id="hs-bedroom-hint">For a 2BR apartment, select 1 or 2 bedrooms.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="hs-date">Cleaning Date <span class="text-danger">*</span></label>
                    <input class="form-control" id="hs-date" name="cleaning_date" type="date" max="<?= esc($today ?? date('Y-m-d'), 'attr') ?>" value="<?= esc(old('cleaning_date') ?? ($today ?? date('Y-m-d')), 'attr') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="hs-type">Cleaning Type <span class="text-danger">*</span></label>
                    <?php $type = old('cleaning_type') ?? 'checkout'; ?>
                    <select class="form-select" id="hs-type" name="cleaning_type" required>
                        <?php foreach (['checkout'=>'Checkout Cleaning','pre_checkin'=>'Pre-Check-in Cleaning','routine'=>'Routine Cleaning','deep'=>'Deep Cleaning'] as $value=>$label): ?>
                            <option value="<?= esc($value, 'attr') ?>" <?= $type === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="hs-booking">Booking / Reservation Number (optional)</label>
                    <input class="form-control" id="hs-booking" name="reservation_id" type="number" min="1" step="1" value="<?= esc(old('reservation_id') ?? '', 'attr') ?>" placeholder="e.g. 104">
                    <div class="form-text">Only use a reservation number that belongs to the selected apartment.</div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="hs-repeat">Reason for Additional Cleaning</label>
                    <textarea class="form-control" id="hs-repeat" name="repeat_reason" rows="2" maxlength="1000" placeholder="Required when recording another cleaning for the same apartment on the same day without a booking number."><?= esc(old('repeat_reason') ?? '') ?></textarea>
                    <div class="form-text">Multiple cleanings on the same day are allowed. For the second or subsequent cleaning, provide a booking number or a reason.</div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="hs-notes">Cleaning Notes (optional)</label>
                    <textarea class="form-control" id="hs-notes" name="notes" rows="3" maxlength="5000" placeholder="Supplies needed, maintenance issues, or other observations"><?= esc(old('notes') ?? '') ?></textarea>
                </div>
                <div class="col-12">
                    <div class="hs-fee" aria-live="polite">
                        <div class="text-muted small fw-semibold">Estimated Cleaning Fee</div>
                        <div id="hs-fee-amount" class="hs-title fs-3">Select an apartment</div>
                        <div class="small text-muted" id="hs-fee-note">The final amount is calculated securely when you submit.</div>
                    </div>
                </div>
                <div class="col-12 d-flex gap-2 flex-wrap mt-4">
                    <button type="submit" class="btn hs-primary px-4" <?= empty($unitOptions) ? 'disabled' : '' ?>>Submit Completed Cleaning</button>
                    <?php if (haven_can_access(site_url('housekeeping'), 'GET')): ?><a href="<?= site_url('housekeeping') ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
                </div>
            </div>
        </form><?php endif; ?>
        <?php if (empty($unitOptions)): ?>
            <div class="alert alert-warning mt-3 mb-0">No apartments with valid bedroom counts are available. Ask management to configure the units first.</div>
        <?php endif; ?>
    </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const rates = <?= json_encode($rateMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const unit = document.getElementById('hs-unit');
    const bedroomsSelect = document.getElementById('hs-bedrooms');
    const bedroomHint = document.getElementById('hs-bedroom-hint');
    const amount = document.getElementById('hs-fee-amount');
    const note = document.getElementById('hs-fee-note');
    function refreshFee() {
        const bedrooms = bedroomsSelect.value;
        if (!bedrooms) {
            amount.textContent = 'Select bedrooms cleaned';
            note.textContent = 'Choose how many bedrooms were actually cleaned.';
        } else if (Object.prototype.hasOwnProperty.call(rates, bedrooms)) {
            amount.textContent = 'KES ' + Number(rates[bedrooms]).toLocaleString('en-KE', {minimumFractionDigits:2,maximumFractionDigits:2});
            note.textContent = bedrooms + ' bedroom(s) cleaned. Management must verify the cleaning.';
        } else {
            amount.textContent = 'Rate not configured';
            note.textContent = 'Ask management to configure the rate for ' + bedrooms + ' bedroom(s).';
        }
    }
    function refresh() {
        const selected = unit.options[unit.selectedIndex];
        const capacity = selected ? Number(selected.getAttribute('data-bedrooms')) : 0;
        const previous = bedroomsSelect.value || bedroomsSelect.dataset.oldValue || '';
        bedroomsSelect.innerHTML = '';
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = capacity > 0 ? 'Select bedrooms cleaned' : 'Select an apartment first';
        bedroomsSelect.appendChild(placeholder);
        bedroomsSelect.disabled = !(capacity > 0);
        if (capacity > 0) {
            for (let count = 1; count <= capacity; count++) {
                const option = document.createElement('option');
                option.value = String(count);
                option.textContent = count + (count === 1 ? ' Bedroom' : ' Bedrooms');
                bedroomsSelect.appendChild(option);
            }
            if (Number(previous) >= 1 && Number(previous) <= capacity && Number.isInteger(Number(previous))) {
                bedroomsSelect.value = previous;
            }
            bedroomHint.textContent = 'This apartment has ' + capacity + ' bedroom(s). Select the number actually cleaned.';
        } else {
            bedroomHint.textContent = 'Select an apartment to see the available bedroom counts.';
        }
        bedroomsSelect.dataset.oldValue = '';
        refreshFee();
    }
    unit.addEventListener('change', refresh);
    bedroomsSelect.addEventListener('change', refreshFee);
    refresh();
});
</script>
@endsection
