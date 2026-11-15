@extends('public_stays.layout')
@section('content')
@php
    $services = [
        ['title' => 'Listing and marketing', 'text' => 'Professional photos, a listing that sells, and promotion on Airbnb, Booking.com, our own website and our network of agents.'],
        ['title' => 'Pricing that follows demand', 'text' => 'Nightly rates adjusted for season, events and day of the week, with weekly and monthly offers to keep the calendar full.'],
        ['title' => 'Guest communication', 'text' => 'We answer enquiries, handle check-in and support guests during their stay, day and night.'],
        ['title' => 'Verified guests', 'text' => 'Every guest registers with ID before arrival, and payments go to the company account named on the official quote.'],
        ['title' => 'Cleaning and maintenance', 'text' => 'Trained housekeeping after every stay, linen and supplies, and repairs handled before they cost you bookings.'],
        ['title' => 'Monthly owner statements', 'text' => 'Clear statements of bookings, income and expenses, with an owner portal to follow your property at any time.'],
    ];
@endphp
<section class="stay-about-hero stay-owner-hero">
    <div class="stay-container">
        <span class="stay-eyebrow">For property owners</span>
        <h1>Earn more from your property, without the day-to-day work</h1>
        <p>{{ $tenant->name }} lists, prices, cleans and manages apartments and holiday homes across Kenya. You keep ownership and see every booking and shilling in your monthly statement.</p>
        <div class="stay-track-actions"><a class="stay-button" href="#owner-enquiry">Tell us about your property &darr;</a>@if($ownerWhatsapp)<a class="stay-button secondary" href="{{ $ownerWhatsapp }}" target="_blank" rel="noopener noreferrer">Talk to us on WhatsApp <span aria-hidden="true">&nearr;</span></a>@endif</div>
    </div>
</section>

<div class="stay-container">
    <section class="stay-section" aria-labelledby="owner-services-heading">
        <span class="stay-eyebrow">What we handle</span><h2 id="owner-services-heading">Full management, from listing to statement</h2>
        <div class="stay-owner-services">@foreach($services as $service)<div><strong>{{ $service['title'] }}</strong><p>{{ $service['text'] }}</p></div>@endforeach</div>
    </section>

    <section class="stay-section" aria-labelledby="owner-steps-heading">
        <span class="stay-eyebrow">How it works</span><h2 id="owner-steps-heading">Three steps to a managed property</h2>
        <ol class="stay-owner-steps">
            <li><strong>Tell us about your property</strong><p>Send the form below or WhatsApp us. We reply within a working day.</p></li>
            <li><strong>We visit and estimate your income</strong><p>We look at the property, the area and comparable stays, and agree a plan and fees with you in writing.</p></li>
            <li><strong>We set up and manage</strong><p>Photos, listing, furnishing advice, then guests, cleaning and pricing. You receive monthly statements.</p></li>
        </ol>
        @if(count($destinations) > 0)<p class="stay-owner-areas">We already manage stays in {{ collect($destinations)->pluck('city')->join(', ', ' and ') }}, and take on properties in other areas of Kenya.</p>@endif
    </section>

    <section class="stay-section stay-owner-form-wrap" id="owner-enquiry" aria-labelledby="owner-form-heading">
        <span class="stay-eyebrow">Get started</span><h2 id="owner-form-heading">Tell us about your property</h2>
        @if($sent)
            <div class="stay-notice" role="status"><strong>Thank you, we have your details.</strong><p>Our team will call you within a working day to talk about your property.@if($ownerWhatsapp) If it is urgent, <a href="{{ $ownerWhatsapp }}" target="_blank" rel="noopener noreferrer">message us on WhatsApp</a>.@endif</p></div>
        @else
            @error('owner_enquiry')<div class="stay-notice error" role="alert">{{ $message }}</div>@enderror
            <form class="stay-owner-form" method="post" action="{{ route('public-stays.owners.enquire', $tenant->slug) }}">
                @csrf
                <input type="hidden" name="form_issued_at" value="{{ $formIssuedAt }}"><input type="hidden" name="form_token" value="{{ $formToken }}">
                <div class="stay-owner-honeypot" aria-hidden="true"><label for="owner-website">Leave this empty</label><input id="owner-website" name="website" tabindex="-1" autocomplete="off"></div>
                <div class="stay-owner-grid">
                    <label>Your name<input name="full_name" required maxlength="150" autocomplete="name" value="{{ old('full_name') }}"></label>
                    <label>Phone or WhatsApp<input name="phone" type="tel" required maxlength="50" autocomplete="tel" placeholder="0712 345 678" value="{{ old('phone') }}"></label>
                    <label>Email <small>optional</small><input name="email" type="email" maxlength="180" autocomplete="email" value="{{ old('email') }}"></label>
                    <label>Where is the property?<input name="location" required maxlength="150" placeholder="e.g. Nyali, Mombasa" value="{{ old('location') }}"></label>
                    <label>Property type<select name="property_type" required>@foreach(\App\Services\OwnerEnquiries::PROPERTY_TYPES as $value => $label)<option value="{{ $value }}" @selected(old('property_type') === $value)>{{ $label }}</option>@endforeach</select></label>
                    <label>Bedrooms<select name="bedrooms" required>@foreach(range(0, 6) as $count)<option value="{{ $count }}" @selected((string) old('bedrooms', '2') === (string) $count)>{{ $count === 0 ? 'Studio' : ($count === 6 ? '6 or more' : $count) }}</option>@endforeach</select></label>
                    <label class="stay-owner-wide">How is it used today?<select name="current_use" required>@foreach(\App\Services\OwnerEnquiries::CURRENT_USE as $value => $label)<option value="{{ $value }}" @selected(old('current_use') === $value)>{{ $label }}</option>@endforeach</select></label>
                    <label class="stay-owner-wide">Anything else we should know? <small>optional</small><textarea name="message" rows="3" maxlength="2000" placeholder="Furnished or not, when it is available, what you hope to earn…">{{ old('message') }}</textarea></label>
                </div>
                <label class="stay-owner-consent"><input type="checkbox" name="consent" value="1" required @checked(old('consent'))> {{ $tenant->name }} may contact me by phone, WhatsApp or email about managing my property.</label>
                <button class="stay-button" type="submit">Send my details &rarr;</button>
                <p class="stay-contact-note">We only use these details to talk to you about your property. No obligation.</p>
            </form>
        @endif
    </section>

    @if($guides->isNotEmpty())
        <section class="stay-section" aria-labelledby="owner-guides-heading">
            <span class="stay-eyebrow">Guides for owners</span><h2 id="owner-guides-heading">Learn how short-term rentals pay in Kenya</h2>
            <div class="stay-blog-grid">@foreach($guides as $guide)<article class="stay-blog-card"><div class="stay-blog-body"><p class="stay-blog-meta">{{ $guide->reading_minutes }} min read</p><h3><a href="{{ route('public-stays.blog.show', [$tenant->slug, $guide->slug]) }}">{{ $guide->title }}</a></h3><p>{{ $guide->summary }}</p></div></article>@endforeach</div>
            <p><a href="{{ route('public-stays.blog', [$tenant->slug, 'for' => 'owners']) }}">All guides for property owners &rarr;</a></p>
        </section>
    @endif
</div>
@endsection
