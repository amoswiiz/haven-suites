@extends('layouts/main')

@section('styles')

<style>
.availability-calendar {
    overflow-x: auto;
}

.availability-table {
    min-width: 1100px;
    border-collapse: separate;
    border-spacing: 0;
}

.availability-table th,
.availability-table td {
    border-bottom: 1px solid #e9edf3;
    border-right: 1px solid #e9edf3;
    text-align: center;
    padding: 9px 5px;
    min-width: 37px;
    height: 51px;
    font-size: 12px;
}

.availability-table thead th {
    background: #f4f7fb;
    font-weight: 600;
    white-space: nowrap;
}

.availability-table .unit-column {
    position: sticky;
    left: 0;
    z-index: 2;
    min-width: 230px;
    max-width: 230px;
    text-align: left;
    padding: 12px;
    background: white;
    box-shadow: 2px 0 4px rgba(0,0,0,.04);
}

.availability-table thead .unit-column {
    z-index: 3;
    background: #f4f7fb;
}

.availability-table .available {
    background: #eaf8ef;
    color: #217a44;
}

.availability-table .booked {
    background: #fce8e8;
    color: #b42318;
    cursor: pointer;
}

.availability-table .maintenance {
    background: #fff3d8;
    color: #9a6700;
}

.availability-table .inactive {
    background: #edf0f3;
    color: #667085;
}

.availability-table .today {
    box-shadow: inset 0 0 0 2px #2563eb;
}

.availability-table .weekend-header {
    background: #e8edf6;
}

.availability-table .booked:hover {
    background: #f8c9c9;
}

.availability-legend {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-right: 18px;
    margin-bottom: 8px;
    font-size: 13px;
}

.availability-dot {
    display: inline-block;
    width: 13px;
    height: 13px;
    border-radius: 4px;
}

.availability-table .day-number {
    font-weight: 700;
    font-size: 12px;
}

.availability-table .day-name {
    color: #667085;
    font-size: 10px;
}

.availability-table .unit-property {
    font-size: 11px;
    color: #667085;
}

