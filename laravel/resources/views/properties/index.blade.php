@extends('layouts/main')

@section('content')

<?php
$propertyList = $properties ?? [];
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">

    <div>
        <h3 class="fw-bold mb-1">Properties Management</h3>
        <p class="text-muted mb-0">
            Manage buildings and properties under Haven Suites.
        </p>
    </div>

    <a href="<?= site_url('properties/create') ?>"
       class="btn btn-primary">
        + Add Property
    </a>

</div>

<div class="row g-3 mb-4">

    <div class="col-md-4">
        <div class="hs-stat-card">
            <div class="hs-stat-label">
                Total Properties
            </div>
            <div class="hs-stat-value">
                <?= count($propertyList) ?>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="hs-stat-card">
            <div class="hs-stat-label">
                Active Properties
            </div>
            <div class="hs-stat-value">
                <?= count(array_filter(
                    $propertyList,
                    fn($p) => $p['status'] === 'active'
                )) ?>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="hs-stat-card">
            <div class="hs-stat-label">
                Inactive Properties
            </div>
            <div class="hs-stat-value">
                <?= count(array_filter(
                    $propertyList,
                    fn($p) => $p['status'] === 'inactive'
                )) ?>
            </div>
        </div>
    </div>

</div>

<div class="card border-0 shadow-sm">

    <div class="card-body">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold mb-0">Property Directory</h5>

            <span class="text-muted small">
                <?= count($propertyList) ?> Properties
            </span>
        </div>

        <div class="table-responsive">

            <table class="table table-hover align-middle">

                <thead>
                    <tr>
                        <th>Property Name</th>
                        <th>Location</th>
                        <th>Address</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                <?php foreach ($propertyList as $property): ?>

                    <tr>

                        <td>
                            <div class="fw-semibold">
                                <?= esc($property['name']) ?>
                            </div>

                            <?php if (!empty($property['description'])): ?>
                                <div class="text-muted small">
                                    <?= esc(
                                        mb_strimwidth(
                                            $property['description'],
                                            0,
                                            90,
                                            '...'
                                        )
                                    ) ?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?= esc($property['location']) ?>
                        </td>

                        <td>
                            <?= esc($property['address'] ?? '-') ?>
                        </td>

                        <td>
                            <?php if ($property['status'] === 'active'): ?>
                                <span class="badge bg-success">
                                    Active
                                </span>
                            <?php else: ?>
                                <span class="badge bg-secondary">
                                    Inactive
                                </span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <a href="<?= site_url('properties/edit/' . $property['id']) ?>"
                               class="btn btn-sm btn-outline-primary">
                                Edit
                            </a>
                        </td>

                    </tr>

                <?php endforeach; ?>

                <?php if (empty($propertyList)): ?>

                    <tr>
                        <td colspan="5"
                            class="text-center py-5 text-muted">

                            <i data-lucide="building-2"
                               class="mb-3"></i>

                            <p>No properties registered yet.</p>

                            <a href="<?= site_url('properties/create') ?>"
                               class="btn btn-primary">
                                Add Your First Property
                            </a>

                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

@endsection
