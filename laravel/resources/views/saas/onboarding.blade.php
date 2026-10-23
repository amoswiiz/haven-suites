@extends('saas.layout')
@section('title','Add your first apartment')
@section('content')
<div class="card narrow"><span class="pill">Step 2 of 2</span><h1>Add an apartment</h1><p class="muted">A property is a building or location. Each rentable apartment is a unit within that property.</p>
@if($hasUnits)<p>You already have apartments in this workspace.</p><a class="button" href="{{ site_url('dashboard') }}">Open dashboard</a>@else
<form method="post" action="{{ route('onboarding.finish',app('currentTenant')->slug) }}">@csrf
<label for="property_name">Property or building name</label><input id="property_name" name="property_name" value="{{ old('property_name') }}" required maxlength="150">
<label for="location">Location</label><input id="location" name="location" value="{{ old('location') }}" placeholder="e.g. Kilimani, Nairobi" required maxlength="150">
<label for="unit_number">Apartment name or number</label><input id="unit_number" name="unit_number" value="{{ old('unit_number') }}" required maxlength="50">
<div class="grid"><div><label for="bedrooms">Bedrooms</label><input id="bedrooms" name="bedrooms" type="number" min="0" max="50" value="{{ old('bedrooms',1) }}" required></div><div><label for="bathrooms">Bathrooms</label><input id="bathrooms" name="bathrooms" type="number" min="0" max="50" value="{{ old('bathrooms',1) }}" required></div></div>
<label for="nightly_rate">Nightly rate (KES)</label><input id="nightly_rate" name="nightly_rate" type="number" min="0" max="10000000" step="0.01" value="{{ old('nightly_rate') }}" required>
<div class="actions"><button>Add apartment and continue</button></div></form>@endif</div>
@endsection
