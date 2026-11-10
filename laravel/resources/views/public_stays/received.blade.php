@extends('public_stays.layout')
@section('title', 'Enquiry received')
@section('content')
<div class="stay-container">
<section class="stay-track" aria-labelledby="received-heading">
    <span class="stay-eyebrow">Enquiry received</span>
    <h1 id="received-heading">Thank you for considering {{ $tenant->name }}!</h1>
    <div class="stay-reference">
        <span>Your enquiry reference</span>
        <strong data-copy-source>{{ $reference }}</strong>
        <button type="button" class="stay-button secondary" data-copy-button hidden>Copy reference</button>
        <span class="stay-help" data-copy-status aria-live="polite"></span>
    </div>
    <p>@if($smsSentTo)We've sent this reference by SMS to the number ending in <strong>{{ $smsSentTo }}</strong>. @endif Please quote it whenever you contact us.</p>
    <div class="stay-track-actions">
        @if(!empty($trackingUrl))<a class="stay-button" href="{{ $trackingUrl }}">Track your enquiry <span aria-hidden="true">&rarr;</span></a>@endif
        @if(!empty($followUpWhatsapp))<a class="stay-button secondary" href="{{ $followUpWhatsapp }}" target="_blank" rel="noopener noreferrer">WhatsApp us about it <span aria-hidden="true">&nearr;</span></a>@endif
    </div>
    @if(!empty($trackingUrl))<p class="stay-help">Bookmark the tracking page to check progress at any time.</p>@endif
    <h2 class="stay-next-heading">What happens next</h2>
    <ol class="stay-next-steps">
        <li><strong>We check your dates.</strong> Our team confirms availability for the apartment you chose.</li>
        <li><strong>We get back to you within {{ $responseHours }} {{ $responseHours === 1 ? 'hour' : 'hours' }}</strong> by phone, SMS or WhatsApp with the price and next steps.</li>
        <li><strong>You confirm your stay.</strong> Your booking is secured once your deposit is received.</li>
    </ol>
    <p class="stay-help">This enquiry does not reserve an apartment and no payment has been taken.</p>
    <a href="{{ route('public-stays.index', $tenant->slug) }}">Explore more stays</a>
</section>
</div>
@endsection
