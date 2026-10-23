@extends('layouts/main')
@section('content')
<div class="container-fluid py-3">
    <h3 class="fw-bold mb-1">Record Unit Expense</h3>
    <p class="text-muted">Record cleaning, maintenance, refills and other unit costs.</p>

    <?php if (haven_session()->getFlashdata('error')): ?>
        <div class="alert alert-danger"><?= esc(haven_session()->getFlashdata('error')) ?></div>
    <?php endif; ?>
    <?php if (haven_session()->getFlashdata('success')): ?>
        <div class="alert alert-success"><?= esc(haven_session()->getFlashdata('success')) ?></div>
    <?php endif; ?>

    <div class="card shadow-sm border-0"><div class="card-body">
        <?php if (haven_can_access(site_url('expenses'), 'POST')): ?><form action="<?= site_url('expenses') ?>" method="post">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="unit_id">Unit *</label>
                    <select class="form-select" id="unit_id" name="unit_id" required>
                        <option value="">Select a unit</option>
                        <?php foreach ($units as $unit): ?>
                            <option value="<?= (int) $unit['id'] ?>" <?= (string) old('unit_id') === (string) $unit['id'] ? 'selected' : '' ?>>
                                <?= esc(($unit['property_name'] ?? 'Property') . ' — ' . ($unit['unit_number'] ?? 'Unit')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="category_id">Expense category *</label>
                    <select class="form-select" id="category_id" name="category_id" required>
                        <option value="">Select a category</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= (int) $category['id'] ?>" <?= (string) old('category_id') === (string) $category['id'] ? 'selected' : '' ?>><?= esc($category['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="expense_date">Expense date *</label>
                    <input class="form-control" type="date" id="expense_date" name="expense_date" value="<?= esc(old('expense_date', date('Y-m-d')), 'attr') ?>" required>
                </div>
                <div class="col-md-8">
                    <label class="form-label" for="description">Description *</label>
                    <input class="form-control" id="description" name="description" maxlength="255" value="<?= esc(old('description'), 'attr') ?>" placeholder="e.g. AC repair or tissue refill" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="amount">Total expense (KES) *</label>
                    <input class="form-control" type="number" id="amount" name="amount" min="0.01" max="9999999999.99" step="0.01" value="<?= esc(old('amount'), 'attr') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="amount_paid">Amount paid (KES) *</label>
                    <input class="form-control" type="number" id="amount_paid" name="amount_paid" min="0" max="9999999999.99" step="0.01" value="<?= esc(old('amount_paid', '0.00'), 'attr') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="supplier_name">Supplier / payee</label>
                    <input class="form-control" id="supplier_name" name="supplier_name" maxlength="150" value="<?= esc(old('supplier_name'), 'attr') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="payment_method">Payment method</label>
                    <select class="form-select" id="payment_method" name="payment_method">
                        <option value="">Not paid</option>
                        <?php foreach (['mpesa' => 'M-Pesa', 'bank' => 'Bank', 'cash' => 'Cash', 'other' => 'Other'] as $value => $label): ?>
                            <option value="<?= $value ?>" <?= old('payment_method') === $value ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="paid_at">Payment date</label>
                    <input class="form-control" type="date" id="paid_at" name="paid_at" value="<?= esc(old('paid_at'), 'attr') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="payment_reference">Payment reference</label>
                    <input class="form-control" id="payment_reference" name="payment_reference" maxlength="100" value="<?= esc(old('payment_reference'), 'attr') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label" for="notes">Notes</label>
                    <textarea class="form-control" id="notes" name="notes" rows="3"><?= esc(old('notes')) ?></textarea>
                </div>
            </div>
            <div class="alert alert-light border mt-3 mb-3">
                Payment status is calculated automatically: unpaid, partially paid or paid.
                Payment method and date are required if the amount paid is greater than zero.
            </div>
            <button class="btn btn-primary" type="submit">Save Expense</button>
        </form><?php endif; ?>
    </div></div>
</div>
@endsection
