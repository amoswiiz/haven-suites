@extends('public_stays.layout')
@section('content')
@if($preview)
    <div class="stay-preview">Staff preview &middot; This page does not publish the apartment. <a href="{{ route('public-listings.edit', [$tenant->slug, $listing['id']]) }}">Return to listing editor</a></div>
@endif
@php
    $unconfirmedMessage = !empty($listing['partner_apartment_id']) ? 'Our team confirms these dates with the apartment owner before accepting your booking.' : 'The external calendar needs confirmation. Our team will verify your dates.';
    $bedroomLabel = $listing['layout_label'] ?? \App\Services\PublicListingCatalog::layoutLabel($listing['bedrooms'], $listing['property_type'] ?? null) ?? 'Bedroom details on request';
    $bedLabel = !empty($listing['beds']) ? $listing['beds'].' bed'.($listing['beds'] === 1 ? '' : 's') : null;
    $bathroomLabel = $listing['bathrooms'] === null ? 'Bathroom details on request' : $listing['bathrooms'].' bathroom'.($listing['bathrooms'] === 1 ? '' : 's');
    $listingLinks = $publicLinks ?? ['public_url' => route('public-stays.show', [$tenant->slug, $listing['slug']]), 'enquiry_url' => route('public-stays.show', [$tenant->slug, $listing['slug']]).'#enquire', 'whatsapp_url' => null];
    $safeEnquiryDefaults = is_array($enquiryDefaults ?? null) ? $enquiryDefaults : [];
    $enquiryValue = static function (string $field, mixed $fallback = '') use ($safeEnquiryDefaults): string {
        $value = old($field, $safeEnquiryDefaults[$field] ?? $fallback);
        return is_scalar($value) ? (string) $value : '';
    };
    $reviews = $reviews ?? ['average' => null, 'count' => 0, 'reviews' => collect()];
    $discountText = implode(' · ', array_filter([
        !empty($listing['weekly_discount']) ? $listing['weekly_discount'].'% off weekly stays' : null,
        !empty($listing['monthly_discount']) ? $listing['monthly_discount'].'% off monthly stays' : null,
    ]));
    $shareText = $listing['title'].' – '.$listingLinks['public_url'];
