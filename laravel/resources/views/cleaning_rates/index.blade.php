@extends('layouts/main')

@section('content')

<div class="container-fluid py-3">

    <div class="mb-4">
        <h3 class="fw-bold mb-1">Cleaning Rates Settings</h3>
        <p class="text-muted">
            Configure standard cleaning charges by the number
            of bedrooms cleaned.
        </p>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">

            <form method="post"
                  action="<?= site_url('settings/cleaning-rates/update') ?>">

                <?= csrf_field() ?>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Cleaning Scope</th>
                                <th>Standard Charge (KES)</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($rates as $rate): ?>
                                <tr>
                                    <td>
                                        <strong>
                                            <?= (int) $rate['bedrooms'] ?>
                                            Bedroom<?= (int) $rate['bedrooms'] === 1 ? '' : 's' ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <input
                                            type="number"
                                            name="rates[<?= (int) $rate['id'] ?>]"
                                            class="form-control"
                                            min="0"
                                            max="99999999.99"
                                            step="0.01"
                                            required
                                            value="<?= esc($rate['amount'], 'attr') ?>"
                                            style="max-width:240px">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="alert alert-light border mt-3">
                    Changes apply to newly calculated cleaning charges.
                    Charges already saved against housekeeping tasks
                    will not automatically change.
                </div>

                <button type="submit" class="btn btn-primary">
                    Save Cleaning Rates
                </button>

                <a href="<?= site_url('housekeeping') ?>"
                   class="btn btn-outline-secondary ms-2">
                    Back to Housekeeping
                </a>

            </form>

        </div>
    </div>

</div>

@endsection
