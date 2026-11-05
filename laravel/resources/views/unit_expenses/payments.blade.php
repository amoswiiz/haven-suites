@extends('layouts/main')
@section('content')
<?php
$e = static fn ($value) => esc((string) ($value ?? ''));
?>
<div class="container-fluid py-4">
    @if(!empty($staffBill))
    <div class="alert alert-info">
        @if($staffBill['payer'] === 'personal')
            Reimburse {{ $staffBill['full_name'] }} for this purchase. This payment is separate from cleaning earnings.
        @else
            Pay the supplier for this bill.
        @endif
        <a href="{{ route('staff-bills.show', [app('currentTenant')->slug, $staffBill['id']]) }}">View bill and receipt</a>
    </div>
    @endif
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h3 class="mb-1">Expense Payments #<?= (int) $expense['id'] ?></h3>
            <div class="text-muted"><?= $e($expense['property_name']) ?> / <?= $e($expense['unit_number'] ?? 'General company expense') ?> — <?= $e($expense['category_name']) ?></div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            @if($canCorrectPayments)<a href="{{ route('expenses.edit', [app('currentTenant')->slug, $expense['id']]) }}" class="btn btn-outline-primary">Edit expense</a>@endif
            <?php if (haven_can_access(site_url('expenses'), 'GET')): ?><a href="<?= site_url('expenses') ?>" class="btn btn-outline-secondary">Back to Expense Register</a><?php endif; ?>
        </div>
    </div>

    <?php if (haven_session()->getFlashdata('success')): ?>
        <div class="alert alert-success"><?= esc(haven_session()->getFlashdata('success')) ?></div>
    <?php endif; ?>
    <?php if (haven_session()->getFlashdata('error')): ?>
        <div class="alert alert-danger"><?= esc(haven_session()->getFlashdata('error')) ?></div>
    <?php endif; ?>
    @if($historicalPaid)
        <div class="alert alert-info">This expense has a historical paid balance of KES {{ number_format((float) $cachedPaid, 2) }} without individual payment entries. Further payments require reconciliation.
            @if($canCorrectPayments && $correctionsInstalled)<a href="{{ route('expenses.payments.historical.edit', [app('currentTenant')->slug, $expense['id']]) }}" class="d-inline-block ms-2">Correct historical paid balance</a>@endif
        </div>
    @elseif($reconciliationIssue)
        <div class="alert alert-warning">Payment records do not match the expense's saved payment total, or payments exceed the expense. New payments are blocked until this is reconciled.</div>
    @endif
    @if($canCorrectPayments && !$correctionsInstalled)<div class="alert alert-info">Payment correction is not available for this company yet. Ask your administrator to complete the update.</div>@endif

    <div class="card shadow-sm mb-3"><div class="card-body">
        <h5><?= $e($expense['description']) ?></h5>
        <p class="text-muted mb-3">Expense date: <?= $e($expense['expense_date']) ?> | Supplier: <?= $e($expense['supplier_name'] ?: 'Not specified') ?></p>
        <div class="row g-3">
            <div class="col-md-4"><div class="border rounded p-3"><div class="text-muted">Total Expense</div><h4>KES <?= number_format((float) $total, 2) ?></h4></div></div>
            <div class="col-md-4"><div class="border rounded p-3"><div class="text-muted">{{ $historicalPaid ? 'Historical paid balance' : 'Payments Recorded' }}</div><h4>KES <?= number_format((float) $paid, 2) ?></h4></div></div>
            <div class="col-md-4"><div class="border rounded p-3"><div class="text-muted">Outstanding</div><h4>KES <?= number_format((float) $balance, 2) ?></h4></div></div>
        </div>
    </div></div>

    <?php if ($canManage && !$reconciliationIssue && (float) $balance > 0): ?>
    <div class="card shadow-sm mb-3"><div class="card-body">
        <h5 class="mb-3">Record Additional Payment</h5>
        <?php if (haven_can_access(site_url('expenses/' . (int) $expense['id'] . '/payments'), 'POST')): ?><form method="post" action="<?= site_url('expenses/' . (int) $expense['id'] . '/payments') ?>">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-3"><label class="form-label">Payment Date</label><input type="date" name="payment_date" class="form-control" value="<?= esc(old('payment_date', date('Y-m-d'))) ?>" required></div>
                <div class="col-md-3"><label class="form-label">Amount (KES)</label><input type="number" name="amount" class="form-control" min="0.01" max="<?= esc($balance, 'attr') ?>" step="0.01" value="<?= esc(old('amount')) ?>" required></div>
                <div class="col-md-3"><label class="form-label">Method</label><select name="payment_method" class="form-select" required><option value="">Select</option><?php foreach (['mpesa'=>'M-Pesa','bank'=>'Bank Transfer','cash'=>'Cash'] as $value => $label): ?><option value="<?= esc($value) ?>" <?= old('payment_method') === $value ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-3"><label class="form-label">Reference</label><input type="text" name="reference" maxlength="100" class="form-control" value="<?= esc(old('reference')) ?>"></div>
                <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"><?= esc(old('notes')) ?></textarea></div>
                <div class="col-12"><button type="submit" class="btn btn-primary">Save Payment</button></div>
            </div>
        </form><?php endif; ?>
    </div></div>
    <?php endif; ?>

    <div class="card shadow-sm"><div class="card-body">
        <h5 class="mb-3">Payment History</h5>
        <div class="table-responsive"><table class="table table-striped align-middle">
            <thead><tr><th>Date</th><th>Method</th><th>Reference</th><th>Notes</th><th class="text-end">Amount (KES)</th>@if($canCorrectPayments && $correctionsInstalled)<th>Action</th>@endif</tr></thead>
            <tbody>
            <?php foreach ($payments as $payment): ?>
                <tr><td><?= $e($payment['payment_date']) ?></td><td><?= $e(strtoupper($payment['payment_method'])) ?></td><td><?= $e($payment['reference'] ?: '-') ?></td><td><?= $e($payment['notes'] ?: '-') ?></td><td class="text-end"><?= number_format((float) $payment['amount'], 2) ?></td>
                    @if($canCorrectPayments && $correctionsInstalled)<td><a href="{{ route('expenses.payments.edit', [app('currentTenant')->slug, $expense['id'], $payment['id']]) }}" class="btn btn-sm btn-outline-primary">Correct payment</a></td>@endif
                </tr>
            <?php endforeach; ?>
            <?php if (!$payments): ?><tr><td colspan="{{ $canCorrectPayments && $correctionsInstalled ? 6 : 5 }}" class="text-center text-muted py-4">No payments recorded.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div></div>
    @if($correctionsInstalled)@include('unit_expenses.payment_corrections')@endif
</div>
@endsection
