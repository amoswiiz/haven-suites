@extends('public_stays.layout')
@section('title', 'Your enquiry')
@section('content')
@php
    $formatDate = static fn (?string $date): ?string => $date ? \Illuminate\Support\Carbon::parse($date)->format('D j M Y') : null;
    $closed = $status['step'] === 0;
    $whatsappUrl = $websiteContactLinks['whatsapp_url'] ?? null;
    $phone = $site->contact_phone ?? null;
@endphp
<div class="stay-container">
<section class="stay-track" aria-labelledby="track-heading">
    <span class="stay-eyebrow">Enquiry {{ $reference }}</span>
    <h1 id="track-heading">{{ $firstName !== '' ? 'Hi '.$firstName.', here' : 'Here' }} is the latest on your enquiry</h1>
    <div class="stay-track-status{{ $closed ? ' is-closed' : '' }}" role="status">
        <strong>{{ $status['label'] }}</strong>
        <p>{{ $status['message'] }}</p>
        @if($updatedAt)<small>Last updated {{ \Illuminate\Support\Carbon::parse($updatedAt)->timezone($tenant->timezone ?: config('app.timezone'))->format('j M Y, H:i') }}</small>@endif
    </div>
    @unless($closed)
        <ol class="stay-track-steps" aria-label="Enquiry progress">
            @foreach($steps as $index => $step)
                @php($position = $index + 1)
                <li class="{{ $position < $status['step'] ? 'is-done' : ($position === $status['step'] ? 'is-current' : '') }}" @if($position === $status['step']) aria-current="step" @endif>
                    <span class="stay-track-dot" aria-hidden="true">{{ $position < $status['step'] ? '✓' : $position }}</span>
                    <span>{{ $step }}</span>
                </li>
            @endforeach
        </ol>
    @endunless
    <dl class="stay-track-details">
        @if($stay['apartment'])<div><dt>Apartment</dt><dd>{{ $stay['apartment'] }}</dd></div>@endif
        @if($stay['location'])<div><dt>Location</dt><dd>{{ $stay['location'] }}</dd></div>@endif
        <div><dt>Dates</dt><dd>@if($stay['check_in'] && $stay['check_out']){{ $formatDate($stay['check_in']) }} &rarr; {{ $formatDate($stay['check_out']) }}@else To be confirmed @endif</dd></div>
        <div><dt>Guests</dt><dd>{{ $stay['guests'] }}</dd></div>
    </dl>
    <p class="stay-help">This page shows your enquiry's progress only. An enquiry does not reserve an apartment until your booking is confirmed.</p>
    @if($whatsappUrl || $phone)
        <div class="stay-track-actions">
            @if($whatsappUrl)<a class="stay-button" href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer">Ask us on WhatsApp <span aria-hidden="true">&nearr;</span></a>@endif
            @if($phone)<span>Call us: <a href="tel:{{ $phone }}">{{ $phone }}</a></span>@endif
        </div>
    @endif
    <p class="stay-help">Please quote <strong>{{ $reference }}</strong> when you contact us.</p>
</section>
</div>
@endsection
