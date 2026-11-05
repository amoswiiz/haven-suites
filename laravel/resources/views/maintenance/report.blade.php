@extends('layouts/main')
@section('content')
<style>.hs-maintenance-report :is(.form-control,.form-select,.btn){min-height:44px}</style>
<div class="mx-auto hs-maintenance-report" style="max-width:760px">
    <a href="{{ route('maintenance.index', app('currentTenant')->slug) }}" class="d-inline-block mb-3">&larr; Maintenance issues</a>
    <h1 class="h3 fw-bold">Report maintenance</h1>
    <p class="text-muted">Spotted something that needs attention? Tell your team where it is and what needs fixing. Your report will start as Open.</p>
    <form method="post" action="{{ route('maintenance.report.store', app('currentTenant')->slug) }}" class="card border-0 shadow-sm">
        @csrf
        <div class="card-body p-4">
            <div class="mb-3">
                <label for="report-unit" class="form-label">Apartment <span aria-hidden="true">*</span></label>
                <select id="report-unit" name="unit_id" class="form-select" required>
                    <option value="">Select apartment</option>
                    @foreach($units as $unit)
                        <option value="{{ $unit['id'] }}" @selected(old('unit_id', request('unit_id')) == $unit['id'])>{{ $unit['property_name'] }} — {{ $unit['unit_number'] }}</option>
                    @endforeach
                </select>
                <div class="form-text">You can report an issue in any apartment in your company.</div>
            </div>
            <div class="mb-3">
                <label for="report-title" class="form-label">What needs fixing? <span aria-hidden="true">*</span></label>
                <input id="report-title" name="title" class="form-control" maxlength="180" placeholder="e.g. Kitchen tap is leaking" value="{{ old('title') }}" required>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label for="report-category" class="form-label">Category <span aria-hidden="true">*</span></label>
                    <select id="report-category" name="category" class="form-select" required>
                        <option value="">Select category</option>
                        @foreach($categories as $value => $label)<option value="{{ $value }}" @selected(old('category') === $value)>{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label for="report-priority" class="form-label">Priority <span aria-hidden="true">*</span></label>
                    <select id="report-priority" name="priority" class="form-select" required>
                        @foreach(['low' => 'Low — minor issue', 'medium' => 'Medium — needs attention', 'high' => 'High — affects the stay', 'urgent' => 'Urgent — immediate attention'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('priority', 'medium') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="mb-4">
                <label for="report-description" class="form-label">Details <span aria-hidden="true">*</span></label>
                <textarea id="report-description" name="description" class="form-control" rows="5" maxlength="5000" placeholder="Where is the problem? What happened, and is it affecting the guest?" required>{{ old('description') }}</textarea>
            </div>
            @if(!$units)<p class="alert alert-info">No apartments are available. Ask your manager to add the apartment first.</p>@endif
            <div class="d-flex gap-3 align-items-center flex-wrap">
                <button type="submit" class="btn btn-primary" @disabled(!$units)>Submit report</button>
                <a href="{{ route('maintenance.index', app('currentTenant')->slug) }}">Cancel</a>
            </div>
        </div>
    </form>
</div>
@endsection
