@extends('layouts/main')

@section('content')

<?php
$unitList = $units ?? [];

$leadDefaults = isset($salesLead) ? ['guest_name' => $salesLead->name, 'guest_phone' => $salesLead->phone, 'guest_email' => $salesLead->email, 'check_in' => $salesLead->check_in, 'check_out' => $salesLead->check_out, 'adults' => $salesLead->guests, 'notes' => 'Sales enquiry #'.$salesLead->id] : [];
$value = static function ($field, $default = '') use ($leadDefaults) {
    return old($field, $leadDefaults[$field] ?? $default);
};

$selectedUnit = (string) $value('unit_id');
$partnerList = $partners ?? [];
$bookingType = (string) $value('booking_type', 'managed');
$partnerChoice = (string) $value('partner_choice', 'existing');
?>

<div style="max-width:1100px">
    @if(isset($salesLead))
        <div class="alert alert-info">Guest details copied from enquiry #{{ $salesLead->id }}. After saving, return to <a href="{{ route('sales.show',[app('currentTenant')->slug,$salesLead->id]) }}">the enquiry</a> to link the reservation and verify the deposit.</div>
    @endif

    <div class="mb-4">
        <?php if (haven_can_access(site_url('reservations'), 'GET')): ?><a href="<?= site_url('reservations') ?>"
           class="text-decoration-none">
            &larr; Back to Reservations
        </a><?php endif; ?>
    </div>

    <div class="mb-4">
        <h3 class="fw-bold mb-1">Create New Reservation</h3>
        <p class="text-muted mb-0">
            Register a guest booking and record the reservation details.
        </p>
    </div>

    <?php if (haven_can_access(site_url('reservations/store'), 'POST')): ?><form method="post"
          action="<?= site_url('reservations/store') ?>"
          id="reservationForm">

        <?= csrf_field() ?>

        <!-- Apartment Selection -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">

                <h5 class="fw-bold mb-4">
                    <i data-lucide="building-2"
                       class="me-2"
                       style="width:20px;height:20px"></i>
                    Apartment Selection
                </h5>

                <div class="mb-4">
                    <label class="form-label fw-semibold">Booking Type</label>
                    <div class="d-flex gap-4 flex-wrap">
                        <label><input type="radio" name="booking_type" value="managed" <?= $bookingType === 'managed' ? 'checked' : '' ?>> Haven Suites Managed Apartment</label>
                        <label><input type="radio" name="booking_type" value="partner" <?= $bookingType === 'partner' ? 'checked' : '' ?>> Partner / Agent Apartment</label>
                    </div>
                </div>
                <div id="partnerApartmentSection" class="mb-4" hidden>
                    <label class="form-label fw-semibold">Partner Apartment</label>
                    <div class="d-flex gap-4 flex-wrap mb-3">
                        <label><input type="radio" name="partner_choice" value="existing" <?= $partnerChoice === 'existing' ? 'checked' : '' ?>> Existing Partner</label>
                        <label><input type="radio" name="partner_choice" value="new" <?= $partnerChoice === 'new' ? 'checked' : '' ?>> + Add New Partner</label>
                    </div>
                    <div id="existingPartnerSection">
                        <select class="form-select" name="partner_apartment_id" id="partner_apartment_id">
                            <option value="">Choose partner apartment</option>
                            <?php foreach ($partnerList as $partner): ?>
                            <option value="<?= esc($partner['id'], 'attr') ?>" <?= (string) $value('partner_apartment_id') === (string) $partner['id'] ? 'selected' : '' ?>>
                                <?= esc($partner['apartment_name'] . ' – ' . $partner['location'] . ' (' . $partner['owner_name'] . ')') ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="newPartnerSection" class="row g-3" hidden>
                        <?php
                        $partnerFields = [
                            ['partner_owner_name', 'Host / Owner Name', true],
                            ['partner_owner_phone', 'Host Phone', false],
                            ['partner_owner_email', 'Host Email', false],
                            ['partner_apartment_name', 'Apartment Name', true],
                            ['partner_building_name', 'Building Name', false],
                            ['partner_unit_number', 'Unit Number', false],
                            ['partner_location', 'Location', true],
                        ];
                        foreach ($partnerFields as [$field, $label, $required]): ?>
                        <div class="col-md-6">
                            <label class="form-label"><?= esc($label) ?><?= $required ? ' *' : '' ?></label>
                            <input class="form-control partner-new-field" type="<?= $field === 'partner_owner_email' ? 'email' : 'text' ?>"
                                name="<?= esc($field, 'attr') ?>" value="<?= esc($value($field), 'attr') ?>"
                                maxlength="<?= $field === 'partner_unit_number' ? 50 : ($field === 'partner_owner_phone' ? 40 : 150) ?>"
                                data-required="<?= $required ? '1' : '0' ?>">
                        </div>
                        <?php endforeach; ?>
                        <div class="col-md-3">
                            <label class="form-label">Bedrooms *</label>
                            <input class="form-control partner-new-field" type="number" min="0" max="30" name="partner_bedrooms" data-required="1" value="<?= esc($value('partner_bedrooms', 1), 'attr') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Bathrooms</label>
                            <input class="form-control partner-new-field" type="number" min="0" max="30" name="partner_bathrooms" value="<?= esc($value('partner_bathrooms'), 'attr') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Partner Notes</label>
                            <textarea class="form-control partner-new-field" name="partner_notes" rows="2"><?= esc($value('partner_notes')) ?></textarea>
                        </div>
                    </div>
                </div>
                <div id="managedApartmentSection">
                <div class="row g-4">

                    <div class="col-md-8">
                        <label class="form-label fw-semibold">
                            Select Apartment
                            <span class="text-danger">*</span>
                        </label>

                        <select name="unit_id"
                                id="unit_id"
                                class="form-select"
                                required>

                            <option value="">Choose an Apartment</option>

                            <?php foreach ($unitList as $unit): ?>

                                <option
                                    value="<?= esc($unit['id'], 'attr') ?>"
                                    data-rate="<?= esc($unit['nightly_rate'], 'attr') ?>"
                                    <?= $selectedUnit === (string) $unit['id']
                                        ? 'selected' : '' ?>>

                                    <?= esc($unit['property_name'] ?? 'Property') ?>
                                    -
                                    <?= esc($unit['unit_number']) ?>
                                    (KES <?= number_format(
                                        (float) $unit['nightly_rate'],
                                        0
                                    ) ?>/night)

                                </option>

                            <?php endforeach; ?>

                        </select>

                        <div class="form-text">
                            Only active apartments are displayed.
                            The system will check for overlapping confirmed
                            reservations when you save.
                        </div>
                    </div>



                </div>
                </div><!-- managedApartmentSection -->
                <div class="row g-3 mt-1">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">
                            Booking Source
                        </label>

                        <select name="booking_source"
                                class="form-select">

                            <?php
                            $sources = [
                                'Direct',
                                'Airbnb',
                                'Booking.com',
                                'WhatsApp',
                                'Agent',
                                'Referral',
                                'Other'
                            ];
                            ?>

                            <?php foreach ($sources as $source): ?>
                                <option value="<?= esc($source) ?>"
                                    <?= $value('booking_source', 'Direct') === $source
                                        ? 'selected' : '' ?>>
                                    <?= esc($source) ?>
                                </option>
                            <?php endforeach; ?>

                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Guest Details -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">

                <h5 class="fw-bold mb-4">
                    <i data-lucide="users"
                       class="me-2"
                       style="width:20px;height:20px"></i>
                    Guest Information
                </h5>

                <div class="row g-4">

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Guest Full Name
                            <span class="text-danger">*</span>
                        </label>

                        <input type="text"
                               name="guest_name"
                               class="form-control"
                               required
                               maxlength="150"
                               placeholder="Enter guest name"
                               value="<?= esc($value('guest_name')) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Guest Phone
                            <span class="text-danger">*</span>
                        </label>

                        <input type="tel"
                               name="guest_phone"
                               class="form-control"
                               required
                               maxlength="40"
                               placeholder="+254 7XX XXX XXX"
                               value="<?= esc($value('guest_phone')) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Guest Email
                        </label>

                        <input type="email"
                               name="guest_email"
                               class="form-control"
                               maxlength="150"
                               placeholder="guest@example.com"
                               value="<?= esc($value('guest_email')) ?>">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-semibold">
                            Adults
                        </label>

                        <input type="number"
                               name="adults"
                               class="form-control"
                               min="1"
                               max="50"
                               required
                               value="<?= esc($value('adults', 1)) ?>">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-semibold">
                            Children
                        </label>

                        <input type="number"
                               name="children"
                               class="form-control"
                               min="0"
                               max="50"
                               required
                               value="<?= esc($value('children', 0)) ?>">
                    </div>

                </div>
            </div>
        </div>

        <!-- Stay Details -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">

                <h5 class="fw-bold mb-4">
                    <i data-lucide="calendar-days"
                       class="me-2"
                       style="width:20px;height:20px"></i>
                    Stay Information
                </h5>

                <div class="row g-4">

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Check-in Date
                            <span class="text-danger">*</span>
                        </label>

                        <input type="date"
                               name="check_in"
                               id="check_in"
                               class="form-control"
                               required
                               value="<?= esc($value('check_in')) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Check-out Date
                            <span class="text-danger">*</span>
                        </label>

                        <input type="date"
                               name="check_out"
                               id="check_out"
                               class="form-control"
                               required
                               value="<?= esc($value('check_out')) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Reservation Status
                        </label>

                        <select name="status"
                                class="form-select">

                            <?php
                            $statuses = [
                                'pending' => 'Pending',
                                'confirmed' => 'Confirmed',
                                'checked_in' => 'Checked In',
                                'checked_out' => 'Checked Out',
                                'cancelled' => 'Cancelled'
                            ];
                            ?>

                            <?php foreach ($statuses as $key => $label): ?>
                                <option value="<?= esc($key) ?>"
                                    <?= $value('status', 'pending') === $key
                                        ? 'selected' : '' ?>>
                                    <?= esc($label) ?>
                                </option>
                            <?php endforeach; ?>

                        </select>
                    </div>

                </div>

            </div>
        </div>

        <!-- Payment Details -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">

                <h5 class="fw-bold mb-4">
                    <i data-lucide="wallet"
                       class="me-2"
                       style="width:20px;height:20px"></i>
                    Booking & Payment Details
                </h5>

                <div class="row g-4">

                    <div class="col-md-6" id="managedRateSection">
                        <label class="form-label fw-semibold">
                            Nightly Rate (KES)
                        </label>

                        <div class="input-group">
                            <span class="input-group-text">KES</span>

                            <input type="number"
                                   name="nightly_rate"
                                   id="nightly_rate"
                                   class="form-control"
                                   min="0"
                                   step="0.01"
                                   placeholder="Automatically populated"
                                   value="<?= esc($value('nightly_rate')) ?>">
                        </div>

                        <div class="form-text">
                            The default apartment rate is automatically
                            selected. You can change it for a negotiated booking.
                        </div>
                    </div>

                    <div class="col-md-6" id="partnerGuestPriceSection" hidden>
                        <label class="form-label fw-semibold">Guest Selling Rate — Per Night (KES) *</label>
                        <input type="number" name="guest_total" id="guest_total" class="form-control" min="0.01" step="0.01" value="<?= esc($value('guest_total'), 'attr') ?>">
                    </div>
                    <div class="col-md-6" id="partnerOwnerNetSection" hidden>
                        <label class="form-label fw-semibold">Owner's Agreed Net — Per Night (KES) *</label>
                        <input type="number" name="owner_net_amount" id="owner_net_amount" class="form-control" min="0" step="0.01" value="<?= esc($value('owner_net_amount'), 'attr') ?>">
                        <div class="form-text">Internal only. The owner total and Haven Suites markup are calculated using the number of nights.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Amount Paid (KES)
                        </label>

                        <div class="input-group">
                            <span class="input-group-text">KES</span>

                            <input type="number"
                                   name="amount_paid"
                                   id="amount_paid"
                                   class="form-control"
                                   min="0"
                                   step="0.01"
                                   value="<?= esc($value('amount_paid', 0)) ?>">
                        </div>
                    </div>

                    <div class="col-12">

                        <div class="rounded-3 p-4"
                             style="background:#f1f5fb">

                            <div class="row g-3">

                                <div class="col-md-4">
                                    <div class="text-muted small">
                                        Total Nights
                                    </div>

                                    <h4 class="fw-bold mb-0"
                                        id="totalNights">
                                        0
                                    </h4>
                                </div>

                                <div class="col-md-4">
                                    <div class="text-muted small">
                                        Booking Total
                                    </div>

                                    <h4 class="fw-bold mb-0"
                                        id="bookingTotal">
                                        KES 0
                                    </h4>
                                </div>

                                <div class="col-md-4">
                                    <div class="text-muted small">
                                        Balance Due
                                    </div>

                                    <h4 class="fw-bold mb-0"
                                        id="balanceDue">
                                        KES 0
                                    </h4>
                                </div>

                            </div>
                            <div id="partnerMarkupSummary" class="mt-3 fw-semibold" hidden>
                                <div>Owner Net Total: <span id="partnerOwnerTotal">KES 0</span></div>
                                <div>Markup Per Night: <span id="partnerMarkupNightly">KES 0</span></div>
                                <div>Haven Suites Total Markup: <span id="partnerMarkup">KES 0</span></div>
                            </div>
                        </div>

                        <div class="form-text mt-2">
                            These figures are estimates shown before saving.
                            The server calculates the final reservation total.
                        </div>

                    </div>

                </div>

            </div>
        </div>

        <!-- Notes -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">

                <h5 class="fw-bold mb-4">
                    <i data-lucide="notebook-pen"
                       class="me-2"
                       style="width:20px;height:20px"></i>
                    Additional Information
                </h5>

                <label class="form-label fw-semibold">
                    Reservation Notes
                </label>

                <textarea name="notes"
                          class="form-control"
                          rows="5"
                          placeholder="Arrival time, guest requests, payment arrangements, booking instructions..."><?= esc($value('notes')) ?></textarea>

            </div>
        </div>

        <!-- Form Actions -->
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">

                <div class="d-flex gap-3 flex-wrap">

                    <button type="submit"
                            class="btn btn-primary px-4">

                        <i data-lucide="save"
                           class="me-1"
                           style="width:17px;height:17px"></i>

                        Save Reservation
                    </button>

                    <?php if (haven_can_access(site_url('reservations'), 'GET')): ?><a href="<?= site_url('reservations') ?>"
                       class="btn btn-outline-secondary px-4">
                        Cancel
                    </a><?php endif; ?>

                </div>

            </div>
        </div>

    </form><?php endif; ?>

