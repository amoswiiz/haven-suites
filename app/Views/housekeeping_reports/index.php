<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<?php
$query = array_filter(
    [
        'month' => $filters['month'],
        'property_id' => $filters['property_id'],
        'unit_id' => $filters['unit_id'],
        'staff_id' => $filters['staff_id'],
    ],
    static fn($value) => $value !== null && $value !== ''
);

$exportUrl = site_url('reports/housekeeping/export')
    . '?' . http_build_query($query);
?>

<div class="container-fluid py-3">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h3 class="fw-bold mb-1">
                Housekeeping Financial Report
            </h3>
            <p class="text-muted mb-0">
                Cleaning costs by apartment, cleaner and month.
            </p>
        </div>

        <a href="<?= esc($exportUrl, 'attr') ?>"
           class="btn btn-outline-primary">
            Export Filtered CSV
        </a>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">

            <form method="get"
                  action="<?= site_url('reports/housekeeping') ?>">

                <div class="row g-3 align-items-end">

                    <div class="col-md-3">
                        <label class="form-label fw-semibold">
                            Month
                        </label>
                        <input type="month"
                               name="month"
                               class="form-control"
                               value="<?= esc($filters['month'], 'attr') ?>"
                               required>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-semibold">
                            Property
                        </label>

                        <select name="property_id"
                                id="reportProperty"
                                class="form-select">

                            <option value="">All Properties</option>

                            <?php foreach ($properties as $property): ?>
                                <option
                                    value="<?= (int) $property['id'] ?>"
                                    <?= (int) $filters['property_id'] === (int) $property['id'] ? 'selected' : '' ?>>
                                    <?= esc($property['name']) ?>
                                </option>
                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-semibold">
                            Apartment
                        </label>

                        <select name="unit_id"
                                id="reportUnit"
                                class="form-select">

                            <option value="">All Apartments</option>

                            <?php foreach ($units as $unit): ?>
                                <option
                                    value="<?= (int) $unit['id'] ?>"
                                    data-property="<?= (int) $unit['property_id'] ?>"
                                    <?= (int) $filters['unit_id'] === (int) $unit['id'] ? 'selected' : '' ?>>
                                    <?= esc($unit['unit_number']) ?>
                                </option>
                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-semibold">
                            Cleaner
                        </label>

                        <select name="staff_id"
                                class="form-select">

                            <option value="">All Cleaners / Staff</option>

                            <?php foreach ($staff as $member): ?>
                                <option
                                    value="<?= (int) $member['id'] ?>"
                                    <?= (int) $filters['staff_id'] === (int) $member['id'] ? 'selected' : '' ?>>
                                    <?= esc($member['full_name']) ?>
                                </option>
                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            Apply Filters
                        </button>

                        <a href="<?= site_url('reports/housekeeping') ?>"
                           class="btn btn-outline-secondary ms-2">
                            Reset
                        </a>
                    </div>

                </div>
            </form>

        </div>
    </div>

    <div class="row g-3 mb-4">

        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted mb-2">
                        Total Cleaning Charges
                    </div>
                    <h3 class="fw-bold mb-0">
                        KES <?= number_format($totalCost, 2) ?>
                    </h3>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted mb-2">
                        Completed / Verified Tasks
                    </div>
                    <h3 class="fw-bold mb-0">
                        <?= number_format($totalTasks) ?>
                    </h3>
                </div>
            </div>
        </div>

    </div>

    <div class="row g-3 mb-4">

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">

                    <h5 class="fw-bold mb-3">
                        Cleaning Charges by Staff
                    </h5>

                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Staff Member</th>
                                    <th>Tasks</th>
                                    <th class="text-end">KES</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($byCleaner as $item): ?>
                                    <tr>
                                        <td><?= esc($item['name']) ?></td>
                                        <td><?= (int) $item['count'] ?></td>
                                        <td class="text-end">
                                            <?= number_format($item['total'], 2) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (empty($byCleaner)): ?>
                                    <tr>
                                        <td colspan="3" class="text-muted">
                                            No records found.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">

                    <h5 class="fw-bold mb-3">
                        Cleaning Charges by Apartment
                    </h5>

                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Apartment</th>
                                    <th>Tasks</th>
                                    <th class="text-end">KES</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($byApartment as $item): ?>
                                    <tr>
                                        <td><?= esc($item['name']) ?></td>
                                        <td><?= (int) $item['count'] ?></td>
                                        <td class="text-end">
                                            <?= number_format($item['total'], 2) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (empty($byApartment)): ?>
                                    <tr>
                                        <td colspan="3" class="text-muted">
                                            No records found.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                </div>
            </div>
        </div>

    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">

            <h5 class="fw-bold mb-3">
                Cleaning Transactions
            </h5>

            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Completed</th>
                            <th>Apartment</th>
                            <th>Cleaner</th>
                            <th>Type</th>
                            <th>Bedrooms</th>
                            <th>Status</th>
                            <th class="text-end">Charge (KES)</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td>
                                    <?= esc($row['completed_at'] ?? '-') ?>
                                </td>

                                <td>
                                    <?= esc($row['property_name'] ?? '-') ?>
                                    /
                                    <?= esc($row['unit_number'] ?? '-') ?>
                                </td>

                                <td>
                                    <?= esc($row['cleaner_name'] ?? 'Unassigned') ?>
                                </td>

                                <td>
                                    <?= esc(ucwords(str_replace('_', ' ', $row['cleaning_type'] ?? '-'))) ?>
                                </td>

                                <td>
                                    <?= $row['bedrooms_cleaned'] !== null
                                        ? (int) $row['bedrooms_cleaned']
                                        : '-' ?>
                                </td>

                                <td>
                                    <span class="badge bg-secondary">
                                        <?= esc(ucfirst($row['status'])) ?>
                                    </span>
                                </td>

                                <td class="text-end">
                                    <?= number_format(
                                        (float) ($row['cleaning_charge'] ?? 0),
                                        2
                                    ) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (empty($rows)): ?>
                            <tr>
                                <td colspan="7"
                                    class="text-center text-muted py-4">
                                    No cleaning transactions match these filters.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>

</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const propertySelect = document.getElementById('reportProperty');
    const unitSelect = document.getElementById('reportUnit');

    if (!propertySelect || !unitSelect) {
        return;
    }

    function filterUnits() {
        const propertyId = propertySelect.value;

        Array.from(unitSelect.options).forEach(function (option) {
            if (!option.value) {
                return;
            }

            const matches = !propertyId
                || option.dataset.property === propertyId;

            option.hidden = !matches;
            option.disabled = !matches;

            if (!matches && option.selected) {
                unitSelect.value = '';
            }
        });
    }

    propertySelect.addEventListener('change', filterUnits);

    filterUnits();
});
</script>

<?= $this->endSection() ?>
