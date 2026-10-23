@extends('layouts/main')
@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h3 class="fw-bold mb-1">Monthly Unit Expense Report</h3>
            <p class="text-muted mb-0">Expenses incurred in <?= esc(date('F Y', strtotime($month . '-01'))) ?>, with payment balances as at <?= esc($reportEnd) ?>.</p>
        </div>
        <button type="button" class="btn btn-outline-secondary d-print-none" onclick="window.print()">Print Report</button>
    </div>

    <?php if (haven_can_access(site_url('reports/monthly-unit-expenses'), 'GET')): ?><form method="get" action="<?= site_url('reports/monthly-unit-expenses') ?>" class="card border-0 shadow-sm mb-4 d-print-none">
        <div class="card-body"><div class="row g-3 align-items-end">
            <div class="col-md-3"><label class="form-label" for="month">Month</label><input class="form-control" type="month" id="month" name="month" value="<?= esc($month, 'attr') ?>" required></div>
            <div class="col-md-3"><label class="form-label" for="property_id">Property</label><select class="form-select" id="property_id" name="property_id"><option value="">All properties</option>
                <?php foreach ($properties as $property): ?><option value="<?= (int) $property['id'] ?>" <?= $propertyId === (int) $property['id'] ? 'selected' : '' ?>><?= esc($property['name']) ?></option><?php endforeach; ?>
            </select></div>
            <div class="col-md-4"><label class="form-label" for="unit_id">Unit</label><select class="form-select" id="unit_id" name="unit_id"><option value="">All units</option>
                <?php foreach ($units as $unit): ?><option data-property="<?= (int) $unit['property_id'] ?>" value="<?= (int) $unit['id'] ?>" <?= $unitId === (int) $unit['id'] ? 'selected' : '' ?>><?= esc(($unit['property_name'] ?? '') . ' / ' . $unit['unit_number']) ?></option><?php endforeach; ?>
            </select></div>
            <div class="col-md-2 d-flex gap-2"><button class="btn btn-primary flex-fill" type="submit">Apply</button><?php if (haven_can_access(site_url('reports/monthly-unit-expenses'), 'GET')): ?><a class="btn btn-outline-secondary" href="<?= site_url('reports/monthly-unit-expenses') ?>">Reset</a><?php endif; ?></div>
        </div></div>
    </form><?php endif; ?>

    <div class="row g-3 mb-4">
        <?php foreach (['incurred' => 'Expenses Incurred', 'paidByEnd' => 'Paid by Month-End', 'outstandingAtEnd' => 'Outstanding at Month-End'] as $key => $label): ?>
            <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small mb-1"><?= esc($label) ?></div><h4 class="mb-0 fw-bold">KES <?= number_format($totals[$key], 2) ?></h4></div></div></div>
        <?php endforeach; ?>
    </div>

    <div class="card border-0 shadow-sm mb-4"><div class="card-body">
        <h5 class="mb-3">Expense Breakdown</h5>
        <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Category</th><th class="text-end">Amount (KES)</th></tr></thead><tbody>
            <?php foreach ($categories as $category => $amount): ?><tr><td><?= esc($category) ?></td><td class="text-end"><?= number_format($amount, 2) ?></td></tr><?php endforeach; ?>
            <?php if (!$categories): ?><tr><td colspan="2" class="text-muted text-center py-3">No expenses recorded for this selection.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div></div>

    <div class="card border-0 shadow-sm"><div class="card-body">
        <h5 class="mb-3">Expense Details</h5>
        <div class="table-responsive"><table class="table table-striped align-middle"><thead><tr><th>Date</th><th>Property / Unit</th><th>Category</th><th>Description</th><th class="text-end">Incurred</th><th class="text-end">Paid by Month-End</th><th class="text-end">Balance at Month-End</th><th class="d-print-none">Action</th></tr></thead><tbody>
            <?php foreach ($expenses as $expense): ?><tr>
                <td class="text-nowrap"><?= esc($expense['expense_date']) ?></td>
                <td><?= esc(($expense['property_name'] ?? '-') . ' / ' . ($expense['unit_number'] ?? '-')) ?></td>
                <td><?= esc($expense['category_name'] ?? '-') ?></td>
                <td><?= esc($expense['description']) ?></td>
                <td class="text-end"><?= number_format((float) $expense['amount'], 2) ?></td>
                <td class="text-end"><?= number_format((float) $expense['paid_to_end'], 2) ?></td>
                <td class="text-end"><?= number_format((float) $expense['balance_at_end'], 2) ?></td>
                <td class="d-print-none"><?php if (haven_can_access(site_url('expenses/' . (int) $expense['id'] . '/payments'), 'GET')): ?><a class="btn btn-sm btn-outline-primary" href="<?= site_url('expenses/' . (int) $expense['id'] . '/payments') ?>">Payments</a><?php endif; ?></td>
            </tr><?php endforeach; ?>
            <?php if (!$expenses): ?><tr><td colspan="8" class="text-center text-muted py-4">No expenses for this month and selection.</td></tr><?php endif; ?>
        </tbody></table></div>
        <p class="small text-muted mb-0">Payment balances are reconstructed from dated expense payments and cleaner payment allocations. Payments made after the report month are excluded from month-end balances. This report includes expenses dated within the selected month; it is not a complete cash-outflow report for that month.</p>
    </div></div>
</div>
<script>
(function () {
    const property = document.getElementById('property_id');
    const unit = document.getElementById('unit_id');
    function filterUnits() {
        const selected = property.value;
        Array.from(unit.options).forEach(function (option) {
            if (!option.value) return;
            option.hidden = !!selected && option.dataset.property !== selected;
        });
        if (unit.selectedOptions.length && unit.selectedOptions[0].hidden) unit.value = '';
    }
    property.addEventListener('change', filterUnits);
    filterUnits();
})();
</script>
@endsection
