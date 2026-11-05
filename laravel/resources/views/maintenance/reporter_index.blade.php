@extends('layouts/main')
@section('content')
<style>.hs-maintenance-filters :is(.form-select,.btn){min-height:44px}</style>
@php
    $statusLabels = ['open' => 'Open', 'assigned' => 'Assigned', 'in_progress' => 'In progress', 'on_hold' => 'On hold', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
@endphp
<div class="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-4">
    <div><h1 class="h3 fw-bold mb-1">Maintenance issues</h1><p class="text-muted mb-0">See what needs attention across your company and follow repair progress.</p></div>
    @if(auth()->user()->can('maintenance.report'))<a href="{{ route('maintenance.report', app('currentTenant')->slug) }}" class="btn btn-primary">Report maintenance</a>@endif
</div>
<form method="get" action="{{ route('maintenance.index', app('currentTenant')->slug) }}" class="card border-0 shadow-sm mb-4 hs-maintenance-filters">
    <div class="card-body row g-3 align-items-end">
        <div class="col-md-6">
            <label for="issue-apartment" class="form-label">Apartment</label>
            <select id="issue-apartment" name="unit_id" class="form-select"><option value="">All apartments</option>
                @foreach($units as $unit)<option value="{{ $unit['id'] }}" @selected(request('unit_id') == $unit['id'])>{{ $unit['property_name'] }} — {{ $unit['unit_number'] }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-4">
            <label for="issue-activity" class="form-label">Progress</label>
            <select id="issue-activity" name="activity" class="form-select">
                <option value="all" @selected(request('activity', 'all') === 'all')>All issues</option>
                <option value="open" @selected(request('activity') === 'open')>Unresolved issues</option>
            </select>
        </div>
        <div class="col-md-2"><button type="submit" class="btn btn-outline-primary w-100">Apply</button></div>
    </div>
</form>
<p class="text-muted">{{ $issues->total() }} {{ $issues->total() === 1 ? 'issue' : 'issues' }}</p>
<div class="row g-3 mb-4">
@forelse($issues as $issue)
    @php($statusClass = match ($issue->status) { 'completed' => 'bg-success', 'in_progress' => 'bg-primary', 'on_hold' => 'bg-warning text-dark', default => 'bg-secondary' })
    <div class="col-12 col-lg-6">
        <article class="card h-100 border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between gap-2 align-items-start mb-2"><h2 class="h5 mb-0">{{ $issue->title }}</h2><span class="badge {{ $statusClass }}">{{ $statusLabels[$issue->status] ?? ucfirst($issue->status) }}</span></div>
                <p class="text-muted">{{ $issue->property_name }} — {{ $issue->unit_number }}</p>
                <div class="d-flex gap-2 align-items-center mb-3 flex-wrap">
                    <span class="badge {{ in_array($issue->priority, ['high', 'urgent'], true) ? 'bg-danger' : 'bg-light text-dark' }}">{{ ucfirst($issue->priority) }} priority</span>
                    <span class="small text-muted">{{ ucwords(str_replace('_', ' ', $issue->category)) }}</span>
                    @if((int) $issue->reported_by_user_id === auth()->id())<span class="small fw-semibold">Reported by you</span>@endif
                </div>
                <p style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $issue->description }}</p>
                <p class="small text-muted mb-0">Issue #{{ $issue->id }} · Reported {{ date('d M Y', strtotime($issue->reported_at)) }}</p>
                @if($issue->completed_at)<p class="small text-muted mb-0">Completed {{ date('d M Y', strtotime($issue->completed_at)) }}</p>@endif
            </div>
        </article>
    </div>
@empty
    <div class="col-12"><div class="card card-body text-center py-5"><p class="mb-0">No maintenance issues match these filters.</p></div></div>
@endforelse
</div>
{{ $issues->links('pagination::bootstrap-5') }}
@endsection
