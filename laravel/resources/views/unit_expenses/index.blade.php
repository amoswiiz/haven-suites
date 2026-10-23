@extends('layouts/main')

@section('content')

<?php
$canManage = haven_auth()->user() !== null
    && haven_auth()->user()->can('finance.manage');

$f = $filters;
?>

<div class="container-fluid py-3">

    <!-- Page Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

        <div>
            <h3 class="fw-bold mb-1">Expense Register</h3>
            <p class="text-muted mb-0">
                Track cleaning, maintenance, refills and other unit expenses.
            </p>
        </div>

        <?php if ($canManage): ?>
            <a href="<?= site_url('expenses/create') ?>"
               class="btn btn-primary">
                <i data-lucide="plus" class="me-1"></i>
                Record Expense
            </a>
        <?php endif; ?>

    </div>

    <!-- Filters -->
    <form action="<?= site_url('expenses') ?>"
          method="get"
          class="card border-0 shadow-sm mb-4">

        <div class="card-body">

            <div class="row g-3">

                <!-- Month -->
                <div class="col-md-3">
                    <label for="month" class="form-label">
                        Expense Month
                    </label>

                    <input
                        type="month"
                        class="form-control"
                        name="month"
                        id="month"
                        value="<?= esc($f['month'] ?? '', 'attr') ?>">
                </div>

                <!-- Property -->
                <div class="col-md-3">
                    <label for="property_id" class="form-label">
                        Property
                    </label>

                    <select name="property_id"
                            id="property_id"
                            class="form-select">

                        <option value="">All Properties</option>

                        <?php foreach ($properties as $property): ?>
                            <option
                                value="<?= (int) $property['id'] ?>"
                                <?= (int) ($f['propertyId'] ?? 0) === (int) $property['id']
                                    ? 'selected'
                                    : '' ?>>

                                <?= esc($property['name']) ?>

                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <!-- Unit -->
                <div class="col-md-3">
                    <label for="unit_id" class="form-label">
                        Unit
                    </label>

                    <select name="unit_id"
                            id="unit_id"
                            class="form-select">

                        <option value="">All Units</option>

                        <?php foreach ($units as $unit): ?>
                            <option
                                value="<?= (int) $unit['id'] ?>"
                                data-property="<?= (int) $unit['property_id'] ?>"
                                <?= (int) ($f['unitId'] ?? 0) === (int) $unit['id']
                                    ? 'selected'
                                    : '' ?>>

                                <?= esc(
                                    ($unit['property_name'] ?? '') .
                                    ' — ' .
                                    $unit['unit_number']
                                ) ?>

                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <!-- Category -->
                <div class="col-md-3">
                    <label for="category_id" class="form-label">
                        Category
                    </label>

                    <select name="category_id"
                            id="category_id"
                            class="form-select">

                        <option value="">All Categories</option>

                        <?php foreach ($categories as $category): ?>
                            <option
                                value="<?= (int) $category['id'] ?>"
                                <?= (int) ($f['categoryId'] ?? 0) === (int) $category['id']
                                    ? 'selected'
                                    : '' ?>>

                                <?= esc($category['name']) ?>

                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <!-- Payment Status -->
                <div class="col-md-3">
                    <label for="status" class="form-label">
                        Payment Status
                    </label>

                    <select name="status"
                            id="status"
                            class="form-select">

                        <option value="">All Statuses</option>

                        <?php foreach ([
                            'unpaid' => 'Unpaid',
                            'partial' => 'Partially Paid',
                            'paid' => 'Paid'
                        ] as $value => $label): ?>

                            <option
                                value="<?= esc($value, 'attr') ?>"
                                <?= ($f['status'] ?? '') === $value
                                    ? 'selected'
                                    : '' ?>>

                                <?= esc($label) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>
                </div>

                <!-- Search -->
                <div class="col-md-6">
                    <label for="q" class="form-label">
                        Search
                    </label>

                    <input
                        type="search"
                        class="form-control"
                        name="q"
                        id="q"
                        value="<?= esc($f['search'] ?? '', 'attr') ?>"
                        placeholder="Description, supplier or payment reference">
                </div>

                <!-- Filter Buttons -->
                <div class="col-md-3 d-flex align-items-end gap-2">

                    <button type="submit"
                            class="btn btn-primary flex-fill">
                        Apply Filters
                    </button>

                    <a href="<?= site_url('expenses') ?>"
                       class="btn btn-outline-secondary">
                        Reset
                    </a>

                </div>

            </div>

        </div>

    </form>

    <!-- Financial Summary -->
    <div class="row g-3 mb-4">

        <!-- Total Expenses -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">

                    <div class="text-muted small mb-2">
                        Total Expenses
                    </div>

                    <h4 class="fw-bold mb-0">
                        KES <?= number_format((float) ($totals['expenses'] ?? 0), 2) ?>
                    </h4>

                </div>
            </div>
        </div>

        <!-- Amount Paid -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">

                    <div class="text-muted small mb-2">
                        Amount Paid
                    </div>

                    <h4 class="fw-bold mb-0">
                        KES <?= number_format((float) ($totals['paid'] ?? 0), 2) ?>
                    </h4>

                </div>
            </div>
        </div>

        <!-- Outstanding -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">

                    <div class="text-muted small mb-2">
                        Outstanding Balance
                    </div>

                    <h4 class="fw-bold mb-0">
                        KES <?= number_format((float) ($totals['outstanding'] ?? 0), 2) ?>
                    </h4>

                </div>
            </div>
        </div>

    </div>

    <!-- Expense Transactions -->
    <div class="card border-0 shadow-sm">

        <div class="card-body">

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">

                <h5 class="fw-bold mb-0">
                    Expense Transactions
                </h5>

                <span class="text-muted small">
                    <?= number_format((int) ($totals['count'] ?? 0)) ?>
                    records
                </span>

            </div>

            <div class="table-responsive">

                <table class="table table-striped table-hover align-middle">

                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Property / Unit</th>
                            <th>Category</th>
                            <th>Description</th>
                            <th>Supplier</th>
                            <th class="text-end">Amount</th>
                            <th class="text-end">Paid</th>
                            <th class="text-end">Balance</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($expenses as $expense): ?>

                            <?php
                            $expenseId = (int) $expense['id'];

                            $amount = (float) ($expense['amount'] ?? 0);

                            $amountPaid = (float) ($expense['amount_paid'] ?? 0);

                            $balance = max(0, $amount - $amountPaid);

                            $status = (string) ($expense['payment_status'] ?? 'unpaid');

                            $badgeClass = match ($status) {
                                'paid' => 'bg-success',
                                'partial' => 'bg-warning text-dark',
                                default => 'bg-secondary',
                            };

                            $statusLabel = match ($status) {
                                'paid' => 'Paid',
                                'partial' => 'Partially Paid',
                                default => 'Unpaid',
                            };
                            ?>

                            <tr>

                                <!-- Date -->
                                <td class="text-nowrap">
                                    <?= esc($expense['expense_date']) ?>
                                </td>

                                <!-- Property and Unit -->
                                <td>
                                    <?= esc(
                                        ($expense['property_name'] ?? '-') .
                                        ' / ' .
                                        ($expense['unit_number'] ?? '-')
                                    ) ?>
                                </td>

                                <!-- Category -->
                                <td>
                                    <?= esc($expense['category_name'] ?? '-') ?>
                                </td>

                                <!-- Description -->
                                <td>
                                    <?= esc($expense['description']) ?>
                                </td>

                                <!-- Supplier -->
                                <td>
                                    <?= esc($expense['supplier_name'] ?: '-') ?>
                                </td>

                                <!-- Expense Amount -->
                                <td class="text-end text-nowrap">
                                    <?= number_format($amount, 2) ?>
                                </td>

                                <!-- Amount Paid -->
                                <td class="text-end text-nowrap">
                                    <?= number_format($amountPaid, 2) ?>
                                </td>

                                <!-- Outstanding Balance -->
                                <td class="text-end text-nowrap">

                                    <span class="<?= $balance > 0
                                        ? 'text-danger fw-semibold'
                                        : 'text-success' ?>">

                                        <?= number_format($balance, 2) ?>

                                    </span>

                                </td>

                                <!-- Payment Status -->
                                <td>
                                    <span class="badge <?= $badgeClass ?>">
                                        <?= esc($statusLabel) ?>
                                    </span>
                                </td>

                                <!-- Actions -->
                                <td class="text-nowrap">

                                    <a
                                        href="<?= site_url(
                                            'expenses/' .
                                            $expenseId .
                                            '/payments'
                                        ) ?>"
                                        class="btn btn-sm btn-outline-primary">

                                        <i data-lucide="wallet" class="me-1"></i>
                                        Payments

                                    </a>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        <?php if (empty($expenses)): ?>

                            <tr>
                                <td colspan="10"
                                    class="text-center text-muted py-4">

                                    No expenses found for the selected filters.

                                </td>
                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</div>

@endsection
