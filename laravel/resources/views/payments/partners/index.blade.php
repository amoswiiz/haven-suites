@extends('layouts/main')
@section('styles')
<style>
.partner-payments-page{--hs-navy:#294780;--hs-orange:#e98d50;--hs-border:#e6ebf2}
.partner-payments-page .hs-page-action{background:var(--hs-navy);border:1px solid var(--hs-navy);color:#fff}
.partner-payments-page .hs-page-action:hover{background:#203b70;color:#fff}
.partner-payments-page .hs-finance-stat{border:1px solid var(--hs-border);border-radius:14px;background:#fff;padding:22px 23px;height:100%;box-shadow:0 4px 16px rgba(28,49,82,.045);position:relative;overflow:hidden}
.partner-payments-page .hs-finance-stat:before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--hs-navy)}
.partner-payments-page .hs-finance-stat.accent:before{background:var(--hs-orange)}
.partner-payments-page .hs-finance-stat .hs-stat-label{color:#68768b;font-size:.85rem;font-weight:600;margin-bottom:9px}
.partner-payments-page .hs-finance-stat .hs-stat-amount{color:#233c68;font-size:clamp(1.2rem,2vw,1.65rem);font-weight:750;line-height:1.3;overflow-wrap:anywhere}
.partner-payments-page .hs-finance-stat .hs-stat-icon{width:42px;height:42px;border-radius:12px;background:#eef2f9;color:var(--hs-navy);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.partner-payments-page .hs-finance-stat.accent .hs-stat-icon{background:#fff2e9;color:#b85f29}
.partner-payments-page .hs-section{border:1px solid var(--hs-border);border-radius:14px;background:#fff;box-shadow:0 4px 16px rgba(28,49,82,.045);overflow:hidden}
.partner-payments-page .hs-section-body{padding:22px}
.partner-payments-page .hs-section-heading{color:#253d69;font-size:1.05rem;font-weight:700}
.partner-payments-page .form-label{font-weight:600;color:#43546c;font-size:.85rem}
.partner-payments-page .form-control:focus,.partner-payments-page .form-select:focus{border-color:#9caed0;box-shadow:0 0 0 .2rem rgba(41,71,128,.12)}
.partner-payments-page .hs-finance-table{margin-bottom:0;min-width:1040px}
.partner-payments-page .hs-finance-table thead th{background:#f3f6fb;color:#344a70;font-size:.77rem;letter-spacing:.025em;text-transform:uppercase;border-bottom:1px solid var(--hs-border);padding:15px 14px;white-space:nowrap}
.partner-payments-page .hs-finance-table tbody td{padding:15px 14px;border-color:#edf0f5;vertical-align:middle;font-size:.88rem}
.partner-payments-page .hs-finance-table tbody tr:hover{background:#f8fafd}
.partner-payments-page .hs-status{display:inline-block;border-radius:999px;padding:5px 10px;font-size:.76rem;font-weight:650;white-space:nowrap}
.partner-payments-page .hs-status-pending{background:#fff3dd;color:#996313}
.partner-payments-page .hs-status-confirmed,.partner-payments-page .hs-status-checked_in{background:#e9f2ff;color:#24558d}
.partner-payments-page .hs-status-checked_out{background:#e8f7ef;color:#20734c}
.partner-payments-page .hs-status-cancelled{background:#f0f1f4;color:#667085}
.partner-payments-page .hs-payment-balance{color:#a95326}
.partner-payments-page .hs-view-button{border-color:#bdc9dd;color:var(--hs-navy);font-weight:600}
.partner-payments-page .hs-view-button:hover{background:var(--hs-navy);border-color:var(--hs-navy);color:#fff}
.partner-payments-page .hs-notice{border-left:3px solid var(--hs-orange);background:#fffaf6;padding:11px 15px;border-radius:0 9px 9px 0;color:#6c625b;font-size:.82rem}
@media(max-width:767px){.partner-payments-page .hs-section-body{padding:16px}.partner-payments-page .hs-finance-stat{padding:18px}}
</style>
@endsection
@section('content')
<div class="partner-payments-page">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h3 class="fw-bold mb-1">Partner Owner Payments</h3>
            <p class="text-muted mb-0">Owner payouts, recorded payments and outstanding balances by partner booking.</p>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <?php
        $summaryCards = [
            ['Total Owner Payouts', $totals['owed'], 'wallet', false],
            ['Payments Recorded', $totals['paid'], 'check-circle', false],
            ['Outstanding Balances', $totals['balance'], 'clock-3', true],
        ];
        ?>
        <?php foreach ($summaryCards as [$label, $amount, $icon, $accent]): ?>
            <div class="col-lg-4 col-md-6">
                <div class="hs-finance-stat <?= $accent ? 'accent' : '' ?>">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <div class="hs-stat-label"><?= esc($label) ?></div>
                            <div class="hs-stat-amount">KES <?= number_format((float) $amount, 2) ?></div>
                        </div>
                        <span class="hs-stat-icon"><i data-lucide="<?= esc($icon, 'attr') ?>"></i></span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="hs-notice mb-4">Summary excludes cancelled bookings. Pending bookings remain included as provisional obligations; payment entry requires a confirmed, checked-in or checked-out booking.</div>

    <div class="hs-section mb-4">
        <div class="hs-section-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h5 class="hs-section-heading mb-0">Find Partner Bookings</h5>
                <span class="small text-muted" id="partner-count" aria-live="polite"></span>
            </div>
            <div class="row g-3 align-items-end">
                <div class="col-lg-6">
                    <label class="form-label" for="partner-search">Search booking, apartment, owner or guest</label>
                    <input class="form-control" type="search" id="partner-search" placeholder="Type a name or booking number">
                </div>
                <div class="col-lg-3 col-md-6">
                    <label class="form-label" for="partner-status">Booking status</label>
                    <select class="form-select" id="partner-status">
                        <option value="">All statuses</option>
                        <option value="pending">Pending</option>
                        <option value="confirmed">Confirmed</option>
                        <option value="checked_in">Checked In</option>
                        <option value="checked_out">Checked Out</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="col-lg-3 col-md-6">
                    <label class="form-label" for="partner-balance">Payment balance</label>
                    <select class="form-select" id="partner-balance">
                        <option value="">All balances</option>
                        <option value="unpaid">Unpaid</option>
                        <option value="partial">Partially paid</option>
                        <option value="paid">Fully paid</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="hs-section">
        <div class="hs-section-body pb-2">
            <h5 class="hs-section-heading mb-1">Partner Booking Payments</h5>
            <p class="text-muted small mb-2">Review owner payouts and open individual bookings to view payment history.</p>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle hs-finance-table">
                <thead><tr>
                    <th>Booking</th><th>Partner Apartment</th><th>Owner</th><th>Guest / Stay</th><th>Status</th>
                    <th class="text-end">Owner Payout</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($bookings as $booking): ?>
                        <?php
                            $owed = (float) $booking['owed'];
                            $paid = (float) $booking['paid'];
                            $paymentState = $paid <= 0 ? 'unpaid' : ($paid + 0.001 >= $owed ? 'paid' : 'partial');
                            $bookingStatus = (string) $booking['status'];
                            $knownStatuses = ['pending', 'confirmed', 'checked_in', 'checked_out', 'cancelled'];
                            $statusClass = in_array($bookingStatus, $knownStatuses, true) ? $bookingStatus : 'cancelled';
                        ?>
                        <tr class="partner-booking-row" data-status="<?= esc($bookingStatus, 'attr') ?>" data-balance="<?= esc($paymentState, 'attr') ?>">
                            <td class="fw-semibold text-nowrap">#<?= (int) $booking['id'] ?></td>
                            <td>
                                <div class="fw-semibold"><?= esc($booking['apartment_name']) ?></div>
                                <small class="text-muted"><?= esc($booking['building_name'] ?? '') ?></small>
                            </td>
                            <td><?= esc($booking['owner_name']) ?></td>
                            <td>
                                <div class="fw-semibold"><?= esc($booking['guest_name']) ?></div>
                                <small class="text-muted text-nowrap"><?= esc($booking['check_in']) ?> – <?= esc($booking['check_out']) ?></small>
                            </td>
                            <td><span class="hs-status hs-status-<?= esc($statusClass, 'attr') ?>"><?= esc(ucwords(str_replace('_', ' ', $bookingStatus))) ?></span></td>
                            <td class="text-end text-nowrap">KES <?= number_format($owed, 2) ?></td>
                            <td class="text-end text-nowrap">KES <?= number_format($paid, 2) ?></td>
                            <td class="text-end text-nowrap fw-semibold <?= (float) $booking['balance'] > 0 ? 'hs-payment-balance' : '' ?>">KES <?= number_format((float) $booking['balance'], 2) ?></td>
                            <td class="text-end"><a class="btn btn-sm btn-outline-primary hs-view-button text-nowrap" href="<?= site_url('payments/partners/' . (int) $booking['id']) ?>">View payments</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr id="partner-no-match" style="display:none"><td colspan="9" class="text-center text-muted py-4">No bookings match these filters.</td></tr>
                    <?php if (empty($bookings)): ?>
                        <tr><td colspan="9" class="text-center text-muted py-4">No partner bookings found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <p class="small text-muted mt-3">Balances are based on each booking's owner payout less recorded partner owner payments. Cancelled and pending bookings may appear for reference, but payments can only be recorded for confirmed, checked-in or checked-out bookings.</p>
</div>
@endsection
@section('scripts')
<script>
(function () {
    const search = document.getElementById('partner-search');
    const status = document.getElementById('partner-status');
    const balance = document.getElementById('partner-balance');
    const rows = Array.from(document.querySelectorAll('.partner-booking-row'));
    const count = document.getElementById('partner-count');
    const empty = document.getElementById('partner-no-match');
    function filterRows() {
        const query = search.value.trim().toLocaleLowerCase();
        let visible = 0;
        rows.forEach(function (row) {
            const matches = (!query || row.textContent.toLocaleLowerCase().includes(query))
                && (!status.value || row.dataset.status === status.value)
                && (!balance.value || row.dataset.balance === balance.value);
            row.style.display = matches ? '' : 'none';
            if (matches) visible++;
        });
        empty.style.display = rows.length > 0 && visible === 0 ? '' : 'none';
        count.textContent = 'Showing ' + visible + ' of ' + rows.length + ' partner bookings';
    }
    [search, status, balance].forEach(function (control) {
        control.addEventListener(control === search ? 'input' : 'change', filterRows);
    });
    filterRows();
})();
</script>
@endsection
