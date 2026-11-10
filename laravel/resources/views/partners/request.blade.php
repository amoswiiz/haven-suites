@extends('public_stays.layout')
@section('title', 'Availability request')
@section('content')
@php
    $date = fn (?string $value): ?string => $value ? \Illuminate\Support\Carbon::parse($value)->format('D j M Y') : null;
    $answered = $availability->response !== null;
@endphp
<div class="stay-container">
<section class="stay-track" aria-labelledby="request-heading">
    <span class="stay-eyebrow">Availability request from {{ $tenant->name }}</span>
    <h1 id="request-heading">Is your apartment available?</h1>
    @if(session('success'))<div class="stay-track-status" role="status"><strong>{{ session('success') }}</strong></div>@endif
    @if($hasFeatured)<img class="stay-request-featured" src="{{ route('partner-request.featured', $code) }}" alt="{{ $availability->apartment_label }}" width="900" height="560">@endif
    @if($photos->isNotEmpty())
        <div class="stay-request-photos">@foreach($photos as $photo)<img src="{{ route('partner-request.photo', [$code, $photo->id]) }}" alt="{{ $photo->caption ?: $availability->apartment_label }}" loading="lazy" width="320" height="220">@endforeach</div>
    @endif
    <dl class="stay-track-details">
        <div><dt>Apartment</dt><dd>{{ $unit->apartment_name ?? $availability->apartment_label }}</dd></div>
        @if(!empty($unit->building_name) && mb_strtolower($unit->building_name) !== mb_strtolower($unit->apartment_name ?? ''))<div><dt>Building / block</dt><dd>{{ $unit->building_name }}</dd></div>@endif
        @if(!empty($unit->unit_number))<div><dt>Unit number</dt><dd>{{ $unit->unit_number }}</dd></div>@endif
        @if(!empty($unit->location))<div><dt>Area</dt><dd>{{ $unit->location }}</dd></div>@endif
        @if($unit)<div><dt>Size</dt><dd>{{ (int) $unit->bedrooms === 0 ? 'Studio' : $unit->bedrooms.' bedroom'.((int) $unit->bedrooms === 1 ? '' : 's') }}@if($unit->bathrooms !== null) &middot; {{ $unit->bathrooms }} bathroom{{ (int) $unit->bathrooms === 1 ? '' : 's' }}@endif</dd></div>@endif
        <div><dt>Check-in</dt><dd>{{ $date($availability->check_in) ?? 'To be confirmed' }}</dd></div>
        <div><dt>Check-out</dt><dd>{{ $date($availability->check_out) ?? 'To be confirmed' }}</dd></div>
        <div><dt>Nights</dt><dd>{{ $availability->check_in && $availability->check_out ? \Illuminate\Support\Carbon::parse($availability->check_in)->diffInDays(\Illuminate\Support\Carbon::parse($availability->check_out)) : 'To be confirmed' }}</dd></div>
        <div><dt>Guests</dt><dd>{{ $availability->guests ?: 'To be confirmed' }}</dd></div>
    </dl>
    @if($answered)
        <div class="stay-track-status{{ $availability->response === 'unavailable' ? ' is-closed' : '' }}"><strong>Your reply: {{ \App\Services\PartnerAvailabilityRequests::RESPONSES[$availability->response] ?? $availability->response }}</strong>@if($availability->nightly_price)<p>Price: {{ $tenant->currency }} {{ number_format((float) $availability->nightly_price) }} per night</p>@endif @if($availability->response_note)<p>{{ $availability->response_note }}</p>@endif<small>You can change your reply below.</small></div>
    @endif
    <form method="post" action="{{ route('partner-request.respond', $code) }}" class="stay-track-lookup" style="max-width:none">
        @csrf
        <div class="stay-field"><label for="nightly-price">Your price per night ({{ $tenant->currency }}, optional)</label><input id="nightly-price" name="nightly_price" type="number" min="0" step="1" inputmode="numeric" value="{{ old('nightly_price', $availability->nightly_price ? (int) $availability->nightly_price : '') }}"></div>
        <div class="stay-field"><label for="owner-note">Note (optional)</label><textarea id="owner-note" name="note" rows="3" maxlength="500" placeholder="e.g. Available from 2 pm, or other dates that work">{{ old('note', $availability->response_note) }}</textarea></div>
        <div class="stay-track-actions">
            <button type="submit" name="response" value="available" class="stay-button">Yes, it's available</button>
            <button type="submit" name="response" value="unavailable" class="stay-button secondary">Not available</button>
        </div>
    </form>
    @if($listingUrl)<p><a href="{{ $listingUrl }}" target="_blank" rel="noopener noreferrer">See this apartment's listing &nearr;</a></p>@endif
    <p class="stay-help">Not the right unit? Reply in WhatsApp and let us know. Your reply goes straight to the {{ $tenant->name }} team.</p>
</section>
</div>
@endsection
