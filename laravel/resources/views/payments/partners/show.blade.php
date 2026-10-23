@extends('layouts/main')

@section('styles')
<style>
.partner-payment-page { --hs-navy:#294780; --hs-orange:#e98d50; }
.partner-payment-page .hs-page-link { color:var(--hs-navy); font-weight:600; text-decoration:none; }
.partner-payment-page .hs-page-link:hover { color:var(--hs-orange); }
.partner-payment-page .hs-action-btn { background:var(--hs-navy); border-color:var(--hs-navy); color:#fff; font-weight:600; }
.partner-payment-page .hs-action-btn:hover { background:#203967; border-color:#203967; color:#fff; }
.partner-payment-page .hs-card { background:#fff; border:1px solid #e7ebf2; border-radius:14px; box-shadow:0 3px 16px rgba(30,52,90,.045); }
.partner-payment-page .hs-card-header { display:flex; align-items:center; gap:10px; margin-bottom:20px; }
.partner-payment-page .hs-card-header::before { content:''; width:4px; height:23px; border-radius:5px; background:var(--hs-orange); }
.partner-payment-page .hs-card-title { margin:0; font-size:1.07rem; font-weight:700; color:#253b60; }
.partner-payment-page .hs-money { font-size:clamp(1.25rem,2vw,1.65rem); font-weight:750; color:var(--hs-navy); overflow-wrap:anywhere; }
.partner-payment-page .hs-money.outstanding { color:#b85e24; }
.partner-payment-page .hs-detail-label { color:#748096; font-size:.79rem; font-weight:600; margin-bottom:5px; }
.partner-payment-page .hs-detail-value { color:#29394f; font-weight:600; overflow-wrap:anywhere; }
.partner-payment-page .hs-status { display:inline-flex; align-items:center; border-radius:999px; padding:7px 12px; font-size:.78rem; font-weight:700; }
.partner-payment-page .hs-status.pending { background:#fff3de; color:#976112; }
.partner-payment-page .hs-status.confirmed,.partner-payment-page .hs-status.checked_in { background:#e9f0fc; color:#294780; }
.partner-payment-page .hs-status.checked_out { background:#e7f5ed; color:#257044; }
.partner-payment-page .hs-status.cancelled { background:#f0f1f4; color:#667085; }
.partner-payment-page .form-label { font-size:.87rem; font-weight:650; color:#354761; }
.partner-payment-page .form-control:focus,.partner-payment-page .form-select:focus { border-color:#e98d50; box-shadow:0 0 0 .2rem rgba(233,141,80,.14); }
.partner-payment-page .table thead th { background:#f1f5fb; color:#294780; font-size:.77rem; font-weight:750; text-transform:uppercase; letter-spacing:.025em; border-bottom:1px solid #dfe6f1; white-space:nowrap; padding:13px 12px; }
.partner-payment-page .table tbody td { padding:13px 12px; border-color:#edf0f5; }
.partner-payment-page .table tbody tr:hover { background:#f9fbfe; }
.partner-payment-page .hs-muted-note { color:#758198; font-size:.85rem; }
.partner-payment-page .hs-audit-change { font-size:.83rem; margin-bottom:5px; overflow-wrap:anywhere; }
.partner-payment-page .hs-audit-before { color:#9a4d3c; }
.partner-payment-page .hs-audit-after { color:#256b49; }
.partner-payment-page .hs-top-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.partner-payment-page .hs-outline-btn{border:1px solid #294780;color:#294780;background:#fff;font-weight:600}.partner-payment-page .hs-outline-btn:hover{background:#edf3fc;color:#203967}.partner-payment-page .hs-payment-form[hidden]{display:none!important}.partner-payment-page .hs-edit-panel{border:1px solid #cbd8ec;background:#f9fbff;border-radius:12px;padding:20px;margin-bottom:20px}.partner-payment-page .hs-edit-link{font-weight:650;white-space:nowrap}.partner-payment-page .hs-disabled-hint{border-left:4px solid #e98d50;background:#fff7ee;padding:12px 16px;border-radius:8px;color:#79502f}
</style>
@endsection

@section('content')
<?php
$bookingStatus = (string) ($booking['status'] ?? 'pending');
$editPaymentId = (int) (haven_service('request')->getGet('edit_payment') ?? 0);
$editingPayment = null;
if ($canManage && $editPaymentId > 0) {
    foreach ($payments as $paymentCandidate) {
        if ((int) $paymentCandidate['id'] === $editPaymentId) {
            $editingPayment = $paymentCandidate;
            break;
        }
    }
}

$canRecordPayment = $canManage
    && in_array($bookingStatus, ['confirmed', 'checked_in', 'checked_out'], true)
    && (float) $booking['balance'] > 0;
?>
<div class="partner-payment-page">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <?php if (haven_can_access(site_url('payments/partners'), 'GET')): ?><a href="<?= site_url('payments/partners') ?>" class="hs-page-link small">&larr; All Partner Owner Payments</a><?php endif; ?>
            <h3 class="fw-bold mt-2 mb-1">Partner Booking #<?= (int) $booking['id'] ?></h3>
            <p class="text-muted mb-0"><?= esc($booking['apartment_name']) ?><?= !empty($booking['building_name']) ? ' &middot; ' . esc($booking['building_name']) : '' ?></p>
        </div>
        <div class="d-flex flex-column align-items-md-end gap-2">
            <span class="hs-status <?= esc($bookingStatus, 'attr') ?>"><?= esc(ucwords(str_replace('_', ' ', $bookingStatus))) ?></span>
            <div class="hs-top-actions">
                <?php if (haven_can_access(site_url('reservations/' . (int) $booking['id']), 'GET')): ?><a href="<?= site_url('reservations/' . (int) $booking['id']) ?>" class="btn hs-outline-btn">View Reservation</a><?php endif; ?>
                <?php if ($canRecordPayment): ?>
                    <button type="button" class="btn hs-action-btn" id="hs-toggle-owner-payment" aria-controls="hs-owner-payment-form" aria-expanded="<?= old('amount') !== null ? 'true' : 'false' ?>">+ Record Payment</button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (haven_session()->getFlashdata('success')): ?>
        <div class="alert alert-success" role="alert"><?= esc(haven_session()->getFlashdata('success')) ?></div>
    <?php endif; ?>
    <?php if (haven_session()->getFlashdata('error')): ?>
        <div class="alert alert-danger" role="alert"><?= esc(haven_session()->getFlashdata('error')) ?></div>
    <?php endif; ?>
    <?php if (haven_session()->getFlashdata('errors')): ?>
        <div class="alert alert-danger" role="alert">
            <?php foreach ((array) haven_session()->getFlashdata('errors') as $error): ?>
                <div><?= esc(is_array($error) ? implode(', ', $error) : $error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <?php foreach ([
            ['Owner Payout', $booking['owed'], 'wallet', false],
            ['Payments Recorded', $booking['paid'], 'check-circle', false],
            ['Outstanding Balance', $booking['balance'], 'clock-3', true],
        ] as [$label, $amount, $icon, $highlight]): ?>
            <div class="col-lg-4 col-md-6">
                <div class="hs-stat-card h-100">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <div class="hs-stat-label mb-2"><?= esc($label) ?></div>
                            <div class="hs-money<?= $highlight ? ' outstanding' : '' ?>">KES <?= number_format((float) $amount, 2) ?></div>
                        </div>
                        <div class="hs-stat-icon"><i data-lucide="<?= esc($icon, 'attr') ?>"></i></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="hs-card p-4 mb-4">
        <div class="hs-card-header"><h5 class="hs-card-title">Booking Details</h5></div>
        <div class="row g-4">
            <div class="col-md-4"><div class="hs-detail-label">Partner Owner</div><div class="hs-detail-value"><?= esc($booking['owner_name']) ?></div></div>
            <div class="col-md-4"><div class="hs-detail-label">Guest</div><div class="hs-detail-value"><?= esc($booking['guest_name']) ?></div></div>
            <div class="col-md-4"><div class="hs-detail-label">Stay</div><div class="hs-detail-value"><?= esc($booking['check_in']) ?> to <?= esc($booking['check_out']) ?></div></div>
        </div>
    </div>

    <?php if ($canManage && !$canRecordPayment && (float) $booking['balance'] > 0 && !in_array($bookingStatus, ['confirmed', 'checked_in', 'checked_out'], true)): ?>
        <div class="hs-disabled-hint mb-4">Owner payments can be recorded only after this booking is Confirmed, Checked In or Checked Out. <?php if (haven_can_access(site_url('reservations/' . (int) $booking['id']), 'GET')): ?><a href="<?= site_url('reservations/' . (int) $booking['id']) ?>">View reservation</a><?php endif; ?>.</div>
    <?php endif; ?>

    <?php if ($canRecordPayment): ?>
        <div class="hs-card p-4 mb-4 hs-payment-form" id="hs-owner-payment-form" <?= old('amount') === null ? 'hidden' : '' ?>>
            <div class="hs-card-header"><h5 class="hs-card-title">Record Owner Payment</h5></div>
            <?php if (haven_can_access(site_url('payments/partners/' . (int) $booking['id']), 'POST')): ?><form method="post" action="<?= site_url('payments/partners/' . (int) $booking['id']) ?>" onsubmit="return confirm('Confirm that this payment was actually sent to the partner owner?');">
                <?= csrf_field() ?>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="amount" class="form-label">Amount (KES)</label>
                        <input id="amount" name="amount" type="number" min="0.01" step="0.01" max="<?= esc($booking['balance'], 'attr') ?>" value="<?= esc(old('amount') ?? '', 'attr') ?>" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label for="payment_date" class="form-label">Payment Date</label>
                        <input id="payment_date" name="payment_date" type="date" value="<?= esc(old('payment_date') ?? date('Y-m-d'), 'attr') ?>" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label for="payment_method" class="form-label">Payment Method</label>
                        <?php $selectedMethod = old('payment_method') ?? ''; ?>
                        <select id="payment_method" name="payment_method" class="form-select" required>
                            <option value="">Select method</option>
                            <option value="mpesa" <?= $selectedMethod === 'mpesa' ? 'selected' : '' ?>>M-Pesa</option>
                            <option value="bank_transfer" <?= $selectedMethod === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                            <option value="cash" <?= $selectedMethod === 'cash' ? 'selected' : '' ?>>Cash</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="payment_reference" class="form-label">Payment Reference</label>
                        <input id="payment_reference" name="payment_reference" type="text" maxlength="120" value="<?= esc(old('payment_reference') ?? '', 'attr') ?>" class="form-control">
                        <div class="form-text">Required for M-Pesa and bank transfers.</div>
                    </div>
                    <div class="col-md-6">
                        <label for="notes" class="form-label">Notes (optional)</label>
                        <textarea id="notes" name="notes" rows="2" maxlength="5000" class="form-control"><?= esc(old('notes') ?? '') ?></textarea>
                    </div>
                    <div class="col-12 mt-3">
                        <button type="submit" class="btn hs-action-btn px-4">Record Payment</button>
                    </div>
                </div>
            </form><?php endif; ?>
        </div>
    <?php elseif (!$canManage): ?>
        <p class="hs-muted-note mb-4">You have view-only access to these payment records.</p>
    <?php endif; ?>

    <div class="hs-card p-4" id="hs-payment-history">
        <div class="hs-card-header"><h5 class="hs-card-title">Payment History</h5></div>
        <?php if ($editingPayment !== null): ?>
            <?php
                $editId = (int) $editingPayment['id'];
                $editDate = old('payment_date') ?? $editingPayment['payment_date'];
                $editMethod = old('payment_method') ?? $editingPayment['payment_method'];
                $editReference = old('payment_reference') ?? ($editingPayment['payment_reference'] ?? '');
                $editNotes = old('notes') ?? ($editingPayment['notes'] ?? '');
                $editReason = old('change_reason') ?? '';
            ?>
            <div class="hs-edit-panel" id="hs-edit-payment">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h6 class="fw-bold mb-0">Edit Owner Payment #<?= $editId ?></h6>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= site_url('payments/partners/' . (int) $booking['id']) ?>#hs-payment-history">Cancel Editing</a>
                </div>
                <p class="hs-muted-note">Correct payment details below. The recorded amount is locked to protect owner balances.</p>
                <?php if (haven_can_access(site_url('payments/partners/payments/' . $editId . '/update'), 'POST')): ?><form method="post" action="<?= site_url('payments/partners/payments/' . $editId . '/update') ?>" onsubmit="return confirm('Save these corrections to the owner payment?');">
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="hs-edit-amount">Recorded Amount (KES)</label>
                            <input id="hs-edit-amount" class="form-control" value="<?= esc(number_format((float) $editingPayment['amount'], 2), 'attr') ?>" disabled>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="hs-edit-date">Payment Date</label>
                            <input id="hs-edit-date" class="form-control" name="payment_date" type="date" value="<?= esc($editDate, 'attr') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="hs-edit-method">Payment Method</label>
                            <select id="hs-edit-method" name="payment_method" class="form-select" required>
                                <option value="mpesa" <?= $editMethod === 'mpesa' ? 'selected' : '' ?>>M-Pesa</option>
                                <option value="bank_transfer" <?= $editMethod === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                                <option value="cash" <?= $editMethod === 'cash' ? 'selected' : '' ?>>Cash</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="hs-edit-reference">Payment Reference</label>
                            <input id="hs-edit-reference" name="payment_reference" class="form-control" maxlength="120" value="<?= esc($editReference, 'attr') ?>">
                            <div class="form-text">Required for M-Pesa and bank transfers.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="hs-edit-notes">Notes (optional)</label>
                            <textarea id="hs-edit-notes" name="notes" class="form-control" rows="2" maxlength="5000"><?= esc($editNotes) ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="hs-edit-reason">Reason for Change <span class="text-danger">*</span></label>
                            <textarea id="hs-edit-reason" name="change_reason" class="form-control" rows="2" maxlength="1000" required placeholder="Explain why this payment record needs correction."><?= esc($editReason) ?></textarea>
                            <div class="form-text">Required for the permanent audit trail. Be specific about the correction.</div>
                        </div>
                        <div class="col-12">
                            <button class="btn hs-action-btn px-4" type="submit">Save Changes</button>
                        </div>
                    </div>
                </form><?php endif; ?>
            </div>
        <?php elseif ($canManage && $editPaymentId > 0): ?>
            <div class="alert alert-warning">This payment could not be found under the selected booking.</div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Date</th><th>Method</th><th>Reference</th><th>Notes</th><th class="text-end">Amount</th><?php if ($canManage): ?><th class="text-end">Action</th><?php endif; ?></tr></thead>
                <tbody>
                    <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td class="text-nowrap"><?= esc($payment['payment_date']) ?></td>
                            <td><?= esc(ucwords(str_replace('_', ' ', $payment['payment_method']))) ?></td>
                            <td><?= esc($payment['payment_reference'] ?? '—') ?></td>
                            <td><?= esc($payment['notes'] ?? '—') ?></td>
                            <td class="text-end text-nowrap fw-semibold">KES <?= number_format((float) $payment['amount'], 2) ?></td>
                            <?php if ($canManage): ?>
                                <td class="text-end"><a class="btn btn-sm hs-outline-btn hs-edit-link" href="<?= site_url('payments/partners/' . (int) $booking['id']) ?>?edit_payment=<?= (int) $payment['id'] ?>#hs-payment-history">Edit</a></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($payments)): ?>
                        <tr><td colspan="<?= $canManage ? 6 : 5 ?>" class="text-center text-muted py-4">No owner payments recorded for this booking.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="hs-card p-4 mt-4" id="hs-payment-audit-history">
        <div class="hs-card-header"><h5 class="hs-card-title">Payment Audit History</h5></div>
        <p class="hs-muted-note">Corrections to recorded owner payments appear here, including the reason, staff user ID and the values before and after each change.</p>
        <?php $ownerAudits = $auditHistory ?? []; ?>
        <?php if (empty($ownerAudits)): ?>
            <p class="text-muted mb-0">No payment corrections have been recorded for this booking yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Date / Time</th><th>Payment</th><th>Changed By</th><th>Reason</th><th>Changes</th></tr></thead>
                    <tbody>
                    <?php foreach ($ownerAudits as $audit): ?>
                        <?php
                            $before = json_decode((string) ($audit['old_values'] ?? '{}'), true);
                            $after = json_decode((string) ($audit['new_values'] ?? '{}'), true);
                            $before = is_array($before) ? $before : [];
                            $after = is_array($after) ? $after : [];
                            $fieldLabels = [
                                'amount' => 'Amount',
                                'payment_date' => 'Payment Date',
                                'payment_method' => 'Payment Method',
                                'payment_reference' => 'Reference',
                                'notes' => 'Notes',
                            ];
                            $differences = [];
                            foreach ($fieldLabels as $field => $label) {
                                $oldValue = (string) ($before[$field] ?? '');
                                $newValue = (string) ($after[$field] ?? '');
                                if ($oldValue !== $newValue) {
                                    $differences[] = ['label' => $label, 'old' => $oldValue, 'new' => $newValue];
                                }
                            }
                        ?>
                        <tr>
                            <td class="text-nowrap"><?= esc($audit['changed_at'] ?? '—') ?></td>
                            <td class="text-nowrap">#<?= (int) ($audit['payment_id'] ?? 0) ?></td>
                            <td class="text-nowrap">User #<?= (int) ($audit['changed_by'] ?? 0) ?></td>
                            <td style="min-width:180px;white-space:pre-wrap"><?= esc($audit['change_reason'] ?? '—') ?></td>
                            <td style="min-width:230px">
                                <?php if (empty($differences)): ?>
                                    <span class="text-muted">No value differences</span>
                                <?php else: ?>
                                    <?php foreach ($differences as $difference): ?>
                                        <div class="hs-audit-change">
                                            <strong><?= esc($difference['label']) ?>:</strong>
                                            <span class="hs-audit-before"><?= esc($difference['old'] === '' ? '—' : $difference['old']) ?></span>
                                            &rarr;
                                            <span class="hs-audit-after"><?= esc($difference['new'] === '' ? '—' : $difference['new']) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var button = document.getElementById('hs-toggle-owner-payment');
    var form = document.getElementById('hs-owner-payment-form');
    if (!button || !form) return;
    button.addEventListener('click', function () {
        var willOpen = form.hasAttribute('hidden');
        if (willOpen) form.removeAttribute('hidden');
        else form.setAttribute('hidden', '');
        button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        if (willOpen) form.scrollIntoView({behavior:'smooth',block:'start'});
    });
});
</script>
@endsection