</div>

@endsection


@section('scripts')

<script>
document.addEventListener('DOMContentLoaded', function () {

    const unitSelect = document.getElementById('unit_id');
    const checkIn = document.getElementById('check_in');
    const checkOut = document.getElementById('check_out');
    const rateInput = document.getElementById('nightly_rate');
    const paidInput = document.getElementById('amount_paid');

    const totalNights = document.getElementById('totalNights');
    const bookingTotal = document.getElementById('bookingTotal');
    const balanceDue = document.getElementById('balanceDue');

    const partnerSection = document.getElementById('partnerApartmentSection');
    const managedSection = document.getElementById('managedApartmentSection');
    const existingSection = document.getElementById('existingPartnerSection');
    const newSection = document.getElementById('newPartnerSection');
    const partnerSelect = document.getElementById('partner_apartment_id');
    const guestTotal = document.getElementById('guest_total');
    const ownerNetInput = document.getElementById('owner_net_amount');
    const partnerMarkup = document.getElementById('partnerMarkup');
    const partnerMarkupNightly = document.getElementById('partnerMarkupNightly');
    const partnerOwnerTotal = document.getElementById('partnerOwnerTotal');
    const isPartner = () => document.querySelector('input[name="booking_type"]:checked').value === 'partner';
    function updateBookingType() {
        const partner = isPartner();
        const isNew = document.querySelector('input[name="partner_choice"]:checked').value === 'new';
        partnerSection.hidden = !partner;
        managedSection.hidden = partner;
        document.getElementById('managedRateSection').hidden = partner;
        document.getElementById('partnerGuestPriceSection').hidden = !partner;
        document.getElementById('partnerOwnerNetSection').hidden = !partner;
        document.getElementById('partnerMarkupSummary').hidden = !partner;
        existingSection.hidden = !partner || isNew;
        newSection.hidden = !partner || !isNew;
        unitSelect.required = !partner;
        partnerSelect.required = partner && !isNew;
        guestTotal.required = partner;
        ownerNetInput.required = partner;
        document.querySelectorAll('.partner-new-field').forEach(el => {
            el.required = partner && isNew && el.dataset.required === '1';
        });
        calculateBooking();
    }
    document.querySelectorAll('input[name="booking_type"], input[name="partner_choice"]').forEach(el => {
        el.addEventListener('change', updateBookingType);
    });
    guestTotal.addEventListener('input', calculateBooking);
    ownerNetInput.addEventListener('input', calculateBooking);

    const money = new Intl.NumberFormat('en-KE', {
        style: 'currency',
        currency: 'KES',
        maximumFractionDigits: 2
    });

    function getNights() {

        if (!checkIn.value || !checkOut.value) {
            return 0;
        }

        const start = Date.parse(checkIn.value + 'T00:00:00Z');
        const end = Date.parse(checkOut.value + 'T00:00:00Z');

        if (!Number.isFinite(start) || !Number.isFinite(end)) {
            return 0;
        }

        return Math.max(
            0,
            Math.round((end - start) / 86400000)
        );
    }

    function calculateBooking() {

        const nights = getNights();
        const rate = Math.max(
            0,
            parseFloat(rateInput.value) || 0
        );

        const paid = Math.max(
            0,
            parseFloat(paidInput.value) || 0
        );

        const guestNightly = Math.max(0, parseFloat(guestTotal.value) || 0);
        const ownerNightly = Math.max(0, parseFloat(ownerNetInput.value) || 0);
        const total = nights * (isPartner() ? guestNightly : rate);
        const balance = Math.max(0, total - paid);

        totalNights.textContent = nights;
        bookingTotal.textContent = money.format(total);
        balanceDue.textContent = money.format(balance);
        partnerOwnerTotal.textContent = money.format(nights * ownerNightly);
        partnerMarkupNightly.textContent = money.format(guestNightly - ownerNightly);
        partnerMarkup.textContent = money.format(nights * (guestNightly - ownerNightly));
    }

    function setApartmentRate() {

        const selected = unitSelect.selectedOptions[0];

        if (selected && selected.dataset.rate) {
            rateInput.value = selected.dataset.rate;
        } else {
            rateInput.value = '';
        }

        calculateBooking();
    }

    unitSelect.addEventListener('change', setApartmentRate);
    checkIn.addEventListener('change', calculateBooking);
    checkOut.addEventListener('change', calculateBooking);
    rateInput.addEventListener('input', calculateBooking);
    paidInput.addEventListener('input', calculateBooking);

    // Preserve entered rates when returning after a validation error.
    if (!rateInput.value && unitSelect.value) {
        setApartmentRate();
    }

    updateBookingType();

});
</script>

@endsection