@endphp
<div class="stay-container">
    <p class="stay-breadcrumb"><a href="{{ $preview ? route('public-listings.index', $tenant->slug) : route('public-stays.index', $tenant->slug) }}">{{ $preview ? 'All listings' : 'All stays' }}</a> &nbsp;/&nbsp; {{ $listing['city'] }}</p>
    <div class="stay-detail-heading"><span class="stay-eyebrow">{{ $listing['neighbourhood'] ?: $listing['city'] }}</span><h1>{{ $listing['title'] }}</h1><p>{{ $listing['public_location'] ?: $listing['city'] }} &middot; {{ $listing['max_guests'] ? 'Up to '.$listing['max_guests'].' guests' : 'Guest capacity to be confirmed' }} &middot; {{ $bedroomLabel }}@if($bedLabel) &middot; {{ $bedLabel }}@endif &middot; {{ $bathroomLabel }}@if(!empty($listing['listing_code'])) &middot; Code {{ $listing['listing_code'] }}@endif</p>@if(!empty($reviews['count']))<p class="stay-rating-line"><a href="#stay-reviews">@include('public_stays.stars', ['rating' => $reviews['average']]) <strong>{{ number_format($reviews['average'], 1) }}</strong> &middot; {{ $reviews['count'] }} {{ $reviews['count'] === 1 ? 'review' : 'reviews' }}</a></p>@endif @if(!empty($listing['collection_labels']))<p class="stay-card-tags">@foreach($listing['collection_labels'] as $collectionLabel)<span class="stay-tag">{{ $collectionLabel }}</span>@endforeach</p>@endif</div>
    <div class="stay-summary-price"><div>@if((float)$listing['starting_price'] > 0)<span>From</span> <strong>{{ $listing['currency'] }} {{ number_format((float)$listing['starting_price'], (float)$listing['starting_price'] == floor((float)$listing['starting_price']) ? 0 : 2) }}</strong> <span>per night</span>@else<strong>Price on request</strong>@endif @if($discountText)<span class="stay-discount">{{ $discountText }}</span>@endif</div><a href="#stay-availability">Explore your dates &darr;</a></div>
    @if(!$preview && ($canEnquire || !empty($listingLinks['whatsapp_url'])))
        <div class="stay-listing-actions" data-quick-contact>
            @if($canEnquire)<a class="stay-button" href="{{ $listingLinks['enquiry_url'] }}" aria-label="Enquire about {{ $listing['title'] }}">Enquire now <span aria-hidden="true">&rarr;</span></a>@endif
            @if(!empty($listingLinks['whatsapp_url']))<a class="stay-button secondary stay-whatsapp-button" href="{{ $listingLinks['whatsapp_url'] }}" target="_blank" rel="noopener noreferrer" data-whatsapp-enquiry data-wa-number="{{ $listingLinks['whatsapp_number'] ?? '' }}" data-wa-base="{{ $listingLinks['whatsapp_base'] ?? '' }}" data-wa-code="{{ $listing['listing_code'] ?? '' }}">WhatsApp us <span aria-hidden="true">&nearr;</span></a>@endif
            <a class="stay-share" href="https://wa.me/?text={{ rawurlencode($shareText) }}" target="_blank" rel="noopener noreferrer" data-share-url="{{ $listingLinks['public_url'] }}" data-share-title="{{ $listing['title'] }}">Share <span aria-hidden="true">&nearr;</span></a>
            <span class="stay-quick-note">Our team confirms your dates and final price.</span>
        </div>
    @endif
    @include('public_stays.media')
    <nav class="stay-section-nav" aria-label="Explore this apartment"><a href="#stay-overview">About the stay</a>@if(!empty($listing['videos']))<a href="#stay-videos">Video tours</a>@endif @if($listing['amenity_labels'])<a href="#stay-amenities">Amenities</a>@endif<a href="#stay-availability">Availability</a>@if($reviews['count'])<a href="#stay-reviews">Reviews</a>@endif<a href="#stay-rules">Good to know</a>@if($listing['public_location'])<a href="#stay-location">Location</a>@endif</nav>
    <div class="stay-detail-grid"><div>
        <section class="stay-detail-section" id="stay-overview"><h2>Make yourself at home</h2><p class="stay-description">{{ $listing['description'] ?: 'Add a description in the listing editor to complete this preview.' }}</p>
            <ul class="stay-layout-facts" aria-label="Rooms and beds"><li><strong>{{ $bedroomLabel }}</strong>@if(!empty($listing['property_type_label']) && $listing['bedrooms'] !== 0)<span>{{ $listing['property_type_label'] }}</span>@endif</li>@if($bedLabel)<li><strong>{{ $bedLabel }}</strong>@if(!empty($listing['bed_setup']))<span>{{ $listing['bed_setup'] }}</span>@endif</li>@endif<li><strong>{{ $bathroomLabel }}</strong></li>@if($listing['max_guests'])<li><strong>Up to {{ $listing['max_guests'] }} guests</strong></li>@endif</ul></section>
        @include('public_stays.videos')
        @if($listing['amenity_labels'])<section class="stay-detail-section" id="stay-amenities"><h2>What this stay offers</h2><ul class="stay-amenities">@foreach($listing['amenity_labels'] as $amenity)<li>{{ $amenity }}</li>@endforeach</ul></section>@endif
        <section class="stay-detail-section" id="stay-availability" aria-labelledby="public-calendar-heading"><h2 id="public-calendar-heading">Explore your dates</h2><p class="stay-muted">Choose your preferred arrival and departure dates in the enquiry form. Our team checks availability again before confirming your booking.</p>
            <div data-stay-calendar data-calendar-url="{{ $preview ? '' : route('public-stays.calendar', [$tenant->slug, $listing['slug']]) }}" data-month="{{ $month->format('Y-m') }}" data-today="{{ $today }}" data-unconfirmed-message="{{ $unconfirmedMessage }}" data-maximum="{{ \Carbon\CarbonImmutable::parse($today)->addYears(2)->subMonth()->format('Y-m') }}">
                <div class="stay-calendar-head"><button type="button" data-calendar-prev aria-label="Previous month" @disabled($preview)>&larr;</button><h3 data-calendar-label>{{ $month->format('F Y') }}</h3><button type="button" data-calendar-next aria-label="Next month" @disabled($preview)>&rarr;</button></div>
                <table class="stay-calendar" aria-label="Apartment availability"><thead><tr>@foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $weekday)<th scope="col">{{ $weekday }}</th>@endforeach</tr></thead><tbody data-calendar-grid>
                    @foreach($weeks as $week)
                        <tr>@foreach($week as $day)<td class="{{ !$day ? 'empty' : ($day['past'] ? 'past' : ($day['blocked'] ? 'blocked' : 'available')) }}" @if($day) aria-label="{{ $day['date'] }}: {{ $day['past'] ? 'Past date' : ($day['blocked'] ? 'Unavailable night' : 'No recorded block') }}" @endif>{{ $day['day'] ?? '' }}</td>@endforeach</tr>
                    @endforeach
                </tbody></table>
                <div class="stay-calendar-legend"><span>No recorded block</span><span>Unavailable night</span></div><p class="stay-calendar-status" data-calendar-status aria-live="polite">{{ !$calendar['fresh'] ? $unconfirmedMessage : 'Availability is subject to confirmation. Check-out dates do not count as an additional night.' }}</p>
            </div>
        </section>
        @if($reviews['count'])
            <section class="stay-detail-section" id="stay-reviews" aria-labelledby="stay-reviews-heading"><h2 id="stay-reviews-heading">@include('public_stays.stars', ['rating' => $reviews['average']]) {{ number_format($reviews['average'], 1) }} &middot; {{ $reviews['count'] }} {{ $reviews['count'] === 1 ? 'review' : 'reviews' }}</h2>
                <div class="stay-review-list">@foreach($reviews['reviews'] as $review)@include('public_stays.review_card', ['review' => $review])@endforeach</div>
            </section>
        @endif
        @if(isset($guestInfo))@include('public_stays.host')@endif
        <section class="stay-detail-section" id="stay-rules"><h2>Good to know</h2><div class="stay-times"><div><small>Check-in from</small><strong>{{ substr($listing['check_in_time'],0,5) }}</strong></div><div><small>Check-out by</small><strong>{{ substr($listing['check_out_time'],0,5) }}</strong></div><div><small>Minimum stay</small><strong>{{ $listing['min_nights'] ? $listing['min_nights'].' night'.($listing['min_nights'] === 1 ? '' : 's') : 'To be confirmed' }}</strong></div></div>
            <h3>House rules</h3><p class="stay-description">{{ $listing['house_rules'] ?: 'Please contact our team with any questions about your stay.' }}</p>
            @if(isset($guestInfo))
                <h3>Cancellations</h3>@foreach($guestInfo['cancellation'] as $paragraph)<p>{{ $paragraph }}</p>@endforeach
                <h3>Deposit and payment</h3>@foreach($guestInfo['payment'] as $paragraph)<p>{{ $paragraph }}</p>@endforeach
                <p><a href="{{ route('public-stays.faq', $tenant->slug) }}">Read our frequently asked questions &rarr;</a></p>
            @endif
        </section>
        @if($listing['public_location'])
            <section class="stay-detail-section" id="stay-location"><h2>Your neighbourhood</h2><p class="stay-muted">{{ $listing['public_location'] }}</p><a href="https://www.google.com/maps/search/?api=1&amp;query={{ rawurlencode($listing['public_location'].' '.$listing['city']) }}" target="_blank" rel="noopener noreferrer">Explore the area on a map &nearr;</a></section>
        @endif
    </div><aside class="stay-enquiry" id="enquire"><div class="stay-price">
        @if((float)$listing['starting_price'] > 0)<small>From</small><strong>{{ $listing['currency'] }} {{ number_format((float)$listing['starting_price'], (float)$listing['starting_price'] == floor((float)$listing['starting_price']) ? 0 : 2) }}</strong><small>per night &middot; Final price confirmed by our team</small>@else<strong>Price on request</strong><small>Our team will prepare your quote.</small>@endif
    </div><h2>Let's plan your stay</h2><p>Tell us when you'd like to visit. We'll confirm availability and help you with the next steps.</p>
        @if($canEnquire)
            <form method="post" action="{{ route('public-stays.enquire', [$tenant->slug, $listing['slug']]) }}" data-enquiry-form data-min-nights="{{ $listing['min_nights'] }}" data-nightly="{{ (float) $listing['starting_price'] > 0 ? (float) $listing['starting_price'] : '' }}" data-cleaning="{{ $listing['cleaning_fee'] ?? '' }}" data-weekly="{{ $listing['weekly_discount'] ?? '' }}" data-monthly="{{ $listing['monthly_discount'] ?? '' }}" data-currency="{{ $listing['currency'] }}">
                @csrf
                @foreach($formContext as $key=>$value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
                <div class="stay-honey" aria-hidden="true"><label for="website">Website</label><input type="text" id="website" name="website" tabindex="-1" autocomplete="off"></div>
                <div class="stay-form-grid"><div class="stay-field"><label for="enquiry-check-in">Check-in *</label><input type="date" id="enquiry-check-in" name="check_in" required min="{{ $today }}" value="{{ $enquiryValue('check_in') }}"></div><div class="stay-field"><label for="enquiry-check-out">Check-out *</label><input type="date" id="enquiry-check-out" name="check_out" required min="{{ $today }}" value="{{ $enquiryValue('check_out') }}"></div></div>
                <div class="stay-estimate" data-price-estimate aria-live="polite" hidden></div>
                <div class="stay-field"><label for="enquiry-guests">Guests *</label><input type="number" id="enquiry-guests" name="guests" required min="1" max="{{ $listing['max_guests'] }}" value="{{ $enquiryValue('guests', 1) }}"></div>
                <div class="stay-field"><label for="full-name">Your full name *</label><input type="text" id="full-name" name="full_name" required minlength="2" maxlength="150" autocomplete="name" value="{{ $enquiryValue('full_name') }}"></div>
                <div class="stay-field"><label for="enquiry-phone">Phone number *</label><input type="tel" id="enquiry-phone" name="phone" required maxlength="50" autocomplete="tel" inputmode="tel" placeholder="+254 712 345 678" aria-describedby="enquiry-phone-help" value="{{ $enquiryValue('phone', \App\Support\PhoneNumber::DEFAULT_PREFIX.' ') }}"><small class="stay-help" id="enquiry-phone-help">Kenyan numbers: type the 9 digits after +254. Outside Kenya, replace +254 with your country code.</small></div>
                <div class="stay-field"><label for="enquiry-email">Email address (optional)</label><input type="email" id="enquiry-email" name="email" maxlength="180" autocomplete="email" value="{{ $enquiryValue('email') }}"></div>
                <div class="stay-field"><label for="enquiry-message">Anything you'd like us to know?</label><textarea id="enquiry-message" name="message" rows="3" maxlength="3000" placeholder="A special occasion, accessibility needs or a question…">{{ $enquiryValue('message') }}</textarea></div>
                <label class="stay-check stay-field"><input type="checkbox" name="flexible_dates" value="1" @checked(old('flexible_dates'))><span>My dates are flexible. Please suggest alternatives if needed.</span></label>
                <label class="stay-check stay-field"><input type="checkbox" name="consent" value="1" required @checked(old('consent'))><span>I agree to be contacted by {{ $tenant->name }} about this enquiry, including an acknowledgement SMS.</span></label>
                <button type="submit" class="stay-button wide">Enquire about these dates &rarr;</button>@if(!empty($listingLinks['whatsapp_url']))<a class="stay-button secondary wide stay-whatsapp-enquire" href="{{ $listingLinks['whatsapp_url'] }}" target="_blank" rel="noopener noreferrer" data-whatsapp-enquiry data-wa-number="{{ $listingLinks['whatsapp_number'] ?? '' }}" data-wa-base="{{ $listingLinks['whatsapp_base'] ?? '' }}" data-wa-code="{{ $listing['listing_code'] ?? '' }}">Enquire on WhatsApp instead <span aria-hidden="true">&nearr;</span></a>@endif<p class="stay-help" data-enquiry-status aria-live="polite">No payment is taken here. Sending an enquiry does not reserve the apartment.</p>
            </form>
        @else
            <div class="stay-notice">{{ $preview ? 'This is a staff preview. Guest enquiries are enabled only on the published page.' : 'Please contact our team directly to plan this stay.' }}</div>
        @endif
    </aside></div>
    @if($alternatives->isNotEmpty())
        <section class="stay-section"><span class="stay-eyebrow">Keep exploring</span><h2>More stays in {{ $listing['city'] }}</h2><div class="stay-grid">@foreach($alternatives as $stay)@include('public_stays.card')@endforeach</div></section>
    @endif
</div>
@if($canEnquire)
    <a href="#enquire" class="stay-button stay-mobile-enquire" data-mobile-enquire hidden>Enquire about these dates &rarr;</a>
@endif
@endsection
