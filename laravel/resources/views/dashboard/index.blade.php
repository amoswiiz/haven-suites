@extends('layouts/main')
@section('styles')
<style>
.hs-dash-hero{background:linear-gradient(115deg,#1c2f56 0%,#294780 72%,#385d96 100%);color:#fff;border-radius:18px;padding:30px 32px;position:relative;overflow:hidden;box-shadow:0 12px 30px rgba(28,47,86,.13)}
.hs-dash-hero:after{content:"";position:absolute;right:-65px;top:-95px;width:300px;height:300px;border:48px solid rgba(233,141,80,.14);border-radius:50%;pointer-events:none}
.hs-dash-eyebrow{font-size:11px;font-weight:800;letter-spacing:.15em;text-transform:uppercase;color:#f2aa77}
.hs-dash-hero h2{font-size:clamp(22px,3vw,32px);font-weight:800;letter-spacing:-.02em}
.hs-dash-hero p{color:#e2e9f5;max-width:650px}
.hs-dash-brand{display:inline-flex;align-items:center;gap:10px;font-size:12px;font-weight:700;color:#fff;border:1px solid rgba(255,255,255,.3);border-radius:99px;padding:7px 12px}
.hs-dash-brand i{color:#f2aa77}
.hs-dash-section{display:flex;justify-content:space-between;align-items:end;gap:12px;flex-wrap:wrap;margin:27px 0 14px;border-left:4px solid #e98d50;padding-left:13px}
.hs-dash-section h4{color:#23395f;font-size:19px;margin:0;font-weight:800}
.hs-dash-section p{margin:4px 0 0;color:#64748b;font-size:12px}
.hs-dash-filter,.hs-dash-card,.hs-dash-panel{background:#fff;border:1px solid #e5eaf2;border-radius:14px;box-shadow:0 3px 16px rgba(31,52,86,.045)}
.hs-dash-filter{padding:20px;margin-top:18px}
.hs-dash-card{padding:22px;height:100%;border-top:3px solid transparent;transition:box-shadow .15s}
.hs-dash-card:hover{box-shadow:0 9px 24px rgba(31,52,86,.10)}
.hs-dash-card.accent{border-top-color:#e98d50}
.hs-dash-card .hs-stat-label{font-size:12px;color:#65748b;font-weight:600}
.hs-dash-card .hs-stat-value,.hs-dash-card .fs-4{color:#203456;font-weight:800}
.hs-dash-card .hs-stat-icon{background:#edf2fc;color:#294780;border-radius:12px;padding:11px;display:flex;align-items:center;justify-content:center}
.hs-dash-panel{padding:22px;height:100%}
.hs-dash-panel h5{color:#23395f;font-size:16px;font-weight:800;margin-bottom:18px}
.hs-dash-btn{background:#294780!important;border-color:#294780!important;color:#fff!important}
.hs-dash-btn:hover{background:#1c2f56!important}
.hs-dash-quicklinks{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}
.hs-dash-quicklinks a{font-size:12px;text-decoration:none;background:rgba(255,255,255,.13);color:#fff;border:1px solid rgba(255,255,255,.22);border-radius:8px;padding:8px 11px;position:relative}
.hs-dash-quicklinks a:hover{background:rgba(255,255,255,.23)}
.hs-dash-occupancy{font-size:42px;color:#294780;font-weight:800;line-height:1.2}
.hs-dash-progress{height:9px;background:#eaf0f7;border-radius:20px;overflow:hidden;margin:15px 0}
.hs-dash-progress span{display:block;height:100%;background:linear-gradient(90deg,#294780,#e98d50);border-radius:20px}
.hs-dash-table th{color:#294780;font-size:12px;text-transform:uppercase;background:#f2f5fa}
@media(max-width:575px){.hs-dash-hero{padding:22px}.hs-dash-card,.hs-dash-panel{padding:17px}}

/* Readable hero: override global heading and button rules */
.hs-dash-hero,.hs-dash-hero *{box-sizing:border-box}
.hs-dash-hero h2,.hs-dash-hero h2.fw-bold{color:#ffffff!important;text-shadow:0 1px 2px rgba(0,0,0,.12)}
.hs-dash-hero .hs-dash-eyebrow{color:#ffb782!important}
.hs-dash-hero p{color:#f1f5ff!important}
.hs-dash-hero .hs-dash-quicklinks a,.hs-dash-hero .hs-dash-quicklinks a:link,.hs-dash-hero .hs-dash-quicklinks a:visited{color:#ffffff!important;background:rgba(255,255,255,.17)!important;border:1px solid rgba(255,255,255,.48)!important;font-weight:700;opacity:1!important}
.hs-dash-hero .hs-dash-quicklinks a:hover,.hs-dash-hero .hs-dash-quicklinks a:focus-visible{background:#ffffff!important;color:#23395f!important;border-color:#ffffff!important}
.hs-dash-hero > *{position:relative;z-index:1}
/* Brand all lower dashboard cards with navy headings and orange trim */
.hs-dash-section ~ .row .hs-dash-card{border-top:3px solid #e98d50}
.hs-dash-card .hs-stat-label,.hs-dash-panel .hs-stat-label{color:#405b87!important;font-weight:700}
.hs-dash-card .hs-stat-value,.hs-dash-panel .hs-stat-value{color:#23395f!important;font-weight:800}
.hs-dash-panel{border-top:3px solid #e98d50}
.hs-dash-panel a:not(.btn){color:#294780!important;font-weight:700}
.hs-dash-panel a:not(.btn):hover{color:#c46b32!important}
.hs-dash-panel .hs-stat-icon,.hs-dash-card .hs-stat-icon{color:#294780!important;background:#edf2fc!important}
.hs-dash-panel h5{border-bottom:1px solid #e8edf4;padding-bottom:12px}
.hs-dash-table thead th{background:#edf2fa!important;color:#294780!important}
.hs-activity-link{display:block;text-decoration:none!important;color:#23395f!important;border-top:3px solid #e98d50}
.hs-activity-link:focus-visible{outline:3px solid #294780;outline-offset:4px}
.hs-activity-link:hover{transform:translateY(-2px)}
.hs-activity-link .hs-stat-value{font-size:32px;line-height:1.2;margin:10px 0}
.hs-activity-link .hs-activity-scope{color:#64748b;font-size:12px}
</style>
@endsection
@section('content')


<section aria-labelledby="daily-operations-heading" class="mb-4">
  <div class="hs-dash-section mt-0">
    <div><h4 id="daily-operations-heading">Today's Operations</h4><p><?= esc(date('l, j F Y')) ?> &middot; Across all apartments</p></div>
  </div>
  <div class="row g-3">
    <?php foreach ([
        ["Today's Check-ins", $todayCheckins, 'reservations?activity=checkins', 'reservations.view', 'Arriving today'],
        ["Today's Check-outs", $todayCheckouts, 'reservations?activity=checkouts', 'reservations.view', 'Departing today'],
        ['Pending Cleanings', $pendingCleaning, 'housekeeping?activity=pending', 'housekeeping.view', 'All dates · Pending, assigned or in progress'],
        ['Open Maintenance', $openMaintenance, 'maintenance?activity=open', 'maintenance.view', 'All dates · Unresolved issues'],
    ] as [$label, $count, $path, $permission, $scope]): ?>
      <?php if (haven_auth()->user()?->can($permission)): ?>
      <div class="col-12 col-sm-6 col-xl-3">
        <?php if (haven_can_access(esc(site_url($path), 'attr'), 'GET')): ?><a class="hs-dash-card hs-activity-link" href="<?= esc(site_url($path), 'attr') ?>">
          <div class="hs-stat-label"><?= esc($label) ?></div>
          <div class="hs-stat-value"><?= number_format($count) ?></div>
          <div class="hs-activity-scope"><?= esc($scope) ?></div>
          <div class="small mt-3">View records &rarr;</div>
        </a><?php endif; ?>
      </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
</section>

<section class="hs-dash-hero">
  <div class="hs-dash-eyebrow mb-2">HAVEN SUITES · OPERATIONS PLATFORM</div>
  <h2 class="mb-2">Welcome to Haven Suites</h2>
  <p class="mb-0">Manage apartments, guest experiences, bookings and finances from one place.</p>
  <div class="hs-dash-quicklinks">
    <?php if (haven_can_access(site_url('reservations'), 'GET')): ?><a href="<?= site_url('reservations') ?>">Reservations &rarr;</a><?php endif; ?>
    <?php if (haven_can_access(site_url('availability'), 'GET')): ?><a href="<?= site_url('availability') ?>">Availability &rarr;</a><?php endif; ?>
    <?php if (!empty($canViewFinance)): ?><?php if (haven_can_access(site_url('invoices'), 'GET')): ?><a href="<?= site_url('invoices') ?>">Invoices &rarr;</a><?php endif; ?><?php endif; ?>
    <?php if (haven_can_access(site_url('housekeeping'), 'GET')): ?><a href="<?= site_url('housekeeping') ?>">Housekeeping &rarr;</a><?php endif; ?>
    <?php if (haven_can_access(site_url('maintenance'), 'GET')): ?><a href="<?= site_url('maintenance') ?>">Maintenance &rarr;</a><?php endif; ?>
  </div>
</section>
        <?php if (!empty($canViewFinance)): ?>
        <?php if (haven_can_access(site_url('dashboard'), 'GET')): ?><form method="get" action="<?= site_url('dashboard') ?>" class="hs-dash-filter mb-4">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4"><label class="form-label">Accounting Month</label>
                        <input type="month" name="month" class="form-control" value="<?= esc($financeFilters['month'], 'attr') ?>" required></div>
                    <div class="col-md-4"><label class="form-label">Apartment Unit</label>
                        <select name="unit_id" class="form-select"><option value="0">All Apartments</option>
                            <?php foreach ($financeUnits as $unit): ?>
                                <option value="<?= (int)$unit['id'] ?>" <?= (int)$financeFilters['unit_id'] === (int)$unit['id'] ? 'selected' : '' ?>><?= esc($unit['property_name'] . ' – ' . $unit['unit_number']) ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="col-md-4 d-flex gap-2"><button class="btn btn-primary hs-dash-btn flex-fill" type="submit">Apply Filters</button><?php if (haven_can_access(site_url('dashboard'), 'GET')): ?><a class="btn btn-outline-secondary" href="<?= site_url('dashboard') ?>">Reset</a><?php endif; ?></div>
                </div>
            </div>
        </form><?php endif; ?>

        <!-- Company-wide income is independent of the Apartment Unit filter above. -->
        <div class="hs-dash-section">
            <div>
                <h4 class="fw-bold mb-1">Company Income</h4>
                <p class="text-muted small mb-0">Company-wide for <?= esc(date('F Y', strtotime($financeMonth . '-01'))) ?>, regardless of the apartment filter. Before operating expenses, refunds and adjustments.</p>
            </div>
            <?php if (haven_can_access(site_url('reports/monthly-company-income') . '?month=' . rawurlencode($financeMonth), 'GET')): ?><a class="btn btn-outline-primary btn-sm" href="<?= site_url('reports/monthly-company-income') . '?month=' . rawurlencode($financeMonth) ?>">View Detailed Income Report &rarr;</a><?php endif; ?>
        </div>
        <div class="row g-3 mb-3">
            <?php foreach ([
                ['Managed Apartment Commissions', $companyIncomeSummary['managed'] ?? '0.00', 'hand-coins'],
                ['Partner Booking Markup', $companyIncomeSummary['partner'] ?? '0.00', 'building-2'],
                ['Total Company Income', $companyIncomeSummary['total'] ?? '0.00', 'chart-no-axes-combined'],
            ] as [$incomeLabel, $incomeValue, $incomeIcon]): ?>
                <div class="col-xl-4 col-md-6">
                    <div class="hs-dash-card accent">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="hs-stat-label"><?= esc($incomeLabel) ?></div>
                                <div class="fs-4 fw-bold">KES <?= number_format((float) $incomeValue, 2) ?></div>
                            </div>
                            <div class="hs-stat-icon flex-shrink-0"><i data-lucide="<?= esc($incomeIcon, 'attr') ?>"></i></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($companyIncomeSummary['issues'])): ?>
            <div class="alert alert-warning small mb-4">
                <?= (int) $companyIncomeSummary['issues'] ?> income record(s) need review. Totals exclude records with missing or invalid agreement/payout data.
                <?php if (haven_can_access(site_url('reports/monthly-company-income') . '?month=' . rawurlencode($financeMonth), 'GET')): ?><a href="<?= site_url('reports/monthly-company-income') . '?month=' . rawurlencode($financeMonth) ?>">Review issues</a><?php endif; ?>.
            </div>
        <?php endif; ?>

        <div class="hs-dash-section">
            <h4 class="fw-bold mb-0">Financial Overview</h4>
            <span class="text-muted small">Collections, expenses and remittances for <?= esc(date('F Y', strtotime($financeMonth . '-01'))) ?>; outstanding balances are all-time</span>
        </div>
        <?php
            $financeCards = [
                ['Guest Collections', $financialSummary['collections'], 'wallet', 'payments/guests'],
                ['Unit Expenses', $financialSummary['expenses'], 'receipt', 'expenses'],
                ['Owner Remittances', $financialSummary['remittances'], 'hand-coins', 'owner-remittances'],
                ['Guest Outstanding', $financialSummary['outstanding'], 'credit-card', 'payments/guests'],
            ];
        ?>
        <div class="row g-3 mb-4">
            <?php foreach ($financeCards as [$label, $value, $icon, $path]): ?>
            <div class="col-xl-3 col-md-6">
                <div class="hs-dash-card">
                    <div class="d-flex align-items-start justify-content-between gap-2">
                        <div>
                            <div class="hs-stat-label"><?= esc($label) ?></div>
                            <div class="fs-4 fw-bold">KES <?= number_format((float) $value, 2) ?></div>
                            <?php if (haven_can_access(site_url($path), 'GET')): ?><a class="small text-decoration-none" href="<?= site_url($path) ?>">View details &rarr;</a><?php endif; ?>
                        </div>
                        <div class="hs-stat-icon flex-shrink-0"><i data-lucide="<?= esc($icon, 'attr') ?>"></i></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-lg-8">
                <div class="hs-dash-panel">
                    <h5>Collections vs Expenses — Last 6 Months</h5>
                    <div style="height:300px"><canvas id="hsFinanceTrend" role="img" aria-label="Monthly collections and expenses chart"></canvas></div>
                    <p class="text-muted small mt-2 mb-0">Based on payment and expense dates; amounts in KES.</p>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="hs-dash-panel">
                    <h5>Estimated Occupancy — <?= esc(date('F Y', strtotime($financeMonth . '-01'))) ?></h5>
                    <div class="hs-dash-occupancy"><?= $occupancySummary['percent'] === null ? 'N/A' : esc(number_format($occupancySummary['percent'], 1)) . '%' ?></div>
                    <div class="hs-dash-progress" aria-hidden="true"><span style="width:<?= esc(number_format(max(0, min(100, (float) ($occupancySummary['percent'] ?? 0))), 1, '.', ''), 'attr') ?>%"></span></div>
                    <p class="text-muted mb-1"><?= number_format($occupancySummary['occupied']) ?> occupied unit-nights</p>
                    <p class="text-muted small mb-0">Of <?= number_format($occupancySummary['capacity']) ?> available unit-nights across currently active units. Check-out dates excluded; overlapping bookings counted once.</p>
                </div>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.8/dist/chart.umd.min.js"></script>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const el = document.getElementById('hsFinanceTrend');
            if (!el || !window.Chart) return;
            const trend = <?= json_encode($financeTrend, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            new Chart(el, {
                type: 'bar',
                data: {
                    labels: trend.map(item => item.label),
                    datasets: [
                        {label: 'Collections', data: trend.map(item => item.collections), backgroundColor: '#294780'},
                        {label: 'Expenses', data: trend.map(item => item.expenses), backgroundColor: '#e98d50'}
                    ]
                },
                options: {responsive: true, maintainAspectRatio: false, scales: {y: {beginAtZero: true}}, plugins: {tooltip: {callbacks: {label: ctx => ctx.dataset.label + ': KES ' + Number(ctx.parsed.y).toLocaleString()}}}}
            });
        });
        </script>
        <?php endif; ?>

        <?php
        $stats = [
            ['Apartment Units', $totalUnits, 'house'],
            ['Active Apartments', $activeUnits, 'check-circle'],
            ['Reservations', $totalReservations, 'calendar-check'],
            ['Active Bookings', $confirmedReservations, 'calendar-days'],
            ['Active Staff', $totalStaff, 'users'],


        ];
        ?>

        <div class="hs-dash-section"><div><h4>Operations Snapshot</h4><p>Current apartment, booking and team activity</p></div></div>
        <div class="row g-4 mb-4">

            <?php foreach ($stats as [$label, $value, $icon]): ?>
            <div class="col-xl-3 col-md-6">
                <div class="hs-dash-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="hs-stat-label">
                                <?= esc($label) ?>
                            </div>
                            <div class="hs-stat-value">
                                <?= number_format($value) ?>
                            </div>
                        </div>

                        <div class="hs-stat-icon">
                            <i data-lucide="<?= esc($icon) ?>"></i>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

        </div>

        <div class="hs-dash-section"><div><h4>Upcoming &amp; Outstanding</h4><p>Upcoming arrivals and maintenance priorities</p></div></div>
        <div class="row g-4">

            <div class="col-lg-7">
                <div class="hs-dash-panel">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5>Upcoming Check-ins</h5>
                        <?php if (haven_can_access(site_url('reservations'), 'GET')): ?><a href="<?= site_url('reservations') ?>"
                           class="small text-decoration-none">
                            View All
                        </a><?php endif; ?>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle hs-dash-table">
                            <thead>
                                <tr>
                                    <th>Guest</th>
                                    <th>Apartment</th>
                                    <th>Check-in</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($upcomingCheckins as $booking): ?>
                                <tr>
                                    <td><?= esc($booking['guest_name']) ?></td>
                                    <td>
                                        <?= esc($booking['property_name']) ?>
                                        / <?= esc($booking['unit_number']) ?>
                                    </td>
                                    <td><?= esc($booking['check_in']) ?></td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if (empty($upcomingCheckins)): ?>
                                <tr>
                                    <td colspan="3"
                                        class="text-center text-muted py-4">
                                        No upcoming check-ins.
                                    </td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="hs-dash-panel">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5>Outstanding Maintenance</h5>
                        <?php if (haven_can_access(site_url('maintenance'), 'GET')): ?><a href="<?= site_url('maintenance') ?>"
                           class="small text-decoration-none">
                            View All
                        </a><?php endif; ?>
                    </div>

                    <?php foreach ($recentMaintenance as $task): ?>
                        <div class="border-bottom py-3">
                            <div class="fw-semibold">
                                <?= esc($task['title']) ?>
                            </div>
                            <div class="small text-muted">
                                <?= esc($task['property_name']) ?>
                                / <?= esc($task['unit_number']) ?>
                            </div>
                            <div class="small mt-1">
                                Priority: <?= esc(ucfirst($task['priority'])) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if (empty($recentMaintenance)): ?>
                        <p class="text-muted text-center py-4">
                            No outstanding maintenance issues.
                        </p>
                    <?php endif; ?>
                </div>
            </div>

        </div>

@endsection
