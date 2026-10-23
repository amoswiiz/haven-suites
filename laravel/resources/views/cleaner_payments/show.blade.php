@extends('layouts/main')
@section('content')

<?php
$receiptNo = 'HS-CP-' . str_pad((string) $payment['id'], 6, '0', STR_PAD_LEFT);
$allocatedTotal = array_sum(array_map(static fn ($row) => (float) $row['amount'], $allocations));
$method = match ($payment['payment_method']) {
    'mpesa' => 'M-Pesa',
    'bank' => 'Bank Transfer',
    'cash' => 'Cash',
    default => ucfirst((string) $payment['payment_method']),
};
?>

<style>
.hs-receipt { max-width: 900px; margin: 0 auto; }
.hs-receipt-header { border-bottom: 2px solid #243d59; padding-bottom: 20px; }
.hs-receipt-table th { background: #f2f5f8; }
@media print {
    .hs-no-print, .hs-sidebar, header, .navbar, .hs-topbar { display: none !important; }
    body, main, .container-fluid, .hs-receipt { margin: 0 !important; padding: 0 !important; max-width: none !important; }
    .hs-receipt .card { border: none !important; box-shadow: none !important; }
    .hs-receipt .card-body { padding: 0 !important; }
    @page { size: A4; margin: 15mm; }
}
</style>

<div class="container-fluid py-3">
    <div class="hs-receipt">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 hs-no-print">
            <a class="btn btn-outline-secondary" href="<?= site_url('payments/cleaners') ?>">Back to Payments</a>
            <button type="button" class="btn btn-primary" onclick="window.print()">Print / Save as PDF</button>
        </div>

        <?php if (haven_session()->getFlashdata('success')): ?>
            <div class="alert alert-success hs-no-print"><?= esc(haven_session()->getFlashdata('success')) ?></div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4 p-md-5">
                <div class="d-flex flex-wrap justify-content-between gap-3 hs-receipt-header mb-4">
                    <div>
                        <h2 class="fw-bold mb-1">HAVEN SUITES</h2>
                        <div class="text-muted">BnB Management System</div>
                        <div class="small text-muted">Cleaner Payment Record</div>
                    </div>
                    <div class="text-md-end">
                        <h4 class="fw-bold mb-1">PAYMENT RECEIPT</h4>
                        <div><strong><?= esc($receiptNo) ?></strong></div>
                        <div class="text-muted small">Recorded payment transaction</div>
                    </div>
                </div>

                <div class="row g-4 mb-4">
                    <div class="col-md-6">
                        <div class="text-muted small mb-1">Paid To</div>
                        <h5 class="fw-semibold mb-2"><?= esc($payment['cleaner_name'] ?: 'Unknown Staff Member') ?></h5>
                        <div><strong>Payment Date:</strong> <?= esc($payment['payment_date']) ?></div>
                        <div><strong>Method:</strong> <?= esc($method) ?></div>
                    </div>
                    <div class="col-md-6">
                        <div><strong>Reference:</strong> <?= esc($payment['reference'] ?: 'Not provided') ?></div>
                        <div><strong>Recorded By (User ID):</strong> <?= (int) $payment['recorded_by'] ?></div>
                        <div><strong>Recorded At:</strong> <?= esc($payment['created_at'] ?: '-') ?></div>
                    </div>
                </div>

                <h5 class="fw-bold mb-3">Cleaning Tasks Covered</h5>
                <div class="table-responsive mb-3">
                    <table class="table table-bordered align-middle hs-receipt-table">
                        <thead>
                            <tr>
                                <th>Task</th>
                                <th>Property / Apartment</th>
                                <th>Completed</th>
                                <th>Bedrooms</th>
                                <th class="text-end">Allocated (KES)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allocations as $row): ?>
                                <tr>
                                    <td>#<?= (int) $row['housekeeping_id'] ?></td>
                                    <td>
                                        <?= esc($row['property_name'] ?: '-') ?> /
                                        <?= esc($row['unit_number'] ?: '-') ?>
                                        <div class="small text-muted"><?= esc(ucwords(str_replace('_', ' ', (string) ($row['cleaning_type'] ?: 'Cleaning')))) ?></div>
                                    </td>
                                    <td><?= esc($row['completed_at'] ?: '-') ?></td>
                                    <td><?= $row['bedrooms_cleaned'] === null ? '-' : (int) $row['bedrooms_cleaned'] ?></td>
                                    <td class="text-end"><?= number_format((float) $row['amount'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$allocations): ?>
                                <tr><td colspan="5" class="text-center text-muted">No task allocations found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="4" class="text-end">Allocated Total</th>
                                <th class="text-end">KES <?= number_format($allocatedTotal, 2) ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="d-flex justify-content-end mb-4">
                    <div class="text-end">
                        <div class="text-muted">Payment Amount</div>
                        <h3 class="fw-bold">KES <?= number_format((float) $payment['amount'], 2) ?></h3>
                    </div>
                </div>

                <?php if (!empty($payment['notes'])): ?>
                    <div class="mb-4">
                        <strong>Notes</strong>
                        <div class="text-muted" style="white-space:pre-wrap"><?= esc($payment['notes']) ?></div>
                    </div>
                <?php endif; ?>

                <hr>
                <p class="text-muted small mb-0">
                    This receipt records a payment entered into Haven Suites Management System.
                    It is not an independent confirmation from M-Pesa or a bank.
                    Payment allocations shown above apply only to this transaction.
                </p>
            </div>
        </div>
    </div>
</div>

@endsection
