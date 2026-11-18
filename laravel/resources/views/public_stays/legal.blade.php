@extends('public_stays.layout')
@section('content')
@php
    $contactEmail = $site->contact_email ?? null;
    $contactPhone = $site->contact_phone ?? null;
    $whatsappUrl = $websiteContactLinks['whatsapp_url'] ?? null;
    $updated = 'November 2026';
@endphp
<section class="stay-about-hero">
    <div class="stay-container">
        <span class="stay-eyebrow">{{ $legalPage === 'privacy' ? 'Privacy policy' : 'Your data' }}</span>
        <h1>{{ $legalPage === 'privacy' ? 'How we look after your information' : 'How to have your data deleted' }}</h1>
        <p>{{ $tenant->name }} · Last updated {{ $updated }}</p>
    </div>
</section>
<div class="stay-container stay-section stay-legal">
@if($legalPage === 'privacy')
    <h2>Who we are</h2>
    <p>{{ $tenant->name }} arranges short and long stays in furnished apartments in Kenya. This policy explains what personal information we collect when you use this website or contact us, why we collect it, and your rights under the Kenya Data Protection Act, 2019.</p>
    <h2>What we collect</h2>
    <ul>
        <li><strong>Enquiries and bookings:</strong> your name, phone number, email address, travel dates, number of guests and any message you send us.</li>
        <li><strong>Messages on social media:</strong> when you comment on our posts or message us on Facebook, Instagram or WhatsApp, we receive your name, your public profile name or username, and what you wrote, so our team can reply.</li>
        <li><strong>Identity checks for stays:</strong> when needed for check-in, the identity details you choose to send us.</li>
        <li><strong>Website use:</strong> if enabled, Google Analytics and Meta Pixel record pages visited and actions such as sending an enquiry, using cookies. They do not give us your name.</li>
    </ul>
    <h2>How we use it</h2>
    <p>To answer your questions, check availability, prepare quotes and invoices, confirm and manage your stay, send booking messages, and improve our website and service. We do not sell your information.</p>
    <h2>Who we share it with</h2>
    <p>Only as needed for your stay: the owner or manager of the apartment you book, payment and messaging providers that send our SMS and WhatsApp messages, and Meta when we reply to you on Facebook, Instagram or WhatsApp. We may also share information when the law requires it.</p>
    <h2>How long we keep it</h2>
    <p>Enquiries and messages are kept for as long as needed to serve you and for our business records, normally up to seven years for booking and payment records required by law. You can ask us to delete information we do not need to keep.</p>
    <h2>Your rights</h2>
    <p>You can ask to see the information we hold about you, correct it, object to its use, or have it deleted. See <a href="{{ route('public-stays.data-deletion', $tenant->slug) }}">how to have your data deleted</a>. You may also complain to the Office of the Data Protection Commissioner of Kenya.</p>
@else
    <h2>Ask us to delete your information</h2>
    <p>You can ask {{ $tenant->name }} to delete the personal information we hold about you, including messages and comments you sent us on Facebook, Instagram or WhatsApp.</p>
    <ol>
        <li>Contact us @if($contactEmail)by email at <a href="mailto:{{ $contactEmail }}?subject={{ rawurlencode('Data deletion request') }}">{{ $contactEmail }}</a>@endif @if($whatsappUrl) or on <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer">WhatsApp</a>@endif @if($contactPhone) or by phone on {{ $contactPhone }}@endif.</li>
        <li>Tell us your name and the phone number, email address or social media account you used with us.</li>
        <li>We confirm your request and delete your information within 30 days, except records we must keep by law, such as invoices and payments. We tell you when it is done.</li>
    </ol>
    <h2>Facebook and Instagram</h2>
    <p>To stop sharing information with us through Facebook, open Facebook Settings &rarr; Apps and websites, find our app and remove it. Removing it does not delete messages already sent to us; contact us as above and we will delete them.</p>
    <p>Read our full <a href="{{ route('public-stays.privacy', $tenant->slug) }}">privacy policy</a>.</p>
@endif
</div>
@endsection
