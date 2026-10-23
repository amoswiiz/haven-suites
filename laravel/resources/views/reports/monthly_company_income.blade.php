@extends('layouts/main')
@section('content')
<?php $h = static fn ($value) => esc((string) $value); ?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <div>
        <h3 class="fw-bold mb-1">Monthly Company Income</h3>
        <p class="text-muted mb-0">Management commissions follow guest payment dates. Partner markup follows checkout dates for completed stays.</p>
    </div>
    <?php if (haven_can_access(site_url('dashboard'), 'GET')): ?><a class="btn btn-outline-secondary" href="<?= site_url('dashboard') ?>">Back to dashboard</a><?php endif; ?>
</div>

<?php if (haven_can_access(site_url('reports/monthly-company-income'), 'GET')): ?><form method="get" action="<?= site_url('reports/monthly-company-income') ?>" class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="month" class="form-label">Reporting month</label>
                <input type="month" class="form-control" name="month" id="month" value="<?= esc($month, 'attr') ?>" required>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100">View report</button>
            </div>
        </div>
    </div>
</form><?php endif; ?>

<div class="row g-3 mb-4">
    <?php foreach ([
        ['Management commissions', $managementCommission, 'wallet'],
        ['Partner booking markup', $partnerMarkup, 'handshake'],
        ['Total company income', $companyIncome, 'chart-no-axes-column-increasing'],
    ] as [$label, $amount, $icon]): ?>
        <div class="col-lg-4 col-md-6">
            <div class="hs-stat-card h-100">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                        <div class="hs-stat-label"><?= $h($label) ?></div>
                        <div class="fs-4 fw-bold">KES <?= $h($amount) ?></div>
                    </div>
                    <div class="hs-stat-icon"><i data-lucide="<?= esc($icon, 'attr') ?>"></i></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php if (!empty($issues)): ?>
    <div class="alert alert-warning mb-4" role="alert">
        <strong>Review required:</strong> Some entries could not be included in income calculations.
        <ul class="mb-2 mt-2">
            <?php foreach ($issues as $issue): ?><li><?= $h($issue) ?></li><?php endforeach; ?>
        </ul>
        <div>Managed collections requiring agreement review: KES <?= $h($unmatchedCollections) ?></div>
    </div>
<?php endif; ?>

<div class="hs-panel mb-4">
    <h5 class="fw-bold mb-3">Managed apartment commissions</h5>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr>
                <th>Apartment</th><th>Owner / Host</th><th>Rate</th>
                <th class="text-end">Payments</th><th class="text-end">Guest collections (KES)</th><th class="text-end">Commission (KES)</th>
            </tr></thead>
            <tbody>
                <?php foreach ($managedRows as $row): ?>
                    <tr>
                        <td><?= $h($row['unit']) ?></td><td><?= $h($row['host']) ?></td>
                        <td><?= $h($row['rate']) ?>%</td><td class="text-end"><?= $h($row['payments']) ?></td>
                        <td class="text-end"><?= $h($row['collections']) ?></td><td class="text-end fw-semibold"><?= $h($row['commission']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($managedRows)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No matching managed-apartment commission entries.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="hs-panel mb-4">
    <h5 class="fw-bold mb-3">Completed partner bookings</h5>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr>
                <th>Booking #</th><th>Apartment</th><th>Owner</th><th>Guest</th><th>Check-in</th><th>Checkout</th>
                <th class="text-end">Guest total (KES)</th><th class="text-end">Owner payout (KES)</th><th class="text-end">Markup (KES)</th>
            </tr></thead>
            <tbody>
                <?php foreach ($partnerRows as $row): ?>
                    <tr>
                        <td><?= $h($row['id']) ?></td><td><?= $h($row['apartment']) ?></td><td><?= $h($row['owner']) ?></td>
                        <td><?= $h($row['guest']) ?></td><td><?= $h($row['check_in']) ?></td><td><?= $h($row['check_out']) ?></td>
                        <td class="text-end"><?= $h($row['gross']) ?></td><td class="text-end"><?= $h($row['owner_amount']) ?></td>
                        <td class="text-end fw-semibold"><?= $h($row['markup']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($partnerRows)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No completed partner bookings checked out this month.</td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot class="table-light"><tr>
                <th colspan="6">Partner totals</th>
                <th class="text-end"><?= $h($partnerGross) ?></th>
                <th class="text-end"><?= $h($partnerOwner) ?></th>
                <th class="text-end"><?= $h($partnerMarkup) ?></th>
            </tr></tfoot>
        </table>
    </div>
</div>

<p class="text-muted small mb-0">This is an income report, not a cash-flow or net-profit statement. Partner owner payouts are not company income. Refunds, adjustments, and company operating expenses are not deducted here; reconcile them separately before treating the total as final.</p>
@endsection
