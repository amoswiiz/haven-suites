<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<?php
$canManage = auth()->user() !== null
    && auth()->user()->can('finance.manage');

$paymentCount = count($payments);
$totalPayments = 0.0;

foreach ($payments as $payment) {
    $totalPayments += (float) ($payment['amount'] ?? 0);
}
?>

<div class="container-fluid py-3">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h3 class="fw-bold mb-1">
                Cleaner Payments
            </h3>
            <p class="text-muted mb-0">
                View cleaning payments, transaction history
                and printable receipts.
            </p>
        </div>

        <div class="d-flex flex-wrap gap-2">

            <a href="<?= site_url('payments/cleaners/balances') ?>"
               class="btn btn-outline-secondary">
                Outstanding Balances
            </a>

            <?php if ($canManage): ?>
                <a href="<?= site_url('payments/cleaners/create') ?>"
                   class="btn btn-primary">
                    Record Payment
                </a>
            <?php endif; ?>

        </div>
    </div>

    <div class="row g-3 mb-4">

        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted mb-2">
                        Total Recorded Payments
                    </div>
                    <h3 class="fw-bold mb-0" id="visibleTotal">
                        KES <?= number_format($totalPayments, 2) ?>
                    </h3>
                    <small class="text-muted">
                        Updates when filters are applied
                    </small>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted mb-2">
                        Payment Transactions
                    </div>
                    <h3 class="fw-bold mb-0" id="visibleCount">
                        <?= number_format($paymentCount) ?>
                    </h3>
                    <small class="text-muted">
                        Matching transactions
                    </small>
                </div>
            </div>
        </div>

    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">

            <div class="row g-3">

                <div class="col-md-5">
                    <label class="form-label fw-semibold">
                        Search Payments
                    </label>
                    <input
                        type="search"
                        id="paymentSearch"
                        class="form-control"
                        placeholder="Cleaner name or transaction reference">
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">
                        Payment Method
                    </label>
                    <select id="paymentMethod" class="form-select">
                        <option value="">All Methods</option>
                        <option value="mpesa">M-Pesa</option>
                        <option value="bank">Bank Transfer</option>
                        <option value="cash">Cash</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">
                        Payment Month
                    </label>
                    <input
                        type="month"
                        id="paymentMonth"
                        class="form-control">
                </div>

                <div class="col-md-1 d-flex align-items-end">
                    <button
                        type="button"
                        id="resetPaymentFilters"
                        class="btn btn-outline-secondary w-100">
                        Reset
                    </button>
                </div>

            </div>

        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">

            <h5 class="fw-bold mb-3">
                Payment Transactions
            </h5>

            <div class="table-responsive">

                <table class="table table-striped align-middle">

                    <thead>
                        <tr>
                            <th>Receipt No.</th>
                            <th>Payment Date</th>
                            <th>Cleaner</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th class="text-end">Amount (KES)</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody id="paymentTableBody">

                        <?php foreach ($payments as $payment): ?>

                            <?php
                            $paymentId = (int) $payment['id'];

                            $receiptNo = 'HS-CP-' .
                                str_pad(
                                    (string) $paymentId,
                                    6,
                                    '0',
                                    STR_PAD_LEFT
                                );

                            $cleanerName = (string) (
                                $payment['cleaner_name'] ?? 'Unknown Cleaner'
                            );

                            $method = strtolower(
                                (string) ($payment['payment_method'] ?? '')
                            );

                            $methodLabel = match ($method) {
                                'mpesa' => 'M-Pesa',
                                'bank' => 'Bank Transfer',
                                'cash' => 'Cash',
                                default => ucfirst($method),
                            };

                            $reference = (string) (
                                $payment['reference'] ?? ''
                            );

                            $date = (string) (
                                $payment['payment_date'] ?? ''
                            );

                            $amount = (float) (
                                $payment['amount'] ?? 0
                            );
                            ?>

                            <tr
                                class="payment-row"
                                data-search="<?= esc(
                                    strtolower(
                                        $cleanerName . ' ' .
                                        $reference . ' ' .
                                        $receiptNo
                                    ),
                                    'attr'
                                ) ?>"
                                data-method="<?= esc($method, 'attr') ?>"
                                data-month="<?= esc(substr($date, 0, 7), 'attr') ?>"
                                data-amount="<?= esc((string) $amount, 'attr') ?>">

                                <td>
                                    <span class="fw-semibold">
                                        <?= esc($receiptNo) ?>
                                    </span>
                                </td>

                                <td>
                                    <?= esc($date) ?>
                                </td>

                                <td>
                                    <?= esc($cleanerName) ?>
                                </td>

                                <td>
                                    <?= esc($methodLabel) ?>
                                </td>

                                <td>
                                    <?= $reference !== ''
                                        ? esc($reference)
                                        : '-' ?>
                                </td>

                                <td class="text-end fw-semibold">
                                    <?= number_format($amount, 2) ?>
                                </td>

                                <td>
                                    <a
                                        href="<?= site_url(
                                            'payments/cleaners/' . $paymentId
                                        ) ?>"
                                        class="btn btn-sm btn-outline-primary">
                                        View Receipt
                                    </a>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

            <div id="noPaymentsMessage"
                 class="text-center text-muted py-4"
                 <?= !empty($payments) ? 'hidden' : '' ?>>
                No payment transactions match the selected filters.
            </div>

            <div class="d-flex justify-content-between align-items-center mt-3">
                <small class="text-muted" id="paymentResultsText">
                    Showing <?= number_format($paymentCount) ?> transactions
                </small>
            </div>

        </div>
    </div>

</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const searchInput = document.getElementById('paymentSearch');
    const methodInput = document.getElementById('paymentMethod');
    const monthInput = document.getElementById('paymentMonth');
    const resetButton = document.getElementById('resetPaymentFilters');

    const rows = Array.from(
        document.querySelectorAll('.payment-row')
    );

    const totalElement = document.getElementById('visibleTotal');
    const countElement = document.getElementById('visibleCount');
    const resultsElement = document.getElementById('paymentResultsText');
    const emptyElement = document.getElementById('noPaymentsMessage');

    const currencyFormatter = new Intl.NumberFormat('en-KE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });

    function applyFilters() {

        const search = searchInput.value.trim().toLowerCase();
        const method = methodInput.value;
        const month = monthInput.value;

        let count = 0;
        let total = 0;

        rows.forEach(function (row) {

            const rowSearch = row.dataset.search || '';
            const rowMethod = row.dataset.method || '';
            const rowMonth = row.dataset.month || '';

            const matchesSearch = !search ||
                rowSearch.includes(search);

            const matchesMethod = !method ||
                rowMethod === method;

            const matchesMonth = !month ||
                rowMonth === month;

            const visible = matchesSearch &&
                matchesMethod &&
                matchesMonth;

            row.hidden = !visible;

            if (visible) {
                count++;
                total += Number(row.dataset.amount || 0);
            }

        });

        totalElement.textContent =
            'KES ' + currencyFormatter.format(total);

        countElement.textContent = count.toLocaleString('en-KE');

        resultsElement.textContent =
            'Showing ' + count.toLocaleString('en-KE') +
            ' transaction' + (count === 1 ? '' : 's');

        emptyElement.hidden = count !== 0;
    }

    searchInput.addEventListener('input', applyFilters);
    methodInput.addEventListener('change', applyFilters);
    monthInput.addEventListener('change', applyFilters);

    resetButton.addEventListener('click', function () {
        searchInput.value = '';
        methodInput.value = '';
        monthInput.value = '';

        applyFilters();
    });

    applyFilters();

});
</script>

<?= $this->endSection() ?>
