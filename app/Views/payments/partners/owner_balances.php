<?= $this->extend('layouts/main') ?>
<?= $this->section('styles') ?>
<style>
.hs-owner-page .hs-owner-title { color: #294780; }
.hs-owner-page .hs-owner-action { background:#294780; border-color:#294780; color:#fff; font-weight:600; }
.hs-owner-page .hs-owner-action:hover { background:#203a6a; border-color:#203a6a; color:#fff; }
.hs-owner-page .hs-owner-panel { background:#fff; border:1px solid #e5eaf2; border-radius:12px; box-shadow:0 3px 14px rgba(31,54,95,.045); padding:22px; }
.hs-owner-page .hs-owner-stat { background:#fff; border:1px solid #e5eaf2; border-radius:12px; box-shadow:0 3px 14px rgba(31,54,95,.045); padding:22px; height:100%; border-top:3px solid #294780; }
.hs-owner-page .hs-owner-stat.balance { border-top-color:#e98d50; }
.hs-owner-page .hs-owner-stat .label { font-size:13px; color:#66758c; font-weight:600; }
.hs-owner-page .hs-owner-stat .amount { font-size:clamp(19px,2vw,25px); color:#294780; font-weight:750; margin-top:10px; overflow-wrap:anywhere; }
.hs-owner-page .hs-owner-stat.balance .amount { color:#c56c2f; }
.hs-owner-page .hs-owner-table { margin-bottom:0; }
.hs-owner-page .hs-owner-table thead th { background:#f1f5fb; color:#294780; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.025em; border-bottom:2px solid #dce5f0; padding:14px 12px; white-space:nowrap; }
.hs-owner-page .hs-owner-table tbody td { padding:14px 12px; border-bottom-color:#edf1f6; }
.hs-owner-page .hs-owner-table tbody tr:hover td { background:#f8faff; }
.hs-owner-page .hs-owner-table .balance-amount { color:#c56c2f; font-weight:700; }
.hs-owner-page .hs-owner-table .settled-amount { color:#287449; font-weight:700; }
.hs-owner-page .hs-owner-table .statement-link { border-color:#294780; color:#294780; font-weight:600; white-space:nowrap; }
.hs-owner-page .hs-owner-table .statement-link:hover { background:#294780; color:white; }
.hs-owner-page .hs-owner-search { max-width:520px; }
.hs-owner-page .hs-owner-search:focus { border-color:#294780; box-shadow:0 0 0 .2rem rgba(41,71,128,.12); }
.hs-owner-page .hs-owner-note { border-left:3px solid #e98d50; padding-left:12px; }
</style>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="hs-owner-page">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h3 class="fw-bold mb-1 hs-owner-title">Partner Owner Balances</h3>
            <p class="text-muted mb-0">Owner balances grouped by partner apartment, across all non-cancelled partner bookings.</p>
        </div>
        <a href="<?= site_url('payments/partners') ?>" class="btn hs-owner-action">View Partner Bookings</a>
    </div>

    <div class="row g-3 mb-4">
        <?php foreach ([
            ['Total Owner Payouts', 'owed'],
            ['Payments Recorded', 'paid'],
            ['Outstanding Balance', 'balance'],
        ] as [$label, $key]): ?>
            <div class="col-md-4">
                <div class="hs-owner-stat <?= $key === 'balance' ? 'balance' : '' ?>">
                    <div class="label"><?= esc($label) ?></div>
                    <div class="amount">KES <?= number_format((float) $totals[$key], 2) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="hs-owner-panel mb-3">
        <label for="owner-search" class="form-label fw-semibold">Search owner or apartment</label>
        <input id="owner-search" type="search" class="form-control hs-owner-search" placeholder="Search owner, building or apartment">
        <div id="owner-count" class="small text-muted mt-2" aria-live="polite"></div>
    </div>

    <div class="hs-owner-panel">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <h5 class="fw-bold mb-0 hs-owner-title">Partner Apartment Balances</h5>
            <span class="text-muted small"><?= count($owners) ?> apartment(s)</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle hs-owner-table">
                <thead>
                    <tr>
                        <th>Owner</th>
                        <th>Apartment</th>
                        <th class="text-end">Bookings</th>
                        <th class="text-end">Owner Payouts</th>
                        <th class="text-end">Paid</th>
                        <th class="text-end">Outstanding</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($owners as $owner): ?>
                        <tr class="owner-row">
                            <td class="fw-semibold"><?= esc($owner['owner_name']) ?></td>
                            <td>
                                <div class="fw-semibold"><?= esc($owner['apartment_name']) ?></div>
                                <small class="text-muted"><?= esc($owner['building_name'] ?? '') ?></small>
                            </td>
                            <td class="text-end"><?= (int) $owner['bookings'] ?></td>
                            <td class="text-end text-nowrap">KES <?= number_format((float) $owner['owed'], 2) ?></td>
                            <td class="text-end text-nowrap">KES <?= number_format((float) $owner['paid'], 2) ?></td>
                            <td class="text-end text-nowrap <?= (float) $owner['balance'] > 0 ? 'balance-amount' : 'settled-amount' ?>">KES <?= number_format((float) $owner['balance'], 2) ?></td>
                            <td class="text-end"><a class="btn btn-sm btn-outline-primary statement-link" href="<?= site_url('payments/partners/owners/' . (int) $owner['partner_apartment_id']) ?>">Statement</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($owners)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No active partner booking balances found.</td></tr>
                    <?php endif; ?>
                    <tr id="owner-no-match" style="display:none"><td colspan="7" class="text-center text-muted py-4">No owners match your search.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <p class="small text-muted mt-3 hs-owner-note">Each row represents one partner apartment. If one owner supplies multiple apartments, they appear separately. Cancelled bookings are excluded; pending bookings are included as provisional obligations. Payments against cancelled bookings should be reconciled separately.</p>
</div>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script>
(function () {
    const search = document.getElementById('owner-search');
    const rows = Array.from(document.querySelectorAll('.owner-row'));
    const count = document.getElementById('owner-count');
    const noMatch = document.getElementById('owner-no-match');
    function update() {
        const term = search.value.trim().toLocaleLowerCase();
        let visible = 0;
        rows.forEach(function (row) {
            const show = row.textContent.toLocaleLowerCase().includes(term);
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        noMatch.style.display = rows.length > 0 && visible === 0 ? '' : 'none';
        count.textContent = 'Showing ' + visible + ' of ' + rows.length + ' partner apartments';
    }
    search.addEventListener('input', update);
    update();
})();
</script>
<?= $this->endSection() ?>
