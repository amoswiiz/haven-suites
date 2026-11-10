@extends('public_stays.layout')
@section('content')
@php
    $browseUrl = route('public-stays.index', $tenant->slug);
    $whatsappUrl = $websiteContactLinks['whatsapp_url'] ?? null;
    $phone = $site->contact_phone ?? null;
    $email = $site->contact_email ?? null;
@endphp
<section class="stay-about-hero">
    <div class="stay-container">
        <span class="stay-eyebrow">About us</span>
        <h1>Who we are</h1>
        @foreach($about['intro'] as $paragraph)<p>{{ $paragraph }}</p>@endforeach
        <div class="stay-track-actions">
            <a class="stay-button" href="{{ $browseUrl }}#find-stay">Find a stay <span aria-hidden="true">&rarr;</span></a>
            @if($whatsappUrl)<a class="stay-button secondary" href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer">WhatsApp us <span aria-hidden="true">&nearr;</span></a>@endif
        </div>
    </div>
</section>

<div class="stay-container">
    <section class="stay-about-section" aria-labelledby="how-we-work">
        <span class="stay-eyebrow">How we work</span>
        <h2 id="how-we-work">From first message to check-out</h2>
        <ol class="stay-about-steps">
            @foreach($about['steps'] as $step)
                <li><strong>{{ $step['title'] }}</strong>@if($step['text'] !== '')<p>{{ $step['text'] }}</p>@endif</li>
            @endforeach
        </ol>
    </section>

    <section class="stay-about-section" aria-labelledby="where-we-are">
        <span class="stay-eyebrow">Where we are</span>
        <h2 id="where-we-are">Based in {{ implode(' and ', array_column($about['main_locations'], 'name')) }}</h2>
        <div class="stay-about-locations">
            @foreach($about['main_locations'] as $location)
                <div class="stay-about-location">
                    <h3>{{ $location['name'] }}</h3>
                    @if($location['count'] > 0)
                        <p>{{ $location['count'] }} {{ $location['count'] === 1 ? 'stay' : 'stays' }} to explore.</p>
                        <a href="{{ $browseUrl }}?city={{ rawurlencode($location['name']) }}#find-stay">See stays in {{ $location['name'] }} <span aria-hidden="true">&rarr;</span></a>
                    @else
                        <p>Ask us about apartments in {{ $location['name'] }}.</p>
                        @if($whatsappUrl)<a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer">Ask on WhatsApp <span aria-hidden="true">&nearr;</span></a>@endif
                    @endif
                </div>
            @endforeach
        </div>
        <h3 class="stay-about-subheading">Other areas</h3>
        <p>{{ $about['other_areas'] }}</p>
        @if($about['other_destinations'])
            <div class="stay-collections" aria-label="Other destinations with stays">
                @foreach($about['other_destinations'] as $destination)<a class="stay-chip" href="{{ $browseUrl }}?city={{ rawurlencode($destination['city']) }}#find-stay">{{ $destination['city'] }} <span>{{ $destination['count'] }}</span></a>@endforeach
            </div>
        @endif
    </section>

    <section class="stay-about-section stay-about-contact" aria-labelledby="get-in-touch">
        <h2 id="get-in-touch">Get in touch</h2>
        <p>Tell us your dates, number of guests and preferred area, and our team will help you plan your stay.</p>
        <ul>
            @if($phone)<li>Call: <a href="tel:{{ preg_replace('/[^+0-9]/', '', $phone) }}">{{ $phone }}</a></li>@endif
            @if($email)<li>Email: <a href="mailto:{{ $email }}">{{ $email }}</a></li>@endif
            @if($whatsappUrl)<li><a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer">Chat with us on WhatsApp <span aria-hidden="true">&nearr;</span></a></li>@endif
            <li><a href="{{ route('public-stays.track', $tenant->slug) }}">Track an existing enquiry</a></li>
        </ul>
        <a class="stay-button" href="{{ $browseUrl }}#find-stay">Find a stay <span aria-hidden="true">&rarr;</span></a>
    </section>
</div>
@endsection
