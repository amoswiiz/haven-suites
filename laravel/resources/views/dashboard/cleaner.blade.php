@extends('layouts/main')
@section('styles')
<style>
.hs-cleaner-dashboard{color:#23395f}.hs-cleaner-dashboard h1{font-size:1.75rem}.hs-cleaner-dashboard h2{font-size:1.15rem;font-weight:700}.hs-cleaner-dashboard .hs-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin:20px 0}.hs-cleaner-dashboard .hs-panel{background:white;border:1px solid #dce3ed;border-radius:12px;padding:20px;min-width:0}.hs-cleaner-dashboard .hs-metric{display:block;font-size:1.7rem;font-weight:750;margin:8px 0}.hs-cleaner-dashboard .hs-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px}.hs-cleaner-dashboard .hs-item{padding:13px 0;border-bottom:1px solid #e6ebf2;overflow-wrap:anywhere}.hs-cleaner-dashboard .hs-item:last-child{border-bottom:0}.hs-cleaner-dashboard a{min-height:44px;display:inline-flex;align-items:center}.hs-cleaner-dashboard .hs-quick-actions{display:flex;gap:10px;flex-wrap:wrap}.hs-cleaner-dashboard :is(a,button):focus-visible{outline:3px solid #e98d50;outline-offset:3px}@media(max-width:650px){.hs-cleaner-dashboard .hs-grid{grid-template-columns:1fr}.hs-cleaner-dashboard .hs-quick-actions .btn{flex:1 1 auto}}
</style>
@endsection
@section('content')
<div class="hs-cleaner-dashboard">
    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-4">
        <div><h1 class="fw-bold mb-1">My dashboard</h1><p class="text-muted mb-0">{{ $summary['today']->format('l, j F Y') }} · Your work at a glance</p></div>
        <div class="hs-quick-actions" aria-label="Cleaner quick actions">
            @if($summary['cleaningEnabled'] && $summary['staff'])<a class="btn btn-primary" href="{{ site_url('housekeeping/my-cleanings/new') }}">Record cleaning</a>@endif
            @if(auth()->user()->can('maintenance.report'))<a class="btn btn-outline-primary" href="{{ route('maintenance.report', app('currentTenant')->slug) }}">Report maintenance</a>@endif
            @if($summary['billsEnabled'] && $summary['staff'])<a class="btn btn-outline-primary" href="{{ route('staff-bills.create', app('currentTenant')->slug) }}">Submit a bill</a>@endif
        </div>
    </div>
    @if(!$summary['staff'])<div class="alert alert-info">{{ $summary['staffLinkIssue'] === 'multiple' ? 'More than one active staff record is linked to your login. Ask your manager to resolve the duplicate links so your assigned cleanings and earnings can appear here.' : 'Your login is not linked to an active staff record. Ask your manager to link your account so your assigned cleanings and earnings can appear here.' }}</div>@endif
    @if($summary['profileEnabled'] && !$summary['profileComplete'])<div class="alert alert-info">Complete your biodata and identification details. <a href="{{ route('staff-profile.edit', app('currentTenant')->slug) }}">Complete my profile</a></div>@endif
    @if($summary['cleaningEnabled'] && $summary['staff'])
    <section aria-labelledby="cleaning-summary-heading">
        <h2 id="cleaning-summary-heading">My cleaning summary</h2>
        <div class="hs-cards">
            <div class="hs-panel">Assigned today<strong class="hs-metric">{{ $summary['cleaning']['today'] }}</strong><span class="small text-muted">Today's assignments, including completed work</span></div>
            <div class="hs-panel">Pending cleanings<strong class="hs-metric">{{ $summary['cleaning']['pending'] }}</strong><span class="small text-muted">All dates · {{ $summary['cleaning']['overdue'] }} overdue</span></div>
            <div class="hs-panel">Awaiting verification<strong class="hs-metric">{{ $summary['cleaning']['awaitingVerification'] }}</strong><span class="small text-muted">Completed and waiting for review</span></div>
            <div class="hs-panel">Disputed cleanings<strong class="hs-metric">{{ $summary['cleaning']['disputed'] }}</strong><a href="{{ site_url('payments/cleaners/earnings') }}">Review my earnings</a></div>
        </div>
    </section>
    <section class="hs-panel mb-4" aria-labelledby="earnings-summary-heading">
        <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap"><h2 id="earnings-summary-heading">My earnings · {{ $summary['today']->format('F Y') }}</h2><a href="{{ site_url('payments/cleaners/earnings?month='.$summary['today']->format('Y-m')) }}">Open earnings breakdown</a></div>
        <p class="text-muted small">Verified cleaning work completed this month. Payment allocations relate to that work and may have been recorded on different dates.</p>
        <div class="hs-cards">
            <div>Approved earnings<strong class="hs-metric">{{ $summary['currency'] }} {{ number_format($summary['earnings']['earnedMinor'] / 100, 2) }}</strong></div>
            <div>Paid against this work<strong class="hs-metric">{{ $summary['currency'] }} {{ number_format($summary['earnings']['paidMinor'] / 100, 2) }}</strong></div>
            <div>Outstanding earnings<strong class="hs-metric">{{ $summary['currency'] }} {{ number_format($summary['earnings']['outstandingMinor'] / 100, 2) }}</strong></div>
        </div>
    </section>
    @endif
    <div class="hs-grid">
        @if($summary['cleaningEnabled'] && $summary['staff'])
        <section class="hs-panel" aria-labelledby="cleaning-work-heading"><h2 id="cleaning-work-heading">Cleanings needing attention</h2><p class="small text-muted">Your earliest five pending cleanings due today or earlier.</p>
            @forelse($summary['cleaning']['items'] as $job)<div class="hs-item"><strong>{{ $job->property_name }} / {{ $job->unit_number }}</strong><p class="small mb-1">{{ date('d M Y H:i', strtotime($job->scheduled_at)) }} · {{ ucwords(str_replace('_', ' ', $job->status)) }}</p><a href="{{ site_url('housekeeping') }}">Open my cleanings &rarr;</a></div>@empty<p class="text-muted">No cleanings need your attention today.</p>@endforelse
        </section>
        @endif
        @if($summary['tasksEnabled'])
        <section class="hs-panel" aria-labelledby="task-summary-heading"><h2 id="task-summary-heading">My tasks due</h2><p class="small text-muted">{{ $summary['tasks']['due'] }} due today or overdue.</p>
            @forelse($summary['tasks']['items'] as $task)<div class="hs-item"><strong>{{ $task->title }}</strong><p class="small mb-1">Due {{ $task->due_date }} · {{ ucwords(str_replace('_', ' ', $task->status)) }}</p><a href="{{ route('tasks.show', [app('currentTenant')->slug, $task->id]) }}">View task &rarr;</a></div>@empty<p class="text-muted">No tasks are due.</p>@endforelse
            <a href="{{ route('tasks.index', app('currentTenant')->slug) }}">All my tasks</a>
        </section>
        @endif
        @if($summary['billsEnabled'])
        <section class="hs-panel" aria-labelledby="bill-summary-heading"><h2 id="bill-summary-heading">My bills &amp; reimbursements</h2><p>{{ $summary['bills']['pending'] }} awaiting review · {{ $summary['bills']['returned'] }} returned for correction</p><p>Approved reimbursement outstanding: <strong>{{ $summary['currency'] }} {{ number_format($summary['bills']['reimbursementMinor'] / 100, 2) }}</strong></p>
            @forelse($summary['bills']['items'] as $bill)<div class="hs-item"><strong>{{ $bill->description }}</strong><span class="small d-block">{{ ucfirst($bill->status) }}</span><a href="{{ route('staff-bills.show', [app('currentTenant')->slug, $bill->id]) }}">View bill &rarr;</a></div>@empty<p class="text-muted">No bills submitted yet.</p>@endforelse
            <a href="{{ route('staff-bills.index', app('currentTenant')->slug) }}">All my bills</a>
        </section>
        @endif
        @if($summary['maintenanceEnabled'])
        <section class="hs-panel" aria-labelledby="maintenance-summary-heading"><h2 id="maintenance-summary-heading">My maintenance reports</h2><p>{{ $summary['maintenance']['open'] }} unresolved reports.</p>
            @forelse($summary['maintenance']['items'] as $issue)<div class="hs-item"><strong>{{ $issue->title }}</strong><p class="small mb-0">{{ ucwords(str_replace('_', ' ', $issue->status)) }}</p></div>@empty<p class="text-muted">You have not reported any maintenance issues.</p>@endforelse
            <a href="{{ route('maintenance.index', app('currentTenant')->slug) }}">Open maintenance workspace</a>
        </section>
        @endif
    </div>
</div>
@endsection
