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
    <dl class="stay-track-details">
        <div><dt>Apartment</dt><dd>{{ $availability->apartment_label }}</dd></div>
        <div><dt>Dates</dt><dd>@if($availability->check_in && $availability->check_out){{ $date($availability->check_in) }} &rarr; {{ $date($availability->check_out) }}@elseif($availability->check_in)From {{ $date($availability->check_in) }}@else To be confirmed @endif</dd></div>
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
    <p class="stay-help">Your reply goes straight to the {{ $tenant->name }} team. You can also reply in WhatsApp.</p>
</section>
</div>
@endsection
