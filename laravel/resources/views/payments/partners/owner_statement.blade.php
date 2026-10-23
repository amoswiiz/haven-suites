@extends('layouts/main')
@section('content')

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <?php if (haven_can_access(site_url('payments/partners/owners'), 'GET')): ?><a href="<?= site_url('payments/partners/owners') ?>" class="small text-decoration-none">&larr; All Partner Owner Balances</a><?php endif; ?>
        <h3 class="fw-bold mt-2 mb-1">Partner Owner Statement</h3>
        <p class="text-muted mb-0"><?= esc($apartment['owner_name']) ?> · <?= esc($apartment['apartment_name']) ?></p>
        <p class="text-muted small mb-0"><?= esc($apartment['location']) ?><?= !empty($apartment['building_name']) ? ' · ' . esc($apartment['building_name']) : '' ?></p>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php foreach ([
        ['Total Owner Payouts', 'owed'],
        ['Payments Recorded', 'paid'],
        ['Outstanding Balance', 'balance'],
    ] as [$label, $key]): ?>
        <div class="col-md-4"><div class="hs-stat-card h-100">
            <div class="hs-stat-label"><?= esc($label) ?></div>
            <div class="fs-4 fw-bold">KES <?= number_format((float) $totals[$key], 2) ?></div>
        </div></div>
    <?php endforeach; ?>
</div>

<div class="hs-panel mb-4">
    <h5 class="mb-3">Bookings and Owner Payouts</h5>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead><tr>
                <th>Booking</th><th>Guest</th><th>Stay</th><th>Status</th>
                <th class="text-end">Payout</th><th class="text-end">Paid</th>
                <th class="text-end">Balance</th><th></th>
            </tr></thead>
            <tbody>
                <?php foreach ($bookings as $booking): ?>
                    <tr>
                        <td>#<?= (int) $booking['id'] ?></td>
                        <td><?= esc($booking['guest_name']) ?></td>
                        <td class="text-nowrap"><?= esc($booking['check_in']) ?> – <?= esc($booking['check_out']) ?></td>
                        <td><?= esc(ucwords(str_replace('_', ' ', $booking['status']))) ?></td>
                        <td class="text-end text-nowrap">KES <?= number_format((float) $booking['owed'], 2) ?></td>
                        <td class="text-end text-nowrap">KES <?= number_format((float) $booking['paid'], 2) ?></td>
                        <td class="text-end text-nowrap">KES <?= number_format((float) $booking['balance'], 2) ?></td>
                        <td><?php if (haven_can_access(site_url('payments/partners/' . (int) $booking['id']), 'GET')): ?><a class="btn btn-sm btn-outline-primary text-nowrap" href="<?= site_url('payments/partners/' . (int) $booking['id']) ?>">View</a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($bookings)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No bookings for this apartment.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="hs-panel">
    <h5 class="mb-3">Payment History</h5>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead><tr><th>Date</th><th>Booking</th><th>Method</th><th>Reference</th><th>Notes</th><th class="text-end">Amount</th></tr></thead>
            <tbody>
                <?php foreach ($payments as $payment): ?>
                    <tr>
                        <td><?= esc($payment['payment_date']) ?></td>
                        <td><?php if (haven_can_access(site_url('payments/partners/' . (int) $payment['reservation_id']), 'GET')): ?><a href="<?= site_url('payments/partners/' . (int) $payment['reservation_id']) ?>">#<?= (int) $payment['reservation_id'] ?></a><?php endif; ?></td>
                        <td><?= esc(ucwords(str_replace('_', ' ', $payment['payment_method']))) ?></td>
                        <td><?= esc($payment['payment_reference'] ?: '—') ?></td>
                        <td><?= esc($payment['notes'] ?: '—') ?></td>
                        <td class="text-end text-nowrap">KES <?= number_format((float) $payment['amount'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($payments)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No payments recorded.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ((float) $cancelledPaid > 0): ?>
<p class="text-warning small mt-3">KES <?= number_format((float) $cancelledPaid, 2) ?> in payments is linked to cancelled bookings. These payments appear in history but are excluded from active-balance summary and require reconciliation.</p>
<?php endif; ?>
<p class="text-muted small mt-3">This statement is for one partner apartment. Pending bookings are included as provisional obligations; cancelled bookings are excluded from the summary totals. Historical payment entries are shown for all bookings.</p>

@endsection