/* Haven Suites calendar branding — presentation only */
.hs-availability-page .hs-page-heading { color: #1e3052; letter-spacing: -.025em; }
.hs-availability-page .hs-calendar-card { border: 1px solid #e6ebf2 !important; border-radius: 15px; overflow: hidden; }
.hs-availability-page .hs-calendar-card > .card-body { padding: 23px; }
.hs-availability-page .hs-calendar-title { color: #1e3052; }
.hs-availability-page .hs-calendar-primary { background: #30477e; border-color: #30477e; color: #fff; font-weight: 600; }
.hs-availability-page .hs-calendar-primary:hover,
.hs-availability-page .hs-calendar-primary:focus { background: #22365f; border-color: #22365f; color: #fff; }
.hs-availability-page .hs-calendar-nav { border-color: #cdd7e6; color: #30477e; }
.hs-availability-page .hs-calendar-nav:hover { background: #eef2fa; color: #22365f; }
.hs-availability-page .availability-table { border: 1px solid #e1e7f0; border-radius: 8px; }
.hs-availability-page .availability-table thead th { background: #edf1f7; color: #30477e; border-bottom: 2px solid #d4ddea; }
.hs-availability-page .availability-table thead .unit-column { background: #edf1f7; }
.hs-availability-page .availability-table .weekend-header { background: #e4eaf5; }
.hs-availability-page .availability-table .today { box-shadow: inset 0 0 0 2px #e58b4d; }
.hs-availability-page .availability-table tbody tr:hover .unit-column { background: #f7f9fc; }
.hs-availability-page .availability-legend { color: #465572; }
.hs-availability-page .hs-calendar-help { border-left: 3px solid #e58b4d; padding-left: 10px; }
.hs-availability-page #bookingDetails .modal-header { border-bottom: 2px solid #e58b4d; }
.hs-availability-page #bookingDetails .modal-title { color: #30477e; font-weight: 700; }
@media (max-width: 767px) {
    .hs-availability-page .hs-calendar-card > .card-body { padding: 15px; }
    .hs-availability-page .availability-table .unit-column { min-width: 170px; max-width: 170px; }
}
</style>

@endsection


@section('content')

<?php
$daysInMonth = (int) $month->format('t');

$previousMonth = $month->modify('-1 month')->format('Y-m');
$nextMonth = $month->modify('+1 month')->format('Y-m');

$currentMonth = $month->format('Y-m');

$today = date('Y-m-d');

$unitCount = count($units);

$bookedNights = 0;
$availableNights = 0;
$maintenanceNights = 0;

$calendar = [];

foreach ($units as $unit) {

    $id = (int) $unit['id'];

    for ($day = 1; $day <= $daysInMonth; $day++) {

        $date = $month->setDate(
            (int) $month->format('Y'),
            (int) $month->format('m'),
            $day
        )->format('Y-m-d');

        $cell = [
            'status' => 'available',
            'reservation' => null
        ];

        if ($unit['status'] === 'maintenance') {

            $cell['status'] = 'maintenance';
            $maintenanceNights++;

        } elseif ($unit['status'] === 'inactive') {

            $cell['status'] = 'inactive';

        } else {

            foreach ($bookingsByUnit[$id] ?? [] as $reservation) {

                if (
                    $reservation['check_in'] <= $date &&
                    $reservation['check_out'] > $date
                ) {
                    $cell['status'] = 'booked';
                    $cell['reservation'] = $reservation;
                    break;
                }
            }

            if ($cell['status'] === 'booked') {
                $bookedNights++;
            } else {
                $availableNights++;
            }
        }

        $calendar[$id][$day] = $cell;
    }
}

$filterParams = $selectedUnitId > 0
    ? ['unit_id' => $selectedUnitId]
    : [];

$previousUrl = site_url('availability') . '?' . http_build_query(
    array_merge($filterParams, ['month' => $previousMonth])
);

$nextUrl = site_url('availability') . '?' . http_build_query(
    array_merge($filterParams, ['month' => $nextMonth])
);
?>

<div class="hs-availability-page">
<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">

    <div>
        <h3 class="fw-bold mb-1 hs-page-heading">Availability Calendar</h3>

        <p class="text-muted mb-0">
            Monitor apartment availability and guest reservations.
        </p>
    </div>

    <?php if (haven_can_access(site_url('reservations/create'), 'GET')): ?><a href="<?= site_url('reservations/create') ?>"
       class="btn btn-primary">
        + New Reservation
    </a><?php endif; ?>

</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">

    <?php
    $stats = [
        ['Apartments', $unitCount, 'building-2'],
        ['Booked Nights', $bookedNights, 'calendar-check'],
        ['Available Nights', $availableNights, 'calendar-days'],
        ['Maintenance Nights', $maintenanceNights, 'wrench']
    ];
    ?>

    <?php foreach ($stats as [$label, $value, $icon]): ?>

        <div class="col-xl-3 col-md-6">
            <div class="hs-stat-card">

                <div class="d-flex justify-content-between align-items-start">

                    <div>
                        <div class="hs-stat-label">
                            <?= esc($label) ?>
                        </div>

                        <div class="hs-stat-value">
                            <?= number_format($value) ?>
                        </div>
                    </div>

                    <div class="hs-stat-icon">
                        <i data-lucide="<?= esc($icon) ?>"></i>
                    </div>

                </div>

            </div>
        </div>

    <?php endforeach; ?>

</div>

<!-- Calendar -->
<div class="card border-0 shadow-sm hs-calendar-card">

    <div class="card-body">

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">

            <div>
                <h5 class="fw-bold mb-1">
                    <?= esc($month->format('F Y')) ?>
                </h5>

                <p class="text-muted small mb-0">
                    Monthly apartment occupancy overview.
                </p>
            </div>

            <div class="d-flex align-items-center gap-2 flex-wrap">

                <?php if (haven_can_access(site_url('availability'), 'GET')): ?><form method="get"
                      action="<?= site_url('availability') ?>"
                      class="d-flex gap-2 flex-wrap">

                    <input type="hidden"
                           name="month"
                           value="<?= esc($currentMonth) ?>">

                    <select name="unit_id"
                            class="form-select"
                            style="min-width:210px"
                            onchange="this.form.submit()">

                        <option value="0">All Apartments</option>

                        <?php foreach ($allUnits as $unit): ?>

                            <option
                                value="<?= esc($unit['id']) ?>"
                                <?= (int) $selectedUnitId === (int) $unit['id']
                                    ? 'selected' : '' ?>>

                                <?= esc($unit['property_name'] ?? 'Property') ?>
                                -
                                <?= esc($unit['unit_number']) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                    <noscript>
                        <button type="submit"
                                class="btn btn-outline-secondary hs-calendar-nav">
                            Filter
                        </button>
                    </noscript>

                </form><?php endif; ?>

                <a href="<?= esc($previousUrl) ?>"
                   class="btn btn-outline-secondary hs-calendar-nav"
                   aria-label="Previous month">
                    &larr;
                </a>

                <?php if (haven_can_access(site_url('availability'), 'GET')): ?><a href="<?= site_url('availability') ?>"
                   class="btn btn-outline-secondary hs-calendar-nav">
                    Today
                </a><?php endif; ?>

                <a href="<?= esc($nextUrl) ?>"
                   class="btn btn-outline-secondary hs-calendar-nav"
                   aria-label="Next month">
                    &rarr;
                </a>

            </div>

        </div>

        <!-- Legend -->
        <div class="mb-3">

            <span class="availability-legend">
                <span class="availability-dot"
                      style="background:#eaf8ef"></span>
                Available
            </span>

            <span class="availability-legend">
                <span class="availability-dot"
                      style="background:#fce8e8"></span>
                Booked
            </span>

            <span class="availability-legend">
                <span class="availability-dot"
                      style="background:#fff3d8"></span>
                Maintenance
            </span>

            <span class="availability-legend">
                <span class="availability-dot"
                      style="background:#edf0f3"></span>
                Inactive
            </span>

        </div>

        <div class="availability-calendar">

            <table class="availability-table w-100">

                <thead>
                    <tr>

                        <th class="unit-column">Apartment</th>

                        <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>

                            <?php
                            $dayDate = $month->setDate(
                                (int) $month->format('Y'),
                                (int) $month->format('m'),
                                $day
                            );

                            $isWeekend = in_array(
                                (int) $dayDate->format('N'),
                                [6, 7],
                                true
                            );
                            ?>

                            <th class="<?= $isWeekend
                                ? 'weekend-header'
                                : '' ?>">

                                <div class="day-number">
                                    <?= $day ?>
                                </div>

                                <div class="day-name">
                                    <?= esc($dayDate->format('D')) ?>
                                </div>

                            </th>

                        <?php endfor; ?>

                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($units as $unit): ?>

                        <?php $unitId = (int) $unit['id']; ?>

                        <tr>

                            <td class="unit-column">

                                <div class="fw-semibold">
                                    <?= esc($unit['unit_number']) ?>
                                </div>

                                <div class="unit-property">
                                    <?= esc($unit['property_name'] ?? '-') ?>
                                </div>

                                <div class="small text-muted">
                                    <?= esc(ucfirst($unit['status'])) ?>
                                </div>

                            </td>

                            <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>

                                <?php
                                $cell = $calendar[$unitId][$day];

                                $date = $month->setDate(
                                    (int) $month->format('Y'),
                                    (int) $month->format('m'),
                                    $day
                                )->format('Y-m-d');

                                $reservation = $cell['reservation'];

                                $tooltip = match ($cell['status']) {
                                    'booked' => 'Booked: ' .
                                        ($reservation['guest_name'] ?? ''),
                                    'maintenance' => 'Under maintenance',
                                    'inactive' => 'Inactive apartment',
                                    default => 'Available'
                                };
                                ?>

                                <td
                                    class="<?= esc($cell['status']) ?>
                                        <?= $date === $today ? 'today' : '' ?>"
                                    title="<?= esc($tooltip, 'attr') ?>"

                                    <?php if ($reservation): ?>

                                        data-bs-toggle="modal"
                                        data-bs-target="#bookingDetails"

                                        data-guest="<?= esc(
                                            $reservation['guest_name'],
                                            'attr'
                                        ) ?>"

                                        data-unit="<?= esc(
                                            $unit['unit_number'],
                                            'attr'
                                        ) ?>"

                                        data-property="<?= esc(
                                            $unit['property_name'] ?? '',
                                            'attr'
                                        ) ?>"

                                        data-checkin="<?= esc(
                                            $reservation['check_in'],
                                            'attr'
                                        ) ?>"

                                        data-checkout="<?= esc(
                                            $reservation['check_out'],
                                            'attr'
                                        ) ?>"

                                        data-status="<?= esc(
                                            $reservation['status'],
                                            'attr'
                                        ) ?>"

                                    <?php endif; ?>
                                >

                                    <?php if ($cell['status'] === 'booked'): ?>
                                        <i data-lucide="check"
                                           style="width:13px;height:13px"></i>

                                    <?php elseif ($cell['status'] === 'maintenance'): ?>
                                        <i data-lucide="wrench"
                                           style="width:13px;height:13px"></i>

                                    <?php elseif ($cell['status'] === 'inactive'): ?>
                                        &mdash;

                                    <?php else: ?>
                                        &middot;
                                    <?php endif; ?>

                                </td>

                            <?php endfor; ?>

                        </tr>

                    <?php endforeach; ?>

                    <?php if (empty($units)): ?>

                        <tr>
                            <td colspan="<?= $daysInMonth + 1 ?>"
                                class="text-center text-muted py-5">
                                No apartments found.
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

        <div class="text-muted small mt-3 hs-calendar-help">
            Select a booked date to view reservation details.
            Check-out dates are treated as available for the next guest.
        </div>

    </div>

</div>

<!-- Booking Details Modal -->
<div class="modal fade"
     id="bookingDetails"
     tabindex="-1"
     aria-labelledby="bookingDetailsLabel"
     aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title"
                    id="bookingDetailsLabel">
                    Reservation Details
                </h5>

                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close">
                </button>

            </div>

            <div class="modal-body">

                <dl class="row mb-0">

                    <dt class="col-sm-4">Guest</dt>
                    <dd class="col-sm-8" id="modalGuest"></dd>

                    <dt class="col-sm-4">Property</dt>
                    <dd class="col-sm-8" id="modalProperty"></dd>

                    <dt class="col-sm-4">Apartment</dt>
                    <dd class="col-sm-8" id="modalUnit"></dd>

                    <dt class="col-sm-4">Check-in</dt>
                    <dd class="col-sm-8" id="modalCheckin"></dd>

                    <dt class="col-sm-4">Check-out</dt>
                    <dd class="col-sm-8" id="modalCheckout"></dd>

                    <dt class="col-sm-4">Status</dt>
                    <dd class="col-sm-8" id="modalStatus"></dd>

                </dl>

            </div>

            <div class="modal-footer">
                <button type="button"
                        class="btn btn-secondary"
                        data-bs-dismiss="modal">
                    Close
                </button>
            </div>

        </div>

    </div>

</div>

</div> <!-- /.hs-availability-page -->

@endsection


@section('scripts')

<script>
document.addEventListener('DOMContentLoaded', function () {

    const modal = document.getElementById('bookingDetails');

    if (!modal) {
        return;
    }

    modal.addEventListener('show.bs.modal', function (event) {

        const cell = event.relatedTarget;

        if (!cell) {
            return;
        }

        const values = {
            modalGuest: cell.dataset.guest || '-',
            modalProperty: cell.dataset.property || '-',
            modalUnit: cell.dataset.unit || '-',
            modalCheckin: cell.dataset.checkin || '-',
            modalCheckout: cell.dataset.checkout || '-',
            modalStatus: (
                cell.dataset.status || '-'
            ).replaceAll('_', ' ')
        };

        Object.entries(values).forEach(function ([id, value]) {
            document.getElementById(id).textContent = value;
        });

    });

});
</script>

@endsection
