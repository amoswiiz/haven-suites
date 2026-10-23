<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<div class="container-fluid py-3">
<h2>Owner Statement Closing</h2>
<?php foreach (['error'=>'danger','success'=>'success'] as $type=>$style): if(session($type)): ?>
<div class="alert alert-<?= $style ?>"><?= esc(session($type)) ?></div>
<?php endif; endforeach ?>
<div class="alert alert-secondary">Approved statements lock their accounting month. Only finance administrators can reopen the latest approved month, with a recorded reason.</div>
<form method="get" action="<?= site_url('owner-statement-closing') ?>" class="row g-2 mb-4">
<div class="col-md-5"><label class="form-label">Unit</label><select class="form-select" name="unit_id" required><option value="">Choose unit</option>
<?php foreach($units as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $unitId === (int)$u['id'] ? 'selected' : '' ?>><?= esc(($u['property_name']??'').' — '.$u['unit_number']) ?></option><?php endforeach ?></select></div>
<div class="col-md-3"><label class="form-label">Month</label><input class="form-control" type="month" name="month" value="<?= esc($month) ?>" required></div>
<div class="col-md-2 align-self-end"><button class="btn btn-primary">View</button></div></form>
<?php if($unitId): ?>
<h5>Historical opening balance</h5>
<?php if($opening): ?>
<p>Effective <?= esc($opening['effective_month']) ?>: KES <?= esc($opening['amount']) ?>. <?= esc($opening['notes']??'') ?></p>
<?php else: ?>
<form method="post" action="<?= site_url('owner-statement-closing/opening') ?>" class="row g-2 mb-4">
<?= csrf_field() ?><input type="hidden" name="unit_id" value="<?= (int)$unitId ?>">
<div class="col-md-3"><label class="form-label">Effective month</label><input class="form-control" type="month" name="effective_month" value="<?= esc($month) ?>" required></div>
<div class="col-md-3"><label class="form-label">Brought-forward balance (KES)</label><input class="form-control" name="amount" type="number" step="0.01" required placeholder="Negative for deficit"></div>
<div class="col-md-4"><label class="form-label">Reconciliation notes</label><input class="form-control" name="notes" required></div>
<div class="col-md-2 align-self-end"><button class="btn btn-outline-primary">Save opening</button></div></form>
<?php endif ?>
<h5>Statement for <?= esc($month) ?></h5>
<?php if($error): ?><div class="alert alert-danger"><?= esc($error) ?></div><?php endif ?>
<?php if($current): ?>
<table class="table table-bordered" style="max-width:720px"><tbody>
<?php foreach(['opening_balance'=>'Opening balance','collected'=>'Collections','management_fee'=>'Management fee','expenses'=>'Unit expenses','entitlement'=>'Monthly result','paid'=>'Owner remittances','closing_balance'=>'Closing balance'] as $key=>$label): ?>
<tr><th><?= esc($label) ?></th><td class="text-end">KES <?= esc($current[$key]) ?></td></tr>
<?php endforeach ?></tbody></table>
<?php foreach($current['issues'] as $issue): ?><div class="alert alert-danger"><?= esc($issue) ?></div><?php endforeach ?>
<?php endif ?>
<?php if($snapshot && !empty($canReopen) && !empty($periodLocked) && !empty($isLatestApproved)): ?>
<div class="card mb-3"><div class="card-body">
<h5 class="card-title">Reopen approved month</h5>
<p class="text-muted">The original approved statement will be archived before the month is unlocked. Reapprove after making corrections.</p>
<form method="post" action="<?= site_url('owner-statement-closing/reopen') ?>">
<?= csrf_field() ?>
<input type="hidden" name="unit_id" value="<?= (int)$unitId ?>">
<input type="hidden" name="month" value="<?= esc($month) ?>">
<label class="form-label" for="reopen-reason">Reason for reopening (at least 15 characters)</label>
<textarea id="reopen-reason" name="reason" class="form-control mb-2" minlength="15" maxlength="5000" rows="3" required></textarea>
<button type="submit" class="btn btn-outline-danger" onclick="return confirm('Reopen this month? Its approved snapshot will be archived and corrections will be permitted until reapproval.')">Reopen accounting month</button>
</form></div></div>
<?php endif ?>
<?php if(!empty($revisions)): ?>
<h5>Previous approved versions</h5>
<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Approved at</th><th>Approved by</th><th>Reopened at</th><th>Reopened by</th><th>Reason</th><th>SHA-256</th></tr></thead><tbody>
<?php foreach($revisions as $revision): ?><tr>
<td><?= esc($revision['approved_at']) ?></td><td>#<?= (int)$revision['approved_by'] ?></td>
<td><?= esc($revision['reopened_at']) ?></td><td>#<?= (int)$revision['reopened_by'] ?></td>
<td><?= esc($revision['reopen_reason']) ?></td><td><code><?= esc($revision['statement_hash']) ?></code></td>
</tr><?php endforeach ?></tbody></table></div>
<?php endif ?>
<?php if($snapshot): ?>
<div class="alert alert-info">Snapshot approved <?= esc($snapshot['approved_at']) ?> by user #<?= (int)$snapshot['approved_by'] ?>.</div>
<?php $saved=json_decode($snapshot['statement_json'],true)??[]; if($current && hash('sha256',json_encode($current))!==$snapshot['statement_hash']): ?>
<div class="alert alert-danger">Current figures differ from the approved snapshot. Reconcile source transactions before proceeding.</div>
<?php endif ?>
<?php elseif($current && !$current['issues']): ?>
<form method="post" action="<?= site_url('owner-statement-closing/approve') ?>">
<?= csrf_field() ?><input type="hidden" name="unit_id" value="<?= (int)$unitId ?>"><input type="hidden" name="month" value="<?= esc($month) ?>">
<button class="btn btn-primary" onclick="return confirm('Approve this monthly snapshot? This will lock the accounting month.')">Approve statement snapshot</button>
</form>
<?php endif ?>
<?php endif ?>
</div>
<?= $this->endSection() ?>
