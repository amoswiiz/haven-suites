<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<div class="container-fluid py-3">
    <h2>Host Payments</h2>
    <p class="text-muted">Most recent 200 reservations. Commissions are based on guest collections and effective host agreements.</p>
    <div class="table-responsive"><table class="table table-striped table-hover align-middle">
        <thead><tr><th>Reservation</th><th>Property</th><th>Unit</th><th class="text-end">Earned (KES)</th><th class="text-end">Paid (KES)</th><th class="text-end">Outstanding (KES)</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td>#<?= esc($row['id']) ?></td><td><?= esc($row['property_name'] ?? '') ?></td><td><?= esc($row['unit_number'] ?? '') ?></td>
                <td class="text-end"><?= esc($row['earned']) ?></td><td class="text-end"><?= esc($row['paid']) ?></td><td class="text-end"><?= esc($row['balance']) ?></td>
                <td><?= $row['needs_review'] ? '<span class="badge bg-warning text-dark">Review</span>' : '<span class="badge bg-success">OK</span>' ?></td>
                <td><a class="btn btn-sm btn-outline-primary" href="<?= site_url('payments/hosts/' . $row['id']) ?>">Details</a></td>
            </tr>
        <?php endforeach ?>
        <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted">No reservations found.</td></tr><?php endif ?>
        </tbody>
    </table></div>
</div>
<?= $this->endSection() ?>
