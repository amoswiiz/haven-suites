@extends('layouts/main')

@section('styles')
<style>
/* Scoped branding: Reservations only; shared layouts and other modules unaffected. */
.hs-reservations-page { --hs-res-navy:#294780; --hs-res-orange:#e98d50; --hs-res-border:#e2e8f0; }
.hs-reservations-page h3,.hs-reservations-page h5 {color:#203454;letter-spacing:-.02em}
.hs-reservations-page .hs-reservation-add {display:inline-flex;align-items:center;gap:7px;background:var(--hs-res-navy);border-color:var(--hs-res-navy);padding:10px 16px;font-weight:600;border-radius:9px}
.hs-reservations-page .hs-reservation-add:hover {background:#1d3562;border-color:#1d3562}
.hs-reservations-page .hs-reservation-add svg {width:17px;height:17px}
.hs-reservations-page .hs-stat-card {border:1px solid var(--hs-res-border);border-radius:13px;box-shadow:0 3px 14px rgba(25,44,78,.035)}
.hs-reservations-page .hs-stat-value {font-size:clamp(22px,2vw,29px)!important;overflow-wrap:anywhere}
.hs-reservations-page .hs-stat-icon {color:var(--hs-res-navy);background:#eef2fa}
.hs-reservations-page .hs-reservation-directory {border:1px solid var(--hs-res-border)!important;border-radius:14px;overflow:hidden}
.hs-reservations-page .hs-reservation-directory .card-body {padding:24px}
.hs-reservations-page .hs-reservation-table {font-size:13px;margin-bottom:0}
.hs-reservations-page .hs-reservation-table thead th {background:#f1f4f9;color:#304466;font-size:11px;letter-spacing:.035em;text-transform:uppercase;white-space:nowrap;border-bottom:2px solid #d8e1ed;padding:12px 11px}
.hs-reservations-page .hs-reservation-table tbody td {padding:13px 11px;border-bottom-color:#e8edf4}
.hs-reservations-page .hs-reservation-table tbody tr:hover td {background:#f7f9fd}
.hs-reservations-page .form-control:focus,.hs-reservations-page .form-select:focus {border-color:#8fa5d0;box-shadow:0 0 0 .2rem rgba(41,71,128,.12)}
.hs-reservations-page .hs-guest-link { color:#294780; font-weight:700; text-decoration:none; background:none; border:0; padding:0; text-align:left; cursor:pointer; }
.hs-reservations-page .hs-guest-link:hover { color:#c46b32; text-decoration:underline; }
.hs-reservations-page .hs-view-btn { color:#294780; border:1px solid #cbd6e7; background:white; border-radius:7px; padding:5px 9px; font-size:12px; white-space:nowrap; }
.hs-reservations-page .hs-view-btn:hover { background:#eef2fa; }
.hs-reservations-page .hs-action-group {display:flex;gap:6px;align-items:center;white-space:nowrap}
/* Explicit high-contrast text overrides global link/button styling. */
.hs-reservations-page .hs-action-group a.hs-edit-btn,
.hs-reservations-page .hs-action-group a.hs-edit-btn:link,
.hs-reservations-page .hs-action-group a.hs-edit-btn:visited {
    display:inline-flex;align-items:center;justify-content:center;
    min-height:33px;padding:6px 13px;border-radius:7px;
    background:#294780!important;border:1px solid #294780!important;
    color:#ffffff!important;-webkit-text-fill-color:#ffffff!important;
    font-size:12px;font-weight:700;line-height:1.3;text-decoration:none!important;
    opacity:1!important;visibility:visible!important;
}
.hs-reservations-page .hs-action-group a.hs-edit-btn:hover,
.hs-reservations-page .hs-action-group a.hs-edit-btn:focus-visible {
    background:#1d3562!important;border-color:#1d3562!important;
    color:#ffffff!important;-webkit-text-fill-color:#ffffff!important;
    box-shadow:0 0 0 3px rgba(41,71,128,.14);
}
.hs-reservations-page .hs-action-group .hs-view-btn {display:inline-flex;align-items:center;justify-content:center;min-height:33px;padding:6px 11px;color:#294780!important;font-weight:600;}
.hs-reservations-page .hs-action-group .hs-view-btn:hover {background:#eef2fa;color:#1d3562!important;}
.hs-reservations-page .hs-action-group {gap:8px;}
.hs-reservations-page .hs-reservation-table tbody td:last-child {min-width:215px;}
.hs-reservations-page .hs-reservation-status {font-size:11px;font-weight:700;padding:7px 9px;border-radius:999px;white-space:nowrap}
.hs-reservations-page .hs-status-confirmed {background:#e7efff;color:#244a94}
.hs-reservations-page .hs-status-checked-in {background:#e4f5ee;color:#16734d}
.hs-reservations-page .hs-status-checked-out {background:#e9edf2;color:#506176}
.hs-reservations-page .hs-status-cancelled {background:#fce8e8;color:#aa3434}
.hs-reservations-page .hs-status-pending {background:#fff0df;color:#995415}
@media(max-width:767px){.hs-reservations-page .hs-reservation-directory .card-body{padding:15px}.hs-reservations-page .hs-reservation-directory input,.hs-reservations-page .hs-reservation-directory select{min-width:0!important;width:100%}}
</style>
@endsection

@section('content')
<?php $activity = haven_service('request')->getGet('activity'); ?>
<?php if (in_array($activity, ['checkins', 'checkouts'], true)): ?>
<div class="alert alert-info d-flex justify-content-between align-items-center gap-3">
    <span>Showing today's <?= $activity === 'checkins' ? 'check-ins' : 'check-outs' ?> &mdash; <?= esc(date('j M Y')) ?>.</span>
    <?php if (haven_can_access(site_url('reservations'), 'GET')): ?><a href="<?= site_url('reservations') ?>">Show all reservations</a><?php endif; ?>
</div>
<?php endif; ?>

<?php
$reservationList = $reservations ?? [];

$totalReservations = count($reservationList);

$confirmedReservations = count(array_filter(
    $reservationList,
    fn($r) => in_array(
        $r['status'],
        ['confirmed', 'checked_in'],
        true
    )
));

$pendingReservations = count(array_filter(
    $reservationList,
    fn($r) => $r['status'] === 'pending'
));

$totalOutstanding = 0;

foreach ($reservationList as $reservation) {
    if ($reservation['status'] !== 'cancelled') {
        $balance = (float) $reservation['total_amount']
            - (float) $reservation['recorded_paid'];

        $totalOutstanding += max(0, $balance);
    }
}

$statusLabels = [
    'pending' => 'Pending',
    'confirmed' => 'Confirmed',
    'checked_in' => 'Checked In',
    'checked_out' => 'Checked Out',
    'cancelled' => 'Cancelled',
];
?>

<div class="hs-reservations-page">
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">

    <div>
        <h3 class="fw-bold mb-1">Reservations</h3>
        <p class="text-muted mb-0">
            Manage guest bookings, stays and payments.
        </p>
    </div>

    <?php if (haven_can_access(site_url('reservations/create'), 'GET')): ?><a href="<?= site_url('reservations/create') ?>"
       class="btn btn-primary hs-reservation-add">
        <i data-lucide="plus" aria-hidden="true"></i> New Reservation
    </a><?php endif; ?>

</div>

<!-- Statistics -->
<div class="row g-3 mb-4">

    <?php
    $stats = [
        ['Total Reservations', $totalReservations, 'calendar-days'],
        ['Active Bookings', $confirmedReservations, 'calendar-check'],
        ['Pending Bookings', $pendingReservations, 'clock'],
        [
            'Outstanding Balance',
            'KES ' . number_format($totalOutstanding, 0),
            'wallet'
        ],
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

                        <div class="hs-stat-value"
                             style="font-size:<?= $label === 'Outstanding Balance'
                                 ? '24px'
                                 : '30px' ?>">
                            <?= esc($value) ?>
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

<!-- Reservations Directory -->
<div class="card border-0 shadow-sm hs-reservation-directory">

    <div class="card-body">

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">

            <div>
                <h5 class="fw-bold mb-1">Booking Directory</h5>
                <p class="text-muted small mb-0">
                    All managed and partner apartment reservations.
                </p>
            </div>

            <div class="d-flex gap-2 flex-wrap">

                <input type="search"
                       id="reservationSearch"
                       class="form-control"
                       style="min-width:220px"
                       placeholder="Search guest, unit, phone..."
                       aria-label="Search reservations">

                <select id="statusFilter"
                        class="form-select"
                        style="min-width:160px"
                        aria-label="Filter reservations by status">

                    <option value="">All Statuses</option>

                    <?php foreach ($statusLabels as $key => $label): ?>
                        <option value="<?= esc($key) ?>">
                            <?= esc($label) ?>
                        </option>
                    <?php endforeach; ?>

                </select>

            </div>

        </div>

        <div class="table-responsive">

            <table class="table table-hover align-middle hs-reservation-table">

                <thead>
                    <tr>
                        <th>Booking</th>
                        <th>Guest</th>
                        <th>Apartment</th>
                        <th>Stay Dates</th>
                        <th>Nights</th>
                        <th>Total</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Source</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                <?php foreach ($reservationList as $reservation): ?>

                    <?php
                    $balance = max(
                        0,
                        (float) $reservation['total_amount']
                        - (float) $reservation['recorded_paid']
                    );

                    $start = new \DateTimeImmutable(
                        $reservation['check_in']
                    );

                    $end = new \DateTimeImmutable(
                        $reservation['check_out']
                    );

                    $nights = $start->diff($end)->days;

                    $status = $reservation['status'];

                    $badgeClass = match ($status) {
                        'confirmed' => 'bg-primary',
                        'checked_in' => 'bg-success',
                        'checked_out' => 'bg-secondary',
                        'cancelled' => 'bg-danger',
                        default => 'bg-warning text-dark'
                    };
                    ?>

                    <tr class="reservation-row"
                        data-status="<?= esc($status, 'attr') ?>">

                        <td class="fw-semibold">
                            <?php if (haven_can_access(site_url('reservations/' . (int) $reservation['id']), 'GET')): ?><a class="hs-guest-link" href="<?= site_url('reservations/' . (int) $reservation['id']) ?>" aria-label="Open booking #<?= (int) $reservation['id'] ?>">#<?= (int) $reservation['id'] ?></a><?php endif; ?>
                        </td>

                        <td>
                            <div class="fw-semibold">
                                <?php if (haven_can_access(site_url('reservations/' . (int) $reservation['id']), 'GET')): ?><a class="hs-guest-link" href="<?= site_url('reservations/' . (int) $reservation['id']) ?>" aria-label="Open reservation for <?= esc($reservation['guest_name'], 'attr') ?>"><?= esc($reservation['guest_name']) ?></a><?php endif; ?>
                            </div>

                            <div class="small text-muted">
                                <?= esc($reservation['guest_phone']) ?>
                            </div>
                        </td>

                        <td>
                            <?php if (($reservation['booking_type'] ?? 'managed') === 'partner'): ?>
                                <div class="fw-semibold"><?= esc($reservation['partner_name'] ?? 'Partner Apartment') ?></div>
                                <div class="small text-muted">
                                    Partner · <?= esc($reservation['partner_location'] ?? '') ?>
                                    · Host: <?= esc($reservation['partner_owner'] ?? '-') ?>
                                </div>
                                <div class="small text-muted">
                                    Markup: KES <?= number_format((float) $reservation['total_amount'] - (float) ($reservation['owner_net_amount'] ?? 0), 0) ?>
                                </div>
                            <?php else: ?>
                                <div class="fw-semibold">
                                    <?= esc(($reservation['property_name'] ?? 'Building') . ' – ' . ($reservation['unit_number'] ?? '-')) ?>
                                </div>
                                <div class="small text-muted">Managed Apartment</div>
                            <?php endif; ?>
                        </td>

                        <td>
                            <div>
                                <?= esc($start->format('d M Y')) ?>
                            </div>

                            <div class="small text-muted">
                                to <?= esc($end->format('d M Y')) ?>
                            </div>
                        </td>

                        <td><?= esc($nights) ?></td>

                        <td>
                            KES <?= number_format(
                                (float) $reservation['total_amount'],
                                0
                            ) ?>
                        </td>

                        <td>
                            KES <?= number_format(
                                (float) $reservation['recorded_paid'],
                                0
                            ) ?>
                        </td>

                        <td class="<?= $balance > 0
                            ? 'text-danger fw-semibold'
                            : 'text-success' ?>">
                            KES <?= number_format($balance, 0) ?>
                        </td>

                        <td>
                            <?= esc($reservation['booking_source'] ?: '-') ?>
                        </td>

                        <td>
                            <span class="badge hs-reservation-status <?= $badgeClass ?>">
                                <?= esc($statusLabels[$status] ?? ucfirst($status)) ?>
                            </span>
                        </td>
                        <td><div class="hs-action-group">
                            <?php if (haven_can_access(site_url('reservations/' . (int) $reservation['id']), 'GET')): ?><a class="hs-view-btn text-decoration-none" href="<?= site_url('reservations/' . (int) $reservation['id']) ?>">View</a><?php endif; ?>
                            <?php if (haven_auth()->user()?->can('reservations.manage')): ?>
                                <?php if (haven_can_access(site_url('reservations/' . (int) $reservation['id'] . '/edit'), 'GET')): ?><a class="hs-edit-btn" aria-label="Edit reservation #<?= (int) $reservation['id'] ?>" href="<?= site_url('reservations/' . (int) $reservation['id'] . '/edit') ?>">Edit</a><?php endif; ?>
                            <?php endif; ?>
                            <button type="button" class="hs-view-btn" data-bs-toggle="modal" data-bs-target="#reservationDetail<?= (int) $reservation['id'] ?>">Quick View</button>
                        </div></td>
                    </tr>

                <?php endforeach; ?>

                <?php if (empty($reservationList)): ?>
                    <tr>
                        <td colspan="11"
                            class="text-center text-muted py-5">
                            No reservations recorded yet.
                        </td>
                    </tr>
                <?php endif; ?>

                </tbody>

            </table>

        </div>

        <div class="d-flex justify-content-between align-items-center mt-3">
            <span id="reservationCount"
                  class="text-muted small">
                Showing <?= $totalReservations ?> reservations
            </span>

            <?php if (haven_can_access(site_url('availability'), 'GET')): ?><a href="<?= site_url('availability') ?>"
               class="small text-decoration-none">
                View Availability Calendar &rarr;
            </a><?php endif; ?>
        </div>

    </div>

</div>
</div> <!-- /hs-reservations-page -->

<!-- Read-only booking details: works without adding new controller routes. -->
<?php foreach ($reservationList as $reservation): ?>
<?php
  $modalStart = new \DateTimeImmutable($reservation['check_in']);
  $modalEnd = new \DateTimeImmutable($reservation['check_out']);
  $modalBalance = max(0, (float) $reservation['total_amount'] - (float) $reservation['recorded_paid']);
  $modalApartment = ($reservation['booking_type'] ?? 'managed') === 'partner'
    ? ($reservation['partner_name'] ?? 'Partner Apartment')
    : (($reservation['property_name'] ?? 'Building') . ' – ' . ($reservation['unit_number'] ?? '-'));
?>
<div class="modal fade" id="reservationDetail<?= (int) $reservation['id'] ?>" tabindex="-1" aria-labelledby="reservationTitle<?= (int) $reservation['id'] ?>" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header" style="border-top:4px solid #e98d50">
      <div><div class="small text-muted">HAVEN SUITES · BOOKING #<?= (int) $reservation['id'] ?></div><h5 class="modal-title fw-bold" id="reservationTitle<?= (int) $reservation['id'] ?>"><?= esc($reservation['guest_name']) ?></h5></div>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body">
      <div class="row g-3">
        <div class="col-md-6"><div class="small text-muted">Phone</div><div class="fw-semibold"><?= esc($reservation['guest_phone'] ?: '—') ?></div></div>
        <div class="col-md-6"><div class="small text-muted">Email</div><div><?= esc($reservation['guest_email'] ?: '—') ?></div></div>
        <div class="col-md-6"><div class="small text-muted">Apartment</div><div class="fw-semibold"><?= esc($modalApartment) ?></div><div class="small text-muted"><?= esc(ucfirst($reservation['booking_type'] ?? 'managed')) ?> booking</div></div>
        <div class="col-md-6"><div class="small text-muted">Status / Source</div><div class="fw-semibold"><?= esc($statusLabels[$reservation['status']] ?? ucfirst($reservation['status'])) ?> · <?= esc($reservation['booking_source'] ?: 'Direct') ?></div></div>
        <div class="col-md-6"><div class="small text-muted">Check-in</div><div class="fw-semibold"><?= esc($modalStart->format('d M Y')) ?></div></div>
        <div class="col-md-6"><div class="small text-muted">Check-out</div><div class="fw-semibold"><?= esc($modalEnd->format('d M Y')) ?> (<?= (int) $modalStart->diff($modalEnd)->days ?> nights)</div></div>
        <div class="col-md-6"><div class="small text-muted">Guests</div><div><?= (int) ($reservation['adults'] ?? 0) ?> adults, <?= (int) ($reservation['children'] ?? 0) ?> children</div></div>
        <div class="col-md-6"><div class="small text-muted">Nightly rate</div><div>KES <?= number_format((float) ($reservation['nightly_rate'] ?? 0), 2) ?></div></div>
      </div>
      <hr>
      <div class="row g-3">
        <div class="col-4"><div class="small text-muted">Booking total</div><strong>KES <?= number_format((float) $reservation['total_amount'], 2) ?></strong></div>
        <div class="col-4"><div class="small text-muted">Recorded paid</div><strong>KES <?= number_format((float) $reservation['recorded_paid'], 2) ?></strong></div>
        <div class="col-4"><div class="small text-muted">Balance</div><strong style="color:#c46b32">KES <?= number_format($modalBalance, 2) ?></strong></div>
      </div>
      <?php if (!empty($reservation['notes'])): ?><hr><div class="small text-muted">Booking notes</div><div style="white-space:pre-wrap"><?= esc($reservation['notes']) ?></div><?php endif; ?>
      <div class="small text-muted mt-3">Payment figures come from recorded guest payments. Open the booking workspace for invoices and receipts.</div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>
  </div></div>
</div>
<?php endforeach; ?>


@endsection

@section('scripts')

<script>
document.addEventListener('DOMContentLoaded', function () {

    const search = document.getElementById('reservationSearch');
    const status = document.getElementById('statusFilter');
    const rows = document.querySelectorAll('.reservation-row');
    const count = document.getElementById('reservationCount');

    function filterReservations() {

        const query = search.value.trim().toLowerCase();
        const selectedStatus = status.value;

        let visible = 0;

        rows.forEach(function (row) {

            const matchesText =
                row.textContent.toLowerCase().includes(query);

            const matchesStatus =
                !selectedStatus ||
                row.dataset.status === selectedStatus;

            const show = matchesText && matchesStatus;

            row.style.display = show ? '' : 'none';

            if (show) {
                visible++;
            }
        });

        count.textContent =
            'Showing ' + visible + ' of ' +
            rows.length + ' reservations';
    }

    search.addEventListener('input', filterReservations);
    status.addEventListener('change', filterReservations);

});
</script>

@endsection
