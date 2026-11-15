@extends('public_stays.layout')
@section('content')
@php
    $layout = $layout ?? ['search' => false, 'cards' => false, 'explore' => false];
@endphp
@unless($layout['search'])@include('public_stays.home_classic_hero')@endunless
<div class="stay-container" id="find-stay">
    @php
        $activeFilters = [];
        $removeFilter = fn (string ...$keys): string => request()->fullUrlWithQuery(array_fill_keys([...$keys, 'page'], null)).'#find-stay';
        if (!empty($filters['city'])) { $activeFilters[] = ['label' => $filters['city'], 'url' => $removeFilter('city', 'area')]; }
        if (!empty($filters['area'])) { $activeFilters[] = ['label' => $filters['area'], 'url' => $removeFilter('area')]; }
        if (!empty($filters['collection'])) { $activeFilters[] = ['label' => \App\Services\PublicListingCatalog::COLLECTIONS[$filters['collection']], 'url' => $removeFilter('collection')]; }
        if (!empty($filters['type'])) { $activeFilters[] = ['label' => \App\Services\PublicListingCatalog::PROPERTY_TYPES[$filters['type']], 'url' => $removeFilter('type')]; }
        if (isset($filters['bedrooms']) && $filters['bedrooms'] !== null) { $activeFilters[] = ['label' => (int) $filters['bedrooms'] === 0 ? 'Studio' : ((int) $filters['bedrooms'] >= 6 ? '6+ bedrooms' : $filters['bedrooms'].' bedroom'.((int) $filters['bedrooms'] === 1 ? '' : 's')), 'url' => $removeFilter('bedrooms')]; }
        if (!empty($filters['q'])) { $activeFilters[] = ['label' => '“'.$filters['q'].'”', 'url' => $removeFilter('q')]; }
        if (isset($filters['min_price']) || isset($filters['max_price'])) { $activeFilters[] = ['label' => 'Price', 'url' => $removeFilter('min_price', 'max_price')]; }
        if (!empty($filters['amenities'])) { $activeFilters[] = ['label' => count($filters['amenities']).' '.(count($filters['amenities']) === 1 ? 'amenity' : 'amenities'), 'url' => $removeFilter('amenities')]; }
    @endphp
    @if($layout['search'])
        @include('public_stays.home_search')
    @else
        @include('public_stays.home_classic_search')
    @endif
    <section class="stay-section" aria-labelledby="stays-heading"><div class="stay-results-head"><div><span class="stay-eyebrow">Somewhere you'll love</span><h2 id="stays-heading">Find your next stay</h2><p>{{ number_format($stays->total()) }} {{ $stays->total() === 1 ? 'stay' : 'stays' }} {{ !empty($filters['check_in']) ? 'matching your dates and preferences' : 'to explore' }} &middot; {{ $layout['cards'] && !empty($filters['check_in']) && !empty($filters['check_out']) ? 'Prices are estimated totals for your dates; our team confirms the final price.' : 'Prices shown are starting nightly rates.' }}</p></div><div><label for="stay-sort">Sort stays</label><select id="stay-sort" name="sort" form="stay-search-form" data-sort-stays><option value="recommended" @selected(($filters['sort'] ?? 'recommended') === 'recommended')>Recommended</option><option value="price_asc" @selected(($filters['sort'] ?? '') === 'price_asc')>Lowest starting price</option><option value="price_desc" @selected(($filters['sort'] ?? '') === 'price_desc')>Highest starting price</option></select></div></div>
        @if($stays->count())
            @if($layout['explore'])
                <div class="stay-map-view" data-map-view hidden data-leaflet-js="{{ \App\Support\AssetVersion::url('assets/maps/leaflet-1.9.4/leaflet.js') }}" data-leaflet-css="{{ \App\Support\AssetVersion::url('assets/maps/leaflet-1.9.4/leaflet.css') }}">
                    <div class="stay-map-canvas" data-map-canvas role="region" aria-label="Map of stays on this page"></div>
                    <p class="stay-map-note">Pins show each stay's general area, not the exact building.</p>
                </div>
            @endif
            <div class="stay-grid">@foreach($stays as $stay)@include('public_stays.card')@endforeach</div>{{ $stays->links('public_stays.pagination') }}
        @else
            <div class="stay-empty"><h2>Let's find the right stay for you.</h2><p>No stays match these preferences right now. Try removing a filter, choosing flexible dates or another destination, or ask our team: we often know of options that are not listed yet.</p><div class="stay-track-actions" style="justify-content:center"><a class="stay-button secondary" href="{{ route('public-stays.index', $tenant->slug) }}#find-stay">Show all stays</a>@if(!empty($websiteContactLinks['whatsapp_url']))<a class="stay-button" href="{{ $websiteContactLinks['whatsapp_url'] }}" target="_blank" rel="noopener noreferrer">Ask us on WhatsApp <span aria-hidden="true">&nearr;</span></a>@endif</div></div>
        @endif
    </section>
    @php
        $corporateWhatsapp = !empty($site->whatsapp_number) && ($number = \App\Support\PhoneNumber::normalize($site->whatsapp_number))
            ? 'https://wa.me/'.ltrim($number, '+').'?text='.rawurlencode('Hello '.$tenant->name.', I am looking for a monthly or corporate stay. Dates: , Guests: , Area: ')
            : null;
    @endphp
    <section class="stay-corporate" aria-labelledby="corporate-heading">
        <div>
            <span class="stay-eyebrow">Long stays &amp; companies</span>
            <h2 id="corporate-heading">Staying a month or more, or booking for your team?</h2>
            <p>Furnished apartments with Wi-Fi and a full kitchen, at monthly rates. We can invoice your company or organisation and host consultants, relocations and project teams.</p>
        </div>
        <div class="stay-track-actions">
            <a class="stay-button" href="{{ route('public-stays.index', [$tenant->slug, 'collection' => 'long_stay']) }}#find-stay">See long-stay apartments &rarr;</a>
            @if($corporateWhatsapp)<a class="stay-button secondary" href="{{ $corporateWhatsapp }}" target="_blank" rel="noopener noreferrer">Ask for monthly or corporate rates <span aria-hidden="true">&nearr;</span></a>@endif
        </div>
    </section>
    @if(($reviewHighlights ?? collect())->isNotEmpty())
        <section class="stay-section" aria-labelledby="home-reviews-heading"><span class="stay-eyebrow">Guest stories</span><h2 id="home-reviews-heading">What our guests say</h2>
            <div class="stay-review-grid">@foreach($reviewHighlights as $review)@include('public_stays.review_card', ['review' => $review, 'stayLink' => $listingTitles[$review->listing_id] ?? null])@endforeach</div>
        </section>
    @endif
</div>
<section class="stay-why" aria-label="Why book with us"><div class="stay-container stay-why-grid">
    <div><strong>Managed by {{ $tenant->name }}</strong><p>A real team handles your enquiry and stays reachable during your stay.@if(!empty($guestInfo['reply_time'])) {{ $guestInfo['reply_time'] }}.@endif</p></div>
    <div><strong>Real photos, clear details</strong><p>See the photos, amenities, house rules and policies before you decide.</p></div>
    <div><strong>Pay the company, safely</strong><p>Payment details come on your official quote. Only pay to the account named on it.</p></div>
    <div><strong>Questions answered</strong><p>Check-in, deposits, cancellations and more. <a href="{{ route('public-stays.faq', $tenant->slug) }}">Read the FAQ &rarr;</a></p></div>
</div></section>
@endsection
