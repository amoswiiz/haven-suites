@extends('layouts/main')
@section('content')
<div class="container-fluid py-3">
<h2>Monthly Owner Remittances</h2>
<?php if (session('error')): ?><div class="alert alert-danger"><?= esc(session('error')) ?></div><?php endif ?>
<?php if (session('success')): ?><div class="alert alert-success"><?= esc(session('success')) ?></div><?php endif ?>
<form method="get" action="<?= site_url('owner-remittances') ?>" class="row g-2 mb-4">
<div class="col-md-3"><label class="form-label">Accounting month</label><input class="form-control" type="month" name="month" value="<?= esc($month) ?>" required></div>
<div class="col-md-5"><label class="form-label">Apartment</label><select name="unit_id" class="form-select" required><option value="">Select apartment</option>
<?php foreach ($units as $unit): ?><option value="<?= (int)$unit['id'] ?>" <?= $unitId === (int)$unit['id'] ? 'selected' : '' ?>><?= esc(($unit['property_name'] ?? '') . ' — ' . $unit['unit_number']) ?></option><?php endforeach ?>
</select></div><div class="col-md-2 align-self-end"><button class="btn btn-primary">View statement</button></div></form>
<?php if ($report): ?>
<h5><?= esc(($selected['property_name'] ?? '') . ' — ' . $selected['unit_number']) ?> | <?= esc($month) ?></h5>
<p><a class="btn btn-outline-secondary btn-sm" href="<?= site_url('owner-remittances/export') . '?month=' . rawurlencode($month) . '&unit_id=' . (int)$unitId ?>">Download CSV statement</a></p>
<table class="table table-bordered" style="max-width:720px"><tbody>
<tr><th>Opening balance (carried forward)</th><td class="text-end">KES <?= esc($report['opening_balance']) ?></td></tr>
<tr><th>Guest collections received</th><td class="text-end">KES <?= esc($report['collected']) ?></td></tr>
<tr><th>Haven Suites management fee</th><td class="text-end">KES <?= esc($report['management_fee']) ?></td></tr>
<tr><th>Unit expenses incurred</th><td class="text-end">KES <?= esc($report['expenses']) ?></td></tr>
<tr class="table-light"><th>Owner entitlement</th><th class="text-end">KES <?= esc($report['entitlement']) ?></th></tr>
<tr><th>Previous deficit recovered this month</th><td class="text-end">KES <?= esc($report['deficit_recovered']) ?></td></tr>
<tr><th>Remaining deficit to recover</th><td class="text-end">KES <?= esc($report['deficit_to_recover']) ?></td></tr>
<tr><th>Remitted against this month</th><td class="text-end">KES <?= esc($report['paid']) ?></td></tr>
<tr class="table-light"><th>Closing owner balance</th><th class="text-end">KES <?= esc($report['closing_balance']) ?></th></tr>
</tbody></table>
<?php foreach ($report['issues'] as $issue): ?><div class="alert alert-warning"><?= esc($issue) ?></div><?php endforeach ?>
<?php if ($canManage && !$report['issues'] && (float)$report['outstanding'] > 0): ?>
<h5>Record owner remittance</h5>
<form method="post" action="<?= site_url('owner-remittances') ?>" class="row g-2 mb-4">
<?= csrf_field() ?>
<input type="hidden" name="unit_id" value="<?= (int)$unitId ?>"><input type="hidden" name="month" value="<?= esc($month) ?>">
<div class="col-md-2"><label class="form-label">Payment date</label><input class="form-control" type="date" name="payment_date" value="<?= date('Y-m-d') ?>" required></div>
<div class="col-md-2"><label class="form-label">Amount (KES)</label><input class="form-control" name="amount" type="number" min="0.01" step="0.01" max="<?= esc($report['outstanding']) ?>" required></div>
<div class="col-md-2"><label class="form-label">Method</label><select class="form-select" name="payment_method"><option value="mpesa">M-Pesa</option><option value="bank">Bank</option><option value="cash">Cash</option></select></div>
<div class="col-md-2"><label class="form-label">Reference</label><input class="form-control" name="reference" maxlength="100"></div>
<div class="col-md-3"><label class="form-label">Notes</label><input class="form-control" name="notes"></div>
<div class="col-md-1 align-self-end"><button class="btn btn-primary">Save</button></div>
</form>
<?php endif ?>
<h5>Remittance history for <?= esc($month) ?></h5>
<table class="table table-striped"><thead><tr><th>Date paid</th><th>Method</th><th>Reference</th><th>Notes</th><th class="text-end">Amount (KES)</th></tr></thead><tbody>
<?php foreach ($history as $payment): ?><tr><td><?= esc($payment['payment_date']) ?></td><td><?= esc($payment['payment_method']) ?></td><td><?= esc($payment['reference'] ?? '') ?></td><td><?= esc($payment['notes'] ?? '') ?></td><td class="text-end"><?= number_format((float)$payment['amount'],2) ?></td></tr><?php endforeach ?>
<?php if (!$history): ?><tr><td colspan="5">No remittances recorded for this month.</td></tr><?php endif ?>
</tbody></table>
<?php endif ?>
</div>
@endsection
