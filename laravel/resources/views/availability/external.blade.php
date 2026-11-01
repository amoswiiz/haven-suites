@extends('layouts/main')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h3>External calendars</h3>
    <a class="btn btn-outline-secondary" href="{{ site_url('availability') }}">Back to availability</a>
</div>
<p>Connect an apartment's private iCal export link from Airbnb, Booking.com, Vrbo, or Google Calendar. Imported dates block availability without creating financial records. Only individual all-day events are supported.</p>
<div class="alert alert-warning">Calendar updates may be delayed. Check the last successful sync before confirming a stay. Imports cannot guarantee live availability.</div>
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
@if($errors->any())<div class="alert alert-danger">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
@if(auth()->user()->can('properties.manage'))
<section class="card card-body mb-4" aria-labelledby="calendar-exports-heading">
    <h5 id="calendar-exports-heading">Export local apartment calendars</h5>
    <p>Generate a private .ics link for each apartment and paste it into the matching listing's calendar import settings. It includes confirmed and checked-in local bookings only. Imported external blocks, guest details, and payment information are excluded.</p>
    <div class="alert alert-info">Airbnb must be able to reach this URL over the internet. Localhost links work for local testing only; use your public HTTPS domain when connecting Airbnb. Anyone with a private link can view its blocked dates.</div>
    <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Apartment</th><th>Private export URL</th><th>Actions</th></tr></thead><tbody>
    @forelse($units as $unit)
        <tr><td>{{ $unit->property_name }} / {{ $unit->unit_number }}</td><td>
        @if($exports->has($unit->id))
            <label class="visually-hidden" for="export-url-{{ $unit->id }}">Export URL for {{ $unit->unit_number }}</label>
            <input class="form-control" id="export-url-{{ $unit->id }}" value="{{ $exports->get($unit->id) }}" readonly onclick="this.select()" autocomplete="off">
            <button type="button" class="btn btn-sm btn-outline-secondary mt-1" data-copy-export="export-url-{{ $unit->id }}">Copy link</button>
        @else
            <span class="text-muted">No export link generated.</span>
        @endif
        </td><td>
            <form class="d-inline" method="post" action="{{ route('calendar.export.generate', ['tenant' => app('currentTenant')->slug, 'unitId' => $unit->id]) }}" @if($exports->has($unit->id)) onsubmit="return confirm('Replace this export link? Update any external calendars using the old link.')" @endif>
                @csrf<button class="btn btn-sm btn-outline-primary">{{ $exports->has($unit->id) ? 'Replace link' : 'Generate link' }}</button>
            </form>
            @if($exports->has($unit->id))
            <form class="d-inline" method="post" action="{{ route('calendar.export.revoke', ['tenant' => app('currentTenant')->slug, 'unitId' => $unit->id]) }}" onsubmit="return confirm('Disable this export link? Connected platforms will stop receiving updates.')">
                @csrf<button class="btn btn-sm btn-outline-danger">Disable link</button>
            </form>
            @endif
        </td></tr>
    @empty<tr><td colspan="3">Add an apartment before generating an export calendar.</td></tr>@endforelse
    </tbody></table></div>
    <span id="export-copy-status" class="small text-muted" role="status" aria-live="polite"></span>
</section>
<form method="post" action="{{ site_url('availability/external') }}" class="card card-body mb-4">
    @csrf
    <h5>Connect a calendar</h5>
    <div class="row g-3">
        <div class="col-md-4"><label for="unit_id" class="form-label">Apartment</label><select name="unit_id" id="unit_id" class="form-select" required><option value="">Choose an apartment</option>@foreach($units as $unit)<option value="{{ $unit->id }}" @selected(old('unit_id') == $unit->id)>{{ $unit->property_name }} / {{ $unit->unit_number }}</option>@endforeach</select></div>
        <div class="col-md-4"><label for="name" class="form-label">Calendar name</label><input class="form-control" id="name" name="name" maxlength="100" value="{{ old('name') }}" placeholder="Airbnb — Apartment 1" required></div>
        <div class="col-md-4"><label for="calendar_url" class="form-label">Private iCal export URL</label><input class="form-control" type="url" id="calendar_url" name="url" maxlength="2048" placeholder="https://www.airbnb.com/calendar/ical/…" autocomplete="off" required><small class="text-muted">Copy the export link from the listing's calendar settings.</small></div>
    </div>
    <div class="mt-3"><button class="btn btn-primary">Connect and import</button></div>
</form>
@endif
<div class="table-responsive"><table class="table table-bordered align-middle"><thead><tr><th>Calendar</th><th>Apartment</th><th>Last successful sync</th><th>Status</th><th>Actions</th></tr></thead><tbody>
@forelse($feeds as $feed)
<tr><td>{{ $feed->name }}</td><td>{{ $units->firstWhere('id', $feed->unit_id)?->unit_number ?? 'Apartment unavailable' }}</td><td>{{ $feed->last_synced_at ?? 'Never' }} <small>({{ config('app.timezone') }})</small></td><td>{{ $feed->last_error ?? ($feed->last_synced_at ? 'Imported successfully' : 'Not synced yet') }}</td><td>
@if(auth()->user()->can('properties.manage'))
<form class="d-inline" method="post" action="{{ site_url('availability/external/'.$feed->id.'/sync') }}">@csrf<button class="btn btn-sm btn-outline-primary">Sync now</button></form>
<form class="d-inline" method="post" action="{{ site_url('availability/external/'.$feed->id.'/delete') }}" onsubmit="return confirm('Disconnect this calendar and remove its availability blocks?')">@csrf<button class="btn btn-sm btn-outline-danger">Disconnect</button></form>
@endif
</td></tr>
@empty<tr><td colspan="5">No external calendars connected.</td></tr>@endforelse
</tbody></table></div>
@endsection

@section('scripts')
<script>
document.querySelectorAll('[data-copy-export]').forEach(button => {
    button.addEventListener('click', async () => {
        const input = document.getElementById(button.dataset.copyExport);
        const status = document.getElementById('export-copy-status');
        input.focus();
        input.select();
        try {
            await navigator.clipboard.writeText(input.value);
            status.textContent = 'Export link copied.';
        } catch {
            status.textContent = 'Link selected. Press Ctrl+C or use your device’s copy action.';
        }
    });
});
</script>
@endsection
