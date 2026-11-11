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
<form method="post" enctype="multipart/form-data" action="{{ $partner ? route('partners.update', [$tenantSlug, $partner->id]) : route('partners.store', $tenantSlug) }}" class="card">
    @csrf
    <h2>Apartment</h2>
    <div class="grid">
        <div><label for="apartment_name">Apartment or property name</label><input id="apartment_name" name="apartment_name" required maxlength="150" value="{{ $value('apartment_name') }}"></div>
        <div><label for="building_name">Building or block</label><input id="building_name" name="building_name" maxlength="150" value="{{ $value('building_name') }}"></div>
        <div><label for="unit_number">Unit number</label><input id="unit_number" name="unit_number" maxlength="50" value="{{ $value('unit_number') }}"></div>
        <div><label for="location">Area / location</label><input id="location" name="location" required maxlength="150" value="{{ $value('location') }}" placeholder="e.g. Diani"></div>
        @php($currentBedrooms = (int) $value('bedrooms', 1))
        <div><label for="bedrooms">Layout / bedrooms</label><select id="bedrooms" name="bedrooms" required>@foreach(\App\Services\PublicListingCatalog::LAYOUTS + [$currentBedrooms => $currentBedrooms.' bedrooms'] as $layoutValue => $layoutLabel)<option value="{{ $layoutValue }}" @selected($currentBedrooms === $layoutValue)>{{ $layoutLabel }}</option>@endforeach</select></div>
        <div><label for="bathrooms">Bathrooms</label><input id="bathrooms" name="bathrooms" type="number" min="0" max="20" value="{{ $value('bathrooms') }}"></div>
        <div><label for="status">Status</label><select id="status" name="status">@foreach(['active' => 'Active', 'inactive' => 'Inactive'] as $key => $label)<option value="{{ $key }}" @selected($value('status', 'active') === $key)>{{ $label }}</option>@endforeach</select></div>
    </div>
    <h2>Listing</h2>
    @if($partner && !empty($appListing))
        <p>Website listing: <strong>{{ $appListing->title }}</strong> <span class="pill">{{ ucfirst($appListing->status) }}</span>
        @if(!empty($canManageListings)) &middot; <a href="{{ route('public-listings.edit', ['tenant' => $tenantSlug, 'id' => $appListing->id]) }}">Edit listing</a>@endif
        @if($appListing->status === 'published' && !empty($siteEnabled)) &middot; <a href="{{ route('public-stays.show', ['company' => $tenantSlug, 'slug' => $appListing->slug]) }}" target="_blank" rel="noopener noreferrer">View public page &nearr;</a>@endif</p>
        <p class="muted">Its photos are shown to the owner in availability requests{{ $appListing->status === 'published' ? ', and its link is included in the message' : '. Publish it to include its link in the message' }}.</p>
    @elseif($partner)
        <p class="muted">This apartment has no website listing yet.@if(!empty($canManageListings)) <a href="{{ route('public-listings.create', $tenantSlug) }}?apartment=partner:{{ $partner->id }}">Create website listing</a> to add photos and a public page.@endif</p>
    @endif
    <div><label for="listing_url">Other listing link (optional)</label><input id="listing_url" name="listing_url" type="url" maxlength="500" inputmode="url" placeholder="https://havensuitesbnb.com/..." value="{{ old('listing_url', $listingUrl ?? '') }}"><small class="muted">This unit's page on your WordPress site, Airbnb or Booking.com. Used in owner messages when there is no published website listing.</small>@error('listing_url')<p class="error" role="alert">{{ $message }}</p>@enderror</div>    <h2>Featured photo</h2>
    <p class="muted">Shown to the owner in availability requests so they can tell which of their units you mean. A clear photo of the building or the living room works well.</p>
    @if(!empty($hasPhoto))<p><img src="{{ route('partners.photo', [$tenantSlug, $partner->id]) }}?v={{ $photoVersion }}" alt="Current photo" style="max-height:140px;width:auto;border-radius:8px;border:1px solid #dfe5ef"></p><label><input type="checkbox" name="remove_photo" value="1" style="width:auto"> Remove this photo</label>@endif
    <div><label for="photo">{{ !empty($hasPhoto) ? 'Replace photo' : 'Upload photo' }}</label><input id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp">@error('photo')<p class="error" role="alert">{{ $message }}</p>@enderror</div>
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
