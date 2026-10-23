@extends('layouts/main')
@section('content')
<div class="container-fluid py-3">
    <h1 class="h3 mb-3">Monthly Host Commissions</h1>
    <form method="get" action="<?= site_url('reports/monthly-host-commissions') ?>" class="row g-2 mb-4">
        <div class="col-md-3"><label class="form-label" for="month">Collection month</label><input id="month" type="month" name="month" class="form-control" value="<?= esc($month) ?>" required></div>
        <div class="col-md-5"><label class="form-label" for="unit">Apartment</label><select id="unit" name="unit_id" class="form-select"><option value="0">All apartments</option>
            <?php foreach ($units as $unit): ?>
                <option value="<?= (int) $unit['id'] ?>" <?= $unitId === (int) $unit['id'] ? 'selected' : '' ?>><?= esc(($unit['property_name'] ?? '') . ' / ' . $unit['unit_number']) ?></option>
            <?php endforeach ?>
        </select></div>
        <div class="col-md-2 d-flex align-items-end"><button type="submit" class="btn btn-primary w-100">View Report</button></div>
    </form>
    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="card"><div class="card-body"><div class="text-muted">All guest collections</div><div class="h4 mb-0">KES <?= number_format((float) $totalCollections, 2) ?></div></div></div></div>
        <div class="col-md-4"><div class="card"><div class="card-body"><div class="text-muted">Calculated host commission</div><div class="h4 mb-0">KES <?= number_format((float) $totalCommission, 2) ?></div></div></div></div>
        <div class="col-md-4"><div class="card"><div class="card-body"><div class="text-muted">Collections requiring review</div><div class="h4 mb-0">KES <?= number_format((float) $unmatchedCollections, 2) ?></div></div></div></div>
    </div>
    <div class="card mb-4"><div class="card-header">Commission by apartment and agreement</div><div class="table-responsive"><table class="table table-striped mb-0"><thead><tr><th>Apartment</th><th>Host</th><th>Rate</th><th>Payments</th><th class="text-end">Collections</th><th class="text-end">Commission</th></tr></thead><tbody>
        <?php foreach ($rows as $row): ?><tr><td><?= esc($row['unit']) ?></td><td><?= esc($row['host_name']) ?></td><td><?= esc($row['commission_rate']) ?>%</td><td><?= (int) $row['payment_count'] ?></td><td class="text-end">KES <?= number_format((float) $row['collections'], 2) ?></td><td class="text-end">KES <?= number_format((float) $row['commission'], 2) ?></td></tr><?php endforeach ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="text-muted text-center py-4">No matched collections for this month.</td></tr><?php endif ?>
    </tbody></table></div></div>
    <?php if ($unmatched || $ambiguous): ?>
        <div class="alert alert-warning">Some payments could not be assigned safely. Fix missing or overlapping host agreement dates, then reload the report. These collections are included in the collections total but excluded from calculated commission.</div>
        <div class="card"><div class="card-header">Payments requiring review</div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Issue</th><th>Payment date</th><th>Apartment</th><th>Reservation</th><th class="text-end">Amount</th></tr></thead><tbody>
        <?php foreach (['No agreement' => $unmatched, 'Overlapping agreements' => $ambiguous] as $reason => $items): ?>
            <?php foreach ($items as $item): ?><tr><td><?= esc($reason) ?></td><td><?= esc($item['payment_date']) ?></td><td><?= esc($item['unit']) ?></td><td>#<?= (int) $item['reservation_id'] ?></td><td class="text-end">KES <?= number_format((float) $item['amount'], 2) ?></td></tr><?php endforeach ?>
        <?php endforeach ?>
        </tbody></table></div></div>
    <?php endif ?>
    <p class="small text-muted mt-3">Commission is calculated per guest payment using the agreement active on the payment date. This report shows commission earned, not host payments made. Payments without a valid agreement require review.</p>
</div>
@endsection
