<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-4 gap-2 flex-wrap">
        <div><h3 class="fw-bold mb-1">Host Agreements</h3><p class="text-muted mb-0">Commission rates and agreement history by apartment.</p></div>
        <?php if ($canManage): ?><a href="<?= site_url('host-agreements/create') ?>" class="btn btn-primary">New Agreement</a><?php endif; ?>
    </div>
    <?php if (session('success')): ?><div class="alert alert-success"><?= esc(session('success')) ?></div><?php endif; ?>
    <?php if (session('error')): ?><div class="alert alert-danger"><?= esc(session('error')) ?></div><?php endif; ?>
    <form method="get" action="<?= site_url('host-agreements') ?>" class="card border-0 shadow-sm mb-4"><div class="card-body row g-2 align-items-end">
        <div class="col-md-6"><label class="form-label" for="unit_id">Apartment</label><select class="form-select" name="unit_id" id="unit_id"><option value="">All Apartments</option>
        <?php foreach ($units as $unit): ?><option value="<?= (int) $unit['id'] ?>" <?= $unitId === (int) $unit['id'] ? 'selected' : '' ?>><?= esc(($unit['property_name'] ?? '') . ' / ' . $unit['unit_number']) ?></option><?php endforeach; ?>
        </select></div><div class="col-md-3"><button class="btn btn-primary" type="submit">Apply Filter</button> <a class="btn btn-outline-secondary" href="<?= site_url('host-agreements') ?>">Reset</a></div>
    </div></form>
    <div class="card border-0 shadow-sm"><div class="card-body"><div class="table-responsive"><table class="table table-striped align-middle">
        <thead><tr><th>Property / Unit</th><th>Host</th><th>Phone</th><th>Commission</th><th>From</th><th>To</th><th>Status Today</th><?php if ($canManage): ?><th>Action</th><?php endif; ?></tr></thead>
        <tbody><?php foreach ($agreements as $agreement): ?>
            <?php $today = date('Y-m-d'); $active = $agreement['effective_from'] <= $today && (empty($agreement['effective_to']) || $agreement['effective_to'] >= $today); ?>
            <tr><td><?= esc(($agreement['property_name'] ?? '-') . ' / ' . $agreement['unit_number']) ?></td><td><?= esc($agreement['host_name']) ?></td><td><?= esc($agreement['host_phone'] ?? '-') ?></td><td><?= number_format((float) $agreement['commission_rate'], 2) ?>%</td><td><?= esc($agreement['effective_from']) ?></td><td><?= esc($agreement['effective_to'] ?? 'Open-ended') ?></td><td><span class="badge <?= $active ? 'bg-success' : 'bg-secondary' ?>"><?= $active ? 'Active' : 'Not active' ?></span></td>
            <?php if ($canManage): ?><td><a class="btn btn-sm btn-outline-primary" href="<?= site_url('host-agreements/' . (int) $agreement['id'] . '/edit') ?>">Edit</a></td><?php endif; ?></tr>
        <?php endforeach; ?>
        <?php if (!$agreements): ?><tr><td colspan="<?= $canManage ? 8 : 7 ?>" class="text-center text-muted py-4">No host agreements found.</td></tr><?php endif; ?>
        </tbody></table></div></div></div>
</div>
<?= $this->endSection() ?>
