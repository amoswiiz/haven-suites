<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div><h3 class="fw-bold mb-1">Guest Payments</h3><p class="text-muted mb-0">Guest collections and reservation balances</p></div>
    </div>
    <?php if (session('error')): ?><div class="alert alert-danger"><?= esc(session('error')) ?></div><?php endif; ?>
    <form method="get" action="<?= site_url('payments/guests') ?>" class="card border-0 shadow-sm mb-4"><div class="card-body d-flex gap-2">
        <input class="form-control" name="q" value="<?= esc($search, 'attr') ?>" placeholder="Search guest, property or unit">
        <button class="btn btn-primary">Search</button><a class="btn btn-outline-secondary" href="<?= site_url('payments/guests') ?>">Reset</a>
    </div></form>
    <div class="card border-0 shadow-sm"><div class="card-body"><div class="table-responsive"><table class="table table-striped align-middle">
        <thead><tr><th>Reservation</th><th>Guest</th><th>Property / Unit</th><th>Check-in</th><th class="text-end">Guest Total (KES)</th><th class="text-end">Collected (KES)</th><th class="text-end">Balance (KES)</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($reservations as $r): ?>
            <tr><td>#<?= (int) $r['id'] ?></td><td><?= esc($r['guest_name']) ?></td>
            <td><?= esc(($r['property_name'] ?? '-') . ' / ' . ($r['unit_number'] ?? '-')) ?></td>
            <td><?= esc($r['check_in'] ?? '-') ?></td>
            <td class="text-end"><?= $r['guest_total'] === null ? 'Not set' : number_format((float) $r['guest_total'], 2) ?></td>
            <td class="text-end"><?= number_format((float) $r['paid'], 2) ?></td>
            <td class="text-end"><?= $r['balance'] === null ? '—' : number_format((float) $r['balance'], 2) ?><?= $r['overpaid'] ? ' (Review)' : '' ?></td>
            <td><a class="btn btn-sm btn-outline-primary" href="<?= site_url('payments/guests/' . (int) $r['id']) ?>">Payments</a></td></tr>
        <?php endforeach; ?>
        <?php if ($reservations === []): ?><tr><td colspan="8" class="text-center text-muted py-4">No reservations found.</td></tr><?php endif; ?>
        </tbody></table></div><p class="small text-muted mb-0">Showing up to 200 most recent matching reservations.</p></div></div>
</div>
<?= $this->endSection() ?>
