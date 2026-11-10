@extends('saas.layout')
@section('title', $partner ? 'Edit partner apartment' : 'Add partner apartment')
@section('content')
@php
    $tenantSlug = app('currentTenant')->slug;
    $value = fn (string $key, mixed $default = '') => old($key, $partner->$key ?? $default);
@endphp
<p><a href="{{ route('partners.index', $tenantSlug) }}">&larr; Partner apartments</a></p>
<h1>{{ $partner ? 'Edit partner apartment' : 'Add partner apartment' }}</h1>
<p class="muted">Owner contact details are for staff only and are never shown to guests.</p>
<form method="post" action="{{ $partner ? route('partners.update', [$tenantSlug, $partner->id]) : route('partners.store', $tenantSlug) }}" class="card">
    @csrf
    <h2>Apartment</h2>
    <div class="grid">
        <div><label for="apartment_name">Apartment or property name</label><input id="apartment_name" name="apartment_name" required maxlength="150" value="{{ $value('apartment_name') }}"></div>
        <div><label for="building_name">Building or block</label><input id="building_name" name="building_name" maxlength="150" value="{{ $value('building_name') }}"></div>
        <div><label for="unit_number">Unit number</label><input id="unit_number" name="unit_number" maxlength="50" value="{{ $value('unit_number') }}"></div>
        <div><label for="location">Area / location</label><input id="location" name="location" required maxlength="150" value="{{ $value('location') }}" placeholder="e.g. Diani"></div>
        <div><label for="bedrooms">Bedrooms</label><input id="bedrooms" name="bedrooms" type="number" min="0" max="20" required value="{{ $value('bedrooms', 1) }}"><small class="muted">Use 0 for a studio.</small></div>
        <div><label for="bathrooms">Bathrooms</label><input id="bathrooms" name="bathrooms" type="number" min="0" max="20" value="{{ $value('bathrooms') }}"></div>
        <div><label for="status">Status</label><select id="status" name="status">@foreach(['active' => 'Active', 'inactive' => 'Inactive'] as $key => $label)<option value="{{ $key }}" @selected($value('status', 'active') === $key)>{{ $label }}</option>@endforeach</select></div>
    </div>
    <h2>Owner</h2>
    <div class="grid">
        <div><label for="owner_name">Owner name</label><input id="owner_name" name="owner_name" required maxlength="150" value="{{ $value('owner_name') }}"></div>
        <div><label for="owner_phone">Owner phone / WhatsApp</label><input id="owner_phone" name="owner_phone" type="tel" maxlength="40" value="{{ $value('owner_phone', \App\Support\PhoneNumber::DEFAULT_PREFIX.' ') }}" placeholder="+254 712 345 678"></div>
        <div><label for="owner_email">Owner email</label><input id="owner_email" name="owner_email" type="email" maxlength="150" value="{{ $value('owner_email') }}"></div>
    </div>
    <label for="notes">Notes (staff only)</label><textarea id="notes" name="notes" rows="4" maxlength="5000">{{ $value('notes') }}</textarea>
    <div class="actions"><button type="submit">{{ $partner ? 'Save changes' : 'Add partner apartment' }}</button><a href="{{ route('partners.index', $tenantSlug) }}">Cancel</a></div>
</form>
@endsection
