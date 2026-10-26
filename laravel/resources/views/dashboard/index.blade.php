@extends('layouts/main')
@section('styles')
<style>
.hs-workspace{--ink:#23395f;--muted:#52627a;color:var(--ink)}
.hs-workspace h2{font-size:1.2rem;font-weight:750;margin:0}.hs-workspace h3{font-size:1rem;font-weight:700;margin:0}
.hs-workspace p,.hs-scope{color:var(--muted);font-size:.875rem}.hs-workspace .hs-scope{display:block;margin-top:6px}
.hs-section-head,.hs-actionbar{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:16px}
.hs-actionbar{padding-bottom:20px;border-bottom:1px solid #dce3ed;margin-bottom:24px}.hs-actionbar p{margin:6px 0 0}
.hs-actions{display:flex;gap:8px;flex-wrap:wrap}.hs-workspace .btn{min-height:44px;display:inline-flex;align-items:center;justify-content:center;font-size:.875rem}
.hs-operation-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:26px}
.hs-operation,.hs-work-panel,.hs-finance-card{background:#fff;border:1px solid #dce3ed;border-radius:12px;padding:18px;min-width:0}
.hs-operation{display:flex;flex-direction:column;gap:8px;text-decoration:none;transition:background .15s,border-color .15s}
.hs-operation:hover{background:#f0f5ff;border-color:#294780}.hs-operation strong{font-size:2rem;line-height:1.15;color:var(--ink)}
.hs-operation-label{font-size:.875rem;font-weight:700}.hs-operation .hs-scope{font-size:.8rem;margin:0}.hs-operation-action{font-size:.875rem;font-weight:600;margin-top:auto;padding-top:4px}
.hs-status{display:inline-block;font-size:.75rem;font-weight:700;border-radius:30px;padding:4px 9px;background:#edf2fa;color:#294780;white-space:normal}
.hs-status.attention{background:#fff0d5;color:#774400}.hs-status.clear{background:#e6f4ed;color:#20573d}
.hs-task-grid,.hs-upcoming-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:26px}.hs-upcoming-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
.hs-work-panel .hs-section-head{align-items:flex-start;margin-bottom:8px}.hs-work-panel .hs-section-head a{font-size:.8rem;min-height:32px;display:inline-flex;align-items:center}
.hs-task{padding:14px 0;border-bottom:1px solid #e6ebf2}.hs-task:last-child{border-bottom:0;padding-bottom:0}.hs-task strong{display:block;font-size:.9rem;overflow-wrap:anywhere}.hs-task p{margin:5px 0 8px;font-size:.8rem}.hs-task a{display:inline-block;padding:8px 0;font-size:.85rem;font-weight:600}
.hs-empty{padding:16px 0;margin:0}.hs-finance-filter{background:#edf2f8;padding:16px;border-radius:12px;margin:16px 0}.hs-finance-filter label{font-size:.85rem;font-weight:600}.hs-finance-filter input,.hs-finance-filter select{min-height:44px}
.hs-finance-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.hs-finance-card strong{display:block;font-size:1.35rem;margin:8px 0;overflow-wrap:anywhere}.hs-finance-card h3{font-size:.85rem}.hs-finance-card a{display:inline-block;padding:8px 0;font-size:.85rem}
.hs-report-details{margin:16px 0 26px;background:#fff;border:1px solid #dce3ed;border-radius:12px;padding:16px}.hs-report-details>summary{font-weight:700;cursor:pointer;min-height:32px}.hs-income-breakdown{display:flex;gap:24px;flex-wrap:wrap;padding:16px 0}.hs-income-breakdown strong{display:block;font-size:1.15rem;margin-top:4px}
.hs-trends{display:grid;grid-template-columns:2fr 1fr;gap:16px;margin:16px 0 26px}.hs-trend-list{list-style:none;padding:0;margin:16px 0 0}.hs-trend-list li{display:grid;grid-template-columns:55px 1fr;gap:8px;align-items:center;margin:12px 0;font-size:.8rem}.hs-trend-bars{display:grid;gap:4px}.hs-trend-bar{height:9px;background:#294780;border-radius:3px;min-width:0}.hs-trend-bar.expense{background:#b9642d}.hs-trend-values{font-size:.75rem;color:#52627a}
.hs-occupancy{font-size:2.5rem;font-weight:750;margin:20px 0 8px}.hs-workspace progress{width:100%;height:12px;accent-color:#294780}.hs-snapshot{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;padding:16px;background:#eaf0f7;border-radius:12px;margin-top:16px}.hs-snapshot dt{font-size:.8rem;color:#52627a;font-weight:500}.hs-snapshot dd{font-size:1.25rem;font-weight:700;margin:4px 0 0}
.hs-workspace :is(a,button,input,select,summary):focus-visible{outline:3px solid #294780;outline-offset:4px}.hs-workspace section{scroll-margin-top:16px}
@media(max-width:1199px){.hs-task-grid{grid-template-columns:1fr 1fr}.hs-finance-grid{grid-template-columns:1fr 1fr}}
@media(max-width:767px){.hs-operation-grid{grid-template-columns:1fr 1fr}.hs-task-grid,.hs-upcoming-grid,.hs-trends{grid-template-columns:1fr}.hs-snapshot{grid-template-columns:repeat(2,minmax(0,1fr))}.hs-actionbar .hs-actions{width:100%}}
@media(max-width:399px){.hs-operation-grid,.hs-finance-grid{grid-template-columns:1fr}.hs-actions .btn{flex:1 1 auto}}
</style>
@endsection
@section('content')
<div class="hs-workspace">
    <div class="hs-actionbar">
        <div><h2>Your day at a glance</h2><p>{{ date('l, j F Y') }} &middot; All apartments</p></div>
        <div class="hs-actions" aria-label="Quick actions">
            @foreach ([['reservations/create', 'New booking', true], ['availability', 'Check availability', false], ['maintenance/create', 'Report issue', false]] as [$path, $label, $primary])
                @if (haven_can_access(site_url($path), 'GET'))
                    <a class="btn {{ $primary ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ site_url($path) }}">{{ $label }}</a>
                @endif
            @endforeach
        </div>
    </div>
    <section aria-labelledby="daily-operations-heading">
        <div class="hs-section-head"><h2 id="daily-operations-heading">Today's operations</h2></div>
        <div class="hs-operation-grid">
            @foreach ([
                ["Today's check-ins", $todayCheckins, 'reservations?activity=checkins', 'Arriving today', 'View arrivals'],
                ["Today's check-outs", $remainingCheckouts, 'reservations?activity=checkouts_pending', $completedCheckouts.' completed / '.$todayCheckouts.' total today', 'View remaining departures'],
                ['Pending cleanings', $pendingCleaning, 'housekeeping?activity=pending', 'All dates / Pending, assigned or in progress', 'View cleaning tasks'],
                ['Open maintenance', $openMaintenance, 'maintenance?activity=open', 'All dates / Unresolved issues', 'View maintenance'],
            ] as [$label, $count, $path, $scope, $action])
                @if (haven_can_access(site_url($path), 'GET'))
                    <a class="hs-operation" href="{{ site_url($path) }}">
                        <span class="hs-operation-label">{{ $label }}</span><strong>{{ number_format($count) }}</strong>
                        <span class="hs-scope">{{ $scope }}</span>
                        @if ($count === 0 && str_contains($path, 'maintenance'))<span class="hs-status clear">All clear</span>@endif
                        <span class="hs-operation-action">{{ $action }} &rarr;</span>
                    </a>
                @endif
            @endforeach
        </div>
    </section>
    <section aria-labelledby="attention-heading">
        <div class="hs-section-head"><div><h2 id="attention-heading">Tasks needing attention</h2><p class="mb-0 mt-1">Up to five records per list. Daily tasks are independent of reporting filters.</p></div></div>
        <div class="hs-task-grid">
            @if (haven_can_access(site_url('reservations'), 'GET'))
            <article class="hs-work-panel">
                <div class="hs-section-head"><h3>Departures remaining today</h3><a href="{{ site_url('reservations?activity=checkouts_pending') }}">View remaining departures &rarr;</a></div>
                @forelse ($departureTasks as $booking)
                    <div class="hs-task"><strong>{{ $booking['guest_name'] }}</strong><p>{{ $booking['property_name'] ?? $booking['partner_name'] ?? 'Partner apartment' }} / {{ $booking['unit_number'] ?? 'External unit' }}</p>
                        <span class="hs-status {{ $booking['status'] === 'checked_out' ? 'clear' : 'attention' }}">{{ ucwords(str_replace('_', ' ', $booking['status'])) }}</span><br>
                        @if (haven_can_access(site_url('reservations/'.$booking['id']), 'GET'))<a href="{{ site_url('reservations/'.$booking['id']) }}">View booking &rarr;</a>@endif
                    </div>
                @empty <p class="hs-empty">No departures remaining today.</p> @endforelse
                <details class="mt-3"><summary>Completed check-outs today: {{ $completedCheckouts }}</summary><p class="mt-2">Completed departures are excluded from the attention queue.</p><a href="{{ site_url('reservations?activity=checkouts_completed') }}">View completed departures &rarr;</a></details>
            </article>
            @endif
            @if (haven_can_access(site_url('housekeeping'), 'GET'))
            <article class="hs-work-panel">
                <div class="hs-section-head"><h3>Cleaning queue</h3><a href="{{ site_url('housekeeping?activity=pending') }}">View all cleaning tasks &rarr;</a></div>
                @forelse ($cleaningTasks as $task)
                    <div class="hs-task"><strong>{{ $task['property_name'] }} / {{ $task['unit_number'] }}</strong><p>{{ date('d M Y, H:i', strtotime($task['scheduled_at'])) }}<br>{{ $task['assigned_name'] ?: 'Unassigned' }}</p>
                        <span class="hs-status attention">{{ ucwords(str_replace('_', ' ', $task['status'])) }}</span><br>
                        @if (haven_can_access(site_url('housekeeping/edit/'.$task['id']), 'GET'))<a href="{{ site_url('housekeeping/edit/'.$task['id']) }}">{{ $task['assigned_staff_id'] ? 'Manage cleaning' : 'Assign cleaner' }} &rarr;</a>@endif
                    </div>
                @empty <p class="hs-empty"><span class="hs-status clear">All clear</span><br>No pending cleaning tasks.</p> @endforelse
            </article>
            @endif
            @if (haven_can_access(site_url('maintenance'), 'GET'))
            <article class="hs-work-panel">
                <div class="hs-section-head"><h3>Maintenance queue</h3><a href="{{ site_url('maintenance?activity=open') }}">View all issues &rarr;</a></div><p>Urgent issues first, then high, medium and low. Oldest reports first within each priority.</p>
                @forelse ($recentMaintenance as $task)
                    <div class="hs-task"><strong>{{ $task['title'] }}</strong><p>{{ $task['property_name'] }} / {{ $task['unit_number'] }}</p><span class="hs-status attention">{{ ucfirst($task['priority']) }} priority / {{ ucwords(str_replace('_', ' ', $task['status'])) }}</span><br>
                        @if (haven_can_access(site_url('maintenance/edit/'.$task['id']), 'GET'))<a href="{{ site_url('maintenance/edit/'.$task['id']) }}">Manage issue &rarr;</a>@endif
                    </div>
                @empty <p class="hs-empty"><span class="hs-status clear">All clear</span><br>No unresolved maintenance issues.</p> @endforelse
            </article>
            @endif
        </div>
    </section>
    @if (haven_can_access(site_url('reservations'), 'GET'))
    <section aria-labelledby="upcoming-heading">
        <div class="hs-section-head"><h2 id="upcoming-heading">Upcoming stays</h2><a href="{{ site_url('reservations') }}">All reservations &rarr;</a></div>
        <div class="hs-upcoming-grid">
            @foreach ([['Arrivals from today', $upcomingCheckins, 'check_in'], ['Departures after today', $upcomingDepartures, 'check_out']] as [$heading, $bookings, $dateField])
            <article class="hs-work-panel"><h3>{{ $heading }}</h3>
                @forelse ($bookings as $booking)
                <div class="hs-task"><strong>{{ $booking['guest_name'] }}</strong><p>{{ $booking['property_name'] ?? $booking['partner_name'] ?? 'Partner apartment' }} / {{ $booking['unit_number'] ?? 'External unit' }} &middot; {{ date('d M Y', strtotime($booking[$dateField])) }}</p>
                    @if (haven_can_access(site_url('reservations/'.$booking['id']), 'GET'))<a href="{{ site_url('reservations/'.$booking['id']) }}">View booking &rarr;</a>@endif
                </div>
                @empty <p class="hs-empty">No upcoming {{ $dateField === 'check_in' ? 'arrivals' : 'departures' }}.</p> @endforelse
            </article>
            @endforeach
        </div>
    </section>
    @endif
    @if ($canViewFinance)
    @php
        $monthLabel = date('F Y', strtotime($financeMonth.'-01'));
        $unitLabel = 'All apartments';
        foreach ($financeUnits as $unit) {
            if ((int) $unit['id'] === (int) $financeFilters['unit_id']) {
                $unitLabel = $unit['property_name'].' / '.$unit['unit_number'];
            }
        }
        $trendMax = max(1, ...array_column($financeTrend, 'collections'), ...array_column($financeTrend, 'expenses'));
        $hasTrend = array_sum(array_column($financeTrend, 'collections')) != 0 || array_sum(array_column($financeTrend, 'expenses')) != 0;
    @endphp
    <section aria-labelledby="finance-heading">
        <div class="hs-section-head"><div><h2 id="finance-heading">Financial summary</h2><p class="mb-0 mt-1">Earned company income, owner obligations and cash collections are shown separately.</p></div></div>
        <form method="get" action="{{ site_url('dashboard') }}" class="hs-finance-filter" aria-label="Financial reporting filters">
            <div class="row g-3 align-items-end">
                <div class="col-md-4"><label for="report-month" class="form-label">Reporting month</label><input id="report-month" type="month" name="month" class="form-control" value="{{ $financeFilters['month'] }}" required></div>
                <div class="col-md-4"><label for="report-unit" class="form-label">Apartment</label><select id="report-unit" name="unit_id" class="form-select"><option value="0">All apartments</option>@foreach ($financeUnits as $unit)<option value="{{ $unit['id'] }}" @selected((int) $financeFilters['unit_id'] === (int) $unit['id'])>{{ $unit['property_name'] }} / {{ $unit['unit_number'] }}</option>@endforeach</select></div>
                <div class="col-md-4 hs-actions"><button class="btn btn-primary" type="submit">Apply filters</button><a class="btn btn-outline-secondary" href="{{ site_url('dashboard') }}">Reset</a></div>
            </div><p class="mb-0 mt-3">Filters affect reporting below. Company income always covers the whole company; outstanding balances always cover all dates.</p>
        </form>
        @include('accounting.summary',['operatingReport'=>$operatingReport])
        @if($companyIncomeSummary['issues'])<div class="alert alert-warning" role="status"><strong>Collection-based income is provisional.</strong> {{ $companyIncomeSummary['issues'] }} record(s) are excluded until their accounting details are reviewed.</div>@endif
        @if($financialSummary['cancelled_count'] || $financialSummary['credits'])<div class="alert alert-warning" role="status"><strong>Guest balances need reconciliation.</strong>
        @if($financialSummary['cancelled_count'])<p>{{ $financialSummary['cancelled_count'] }} cancelled booking(s) retain {{ app('currentTenant')->currency }} {{ number_format($financialSummary['cancelled_balance'],2) }} in positive balances. These are excluded from guest outstanding pending review of cancellation charges and adjustments. No debt has been written off.</p>@endif
        @if($financialSummary['credits'])<p>{{ app('currentTenant')->currency }} {{ number_format($financialSummary['credits'],2) }} in recorded overpayments or credits needs review. These amounts are not automatically treated as refunds or offset against other guests.</p>@endif
        @if(haven_can_access(site_url('payments/guests'),'GET'))<a href="{{ site_url('payments/guests') }}">Review guest payment records &rarr;</a>@endif
        </div>@endif
        <p class="hs-scope">Showing {{ $monthLabel }} &middot; {{ $unitLabel }}</p>
        <div class="hs-finance-grid">
            @foreach ([['Collection fees + partner markup', $companyIncomeSummary['total'], 'Company-wide / '.$monthLabel, 'reports/monthly-company-income?month='.$financeMonth], ['Guest collections', $financialSummary['collections'], $unitLabel.' / '.$monthLabel, 'payments/guests'], ['Unit expenses', $financialSummary['expenses'], $unitLabel.' / '.$monthLabel, 'expenses'], ['Guest outstanding', $financialSummary['outstanding'], $unitLabel.' / All time', 'payments/guests']] as [$label, $value, $scope, $path])
            <article class="hs-finance-card"><h3>{{ $label }}</h3>@if($label==='Collection fees + partner markup' && $companyIncomeSummary['issues'])<span class="hs-status attention">Provisional</span>@endif @if($label==='Guest outstanding' && ($financialSummary['cancelled_count'] || $financialSummary['credits']))<span class="hs-status attention">Needs reconciliation</span>@endif<strong>KES {{ number_format((float) $value, 2) }}</strong><span class="hs-scope">{{ $scope }}</span>
                @if (haven_can_access(site_url($path), 'GET'))<a href="{{ site_url($path) }}">Open {{ strtolower($label) }} report &rarr;</a>@endif
            </article>
            @endforeach
        </div>
        <details class="hs-report-details"><summary>Collection-based fees, partner markup and owner remittances</summary><div class="hs-income-breakdown">
            <div><span class="hs-scope">Managed apartment commissions / Company-wide</span><strong>KES {{ number_format((float) $companyIncomeSummary['managed'], 2) }}</strong></div>
            <div><span class="hs-scope">Partner booking markup / Company-wide</span><strong>KES {{ number_format((float) $companyIncomeSummary['partner'], 2) }}</strong></div>
            <div><span class="hs-scope">Owner remittances / {{ $unitLabel }}</span><strong>KES {{ number_format((float) $financialSummary['remittances'], 2) }}</strong>@if (haven_can_access(site_url('owner-remittances'), 'GET'))<a href="{{ site_url('owner-remittances') }}">View remittances &rarr;</a>@endif</div>
        </div><p class="mb-0">{{ $monthLabel }}. Company income is before operating expenses, refunds and adjustments.</p></details>
        @if (!empty($companyIncomeSummary['issues']))<div class="alert alert-warning" role="status">{{ $companyIncomeSummary['issues'] }} income records need review and are excluded from totals. <a href="{{ site_url('reports/monthly-company-income?month='.$financeMonth) }}">Review income records</a></div>@endif
    </section>
    <section aria-labelledby="trends-heading">
        <div class="hs-section-head"><h2 id="trends-heading">Trends and occupancy</h2></div>
        <div class="hs-trends">
            <article class="hs-work-panel"><h3>Collections and expenses / Last six months</h3><p class="hs-scope">{{ $unitLabel }} &middot; Based on payment and expense dates, in KES.</p>
                @if (!$hasTrend)<p class="hs-empty">No collections or expenses recorded in this period.</p>@endif
                <ul class="hs-trend-list" aria-label="Monthly collections and expenses">
                    @foreach ($financeTrend as $item)
                    <li><span>{{ $item['label'] }}</span><div class="hs-trend-bars"><span class="hs-trend-bar" aria-hidden="true" style="width:{{ max(0, $item['collections']) / $trendMax * 100 }}%"></span><span class="hs-trend-bar expense" aria-hidden="true" style="width:{{ max(0, $item['expenses']) / $trendMax * 100 }}%"></span><span class="hs-trend-values">Collections: {{ number_format($item['collections'], 2) }} / Expenses: {{ number_format($item['expenses'], 2) }}</span></div></li>
                    @endforeach
                </ul>
            </article>
            <article class="hs-work-panel"><h3>Estimated occupancy</h3><p class="hs-scope">{{ $unitLabel }} / {{ $monthLabel }}</p><div class="hs-occupancy">{{ $occupancySummary['percent'] === null ? 'N/A' : number_format($occupancySummary['percent'], 1).'%' }}</div>
                @if ($occupancySummary['percent'] !== null)<progress max="100" value="{{ $occupancySummary['percent'] }}" aria-label="Estimated occupancy">{{ $occupancySummary['percent'] }}%</progress>@endif
                <p>{{ number_format($occupancySummary['occupied']) }} occupied unit-nights out of {{ number_format($occupancySummary['capacity']) }} available.</p><details><summary>How this is calculated</summary><p class="mt-2">Currently active units only. Check-out dates are excluded; overlapping bookings count once. This is a calendar estimate.</p></details>
            </article>
        </div>
    </section>
    @endif
    <section aria-labelledby="snapshot-heading"><h2 id="snapshot-heading">Property and team snapshot</h2><dl class="hs-snapshot">
        @foreach ([['Total units', $totalUnits], ['Active units', $activeUnits], ['All reservations', $totalReservations], ['Confirmed / in-house', $confirmedReservations], ['Active staff', $totalStaff]] as [$label, $value])<div><dt>{{ $label }}</dt><dd>{{ number_format($value) }}</dd></div>@endforeach
    </dl></section>
</div>
@endsection
