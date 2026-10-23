@extends('layouts/main')
@section('content')
<div class="container-fluid py-3">
    <div class="mb-4"><h3 class="fw-bold mb-1">Record Cleaner Payment</h3><p class="text-muted">Allocate one payment to one or more completed cleaning tasks.</p></div>
    <?php if (session('error')): ?><div class="alert alert-danger"><?= esc(session('error')) ?></div><?php endif; ?>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <?php if (haven_can_access(site_url('payments/cleaners/create'), 'GET')): ?><form method="get" action="<?= site_url('payments/cleaners/create') ?>" class="row g-3 align-items-end">
            <div class="col-md-7"><label class="form-label fw-semibold" for="staffFilter">Cleaner / Staff Member</label>
                <select class="form-select" name="staff_id" id="staffFilter" required>
                    <option value="">Choose staff member</option>
                    <?php foreach ($staff as $member): ?><option value="<?= (int) $member['id'] ?>" <?= (int) $staffId === (int) $member['id'] ? 'selected' : '' ?>><?= esc($member['full_name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-auto"><button type="submit" class="btn btn-outline-primary">Load Unpaid Tasks</button></div>
        </form><?php endif; ?>
    </div></div>
    <?php if ($staffId): ?>
        <?php if (haven_can_access(site_url('payments/cleaners'), 'POST')): ?><form method="post" action="<?= site_url('payments/cleaners') ?>" id="paymentForm">
            <?= csrf_field() ?>
            <input type="hidden" name="staff_id" value="<?= (int) $staffId ?>">
            <div class="card border-0 shadow-sm mb-3"><div class="card-body">
                <h5 class="fw-bold mb-3">Payment Details</h5>
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label">Payment Date</label><input class="form-control" type="date" name="payment_date" value="<?= esc(old('payment_date', date('Y-m-d')), 'attr') ?>" required></div>
                    <div class="col-md-4"><label class="form-label">Method</label><select class="form-select" name="payment_method" required><option value="mpesa">M-Pesa</option><option value="bank">Bank Transfer</option><option value="cash">Cash</option></select></div>
                    <div class="col-md-4"><label class="form-label">Reference (optional)</label><input class="form-control" name="reference" maxlength="100" value="<?= esc(old('reference', ''), 'attr') ?>"></div>
                    <div class="col-md-4"><label class="form-label">Total Payment (KES)</label><input class="form-control" type="number" name="amount" id="paymentAmount" min="0.01" step="0.01" required readonly></div>
                    <div class="col-md-8"><label class="form-label">Notes (optional)</label><textarea class="form-control" name="notes" rows="2"><?= esc(old('notes', '')) ?></textarea></div>
                </div>
            </div></div>
            <div class="card border-0 shadow-sm"><div class="card-body">
                <h5 class="fw-bold mb-3">Allocate to Cleaning Tasks</h5>
                <p class="text-muted">Enter the amount to pay for each task. Leave a task at zero to exclude it. The total payment updates automatically.</p>
                <div class="table-responsive"><table class="table align-middle">
                    <thead><tr><th>Task</th><th>Apartment</th><th>Completed</th><th class="text-end">Remaining (KES)</th><th style="min-width:170px">Pay Now (KES)</th></tr></thead>
                    <tbody>
                    <?php foreach ($tasks as $task): ?>
                        <tr>
                            <td>#<?= (int) $task['id'] ?> — <?= esc($task['cleaning_type']) ?></td>
                            <td><?= esc($task['property_name'] ?? '-') ?> / <?= esc($task['unit_number'] ?? '-') ?></td>
                            <td><?= esc($task['completed_at'] ?? '-') ?></td>
                            <td class="text-end"><?= number_format((float) $task['remaining'], 2) ?></td>
                            <td><input type="number" class="form-control allocation" name="allocations[<?= (int) $task['id'] ?>]" min="0" max="<?= esc($task['remaining'], 'attr') ?>" step="0.01" value="0.00"></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$tasks): ?><tr><td colspan="5" class="text-center text-muted py-4">No unpaid completed cleaning tasks for this staff member.</td></tr><?php endif; ?>
                    </tbody>
                </table></div>
                <div class="d-flex justify-content-end gap-2 mt-3"><?php if (haven_can_access(site_url('payments/cleaners'), 'GET')): ?><a href="<?= site_url('payments/cleaners') ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?><button class="btn btn-primary" type="submit" <?= !$tasks ? 'disabled' : '' ?>>Save Payment</button></div>
            </div></div>
        </form><?php endif; ?>
    <?php endif; ?>
</div>
@endsection
@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const allocations = Array.from(document.querySelectorAll('.allocation'));
    const total = document.getElementById('paymentAmount');
    if (!total) return;
    function calculate() {
        let cents = 0;
        allocations.forEach(function (input) {
            const value = Number(input.value);
            if (Number.isFinite(value) && value > 0) cents += Math.round(value * 100);
        });
        total.value = (cents / 100).toFixed(2);
    }
    allocations.forEach(function (input) { input.addEventListener('input', calculate); });
    calculate();
});
</script>
@endsection
