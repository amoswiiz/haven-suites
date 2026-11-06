@extends('saas.layout')
@section('title', 'Apartment calendar blocks')
@section('content')
<div class="split"><div><h1>Apartment calendar blocks</h1><p class="muted">Reserve dates for owner use or repairs. These blocks affect the booking calendar and public availability.</p></div><a class="button secondary" href="{{ site_url('availability') }}">View availability</a></div>
<form method="post" action="{{ route('unit-closures.store', app('currentTenant')->slug) }}" class="card">
    @csrf
    <h2>Block apartment dates</h2>
    <div class="grid">
        <div><label for="unit_id">Apartment</label><select id="unit_id" name="unit_id" required><option value="">Select apartment</option>@foreach($units as $unit)<option value="{{ $unit->id }}" @selected((string)old('unit_id') === (string)$unit->id)>{{ $unit->property_name }} / {{ $unit->unit_number }}</option>@endforeach</select></div>
        <div><label for="kind">Reason category</label><select id="kind" name="kind" required>@foreach(['owner' => 'Owner use', 'maintenance' => 'Maintenance / repairs', 'other' => 'Other'] as $key => $label)<option value="{{ $key }}" @selected(old('kind', 'owner') === $key)>{{ $label }}</option>@endforeach</select></div>
        <div><label for="start_date">Blocked from</label><input type="date" id="start_date" name="start_date" value="{{ old('start_date') }}" required></div>
        <div><label for="end_date">Available again</label><input type="date" id="end_date" name="end_date" value="{{ old('end_date') }}" required><small class="muted">The last date is available for a new check-in.</small></div>
    </div>
    <label for="reason">Internal note (optional)</label><textarea id="reason" name="reason" maxlength="2000" rows="2">{{ old('reason') }}</textarea>
    <p class="muted">Guests only see unavailable dates. This note and the reason category stay private. Open maintenance reports do not block dates unless you add a block here.</p>
    <button type="submit">Save calendar block</button>
</form>
<section class="card"><h2>Saved blocks</h2><div class="table-wrap"><table><thead><tr><th>Apartment</th><th>Dates</th><th>Reason</th><th>Action</th></tr></thead><tbody>
@forelse($closures as $closure)
    @php($unit = $units->firstWhere('id', $closure->unit_id))
    <tr><td>{{ $unit ? $unit->property_name.' / '.$unit->unit_number : 'Apartment unavailable' }}</td><td>{{ $closure->start_date }} to {{ $closure->end_date }}<br><small class="muted">Available again {{ $closure->end_date }}</small></td><td>{{ ucfirst($closure->kind) }}@if($closure->reason)<p>{{ $closure->reason }}</p>@endif</td><td><form method="post" action="{{ route('unit-closures.destroy', [app('currentTenant')->slug, $closure->id]) }}" onsubmit="return confirm('Remove this calendar block? Existing reservations and external calendar blocks will remain.');">@csrf<button type="submit" class="secondary">Remove block</button></form></td></tr>
@empty<tr><td colspan="4">No apartment blocks recorded.</td></tr>@endforelse
</tbody></table></div>{{ $closures->links() }}</section>
@endsection
