@extends('public_stays.layout')
@section('content')
<section class="stay-hero"><div class="stay-container stay-hero-grid"><div class="stay-hero-copy"><span class="stay-eyebrow">Thoughtful stays. Warm welcomes.</span><h1>{{ $site->headline ?: 'Find a place that feels like yours.' }}</h1><p>{{ $site->tagline ?: 'Explore comfortable apartments, find dates that work for you, and let our team help you plan a memorable stay.' }}</p><div class="stay-hero-actions"><a class="stay-button" href="#find-stay">Find your stay <span aria-hidden="true">&darr;</span></a>@if(!empty($websiteContactLinks['whatsapp_url']))<a class="stay-hero-contact" href="{{ $websiteContactLinks['whatsapp_url'] }}" target="_blank" rel="noopener noreferrer">Talk to our team on WhatsApp <span aria-hidden="true">&nearr;</span></a>@endif</div><p class="stay-hero-note">Browse the photos. Explore your dates. Enquire directly with our team.</p></div><div class="stay-hero-image">
    @if(!empty($hero['photos'][0]))
        <img src="{{ $hero['photos'][0]['url'] }}" alt="{{ $hero['photos'][0]['caption'] }}" width="900" height="700" fetchpriority="high">
    @else
        <div class="stay-hero-empty">A little space<br>to feel at home.</div>
    @endif
</div></div></section>
<div class="stay-container" id="find-stay">
    <form class="stay-search" id="stay-search-form" action="{{ route('public-stays.index', $tenant->slug) }}" method="get" aria-label="Find accommodation">
        <div class="stay-search-main">
            <div><label for="stay-city">Where would you like to stay?</label><select name="city" id="stay-city"><option value="">Explore all destinations</option>@foreach($cities as $city)<option value="{{ $city }}" @selected(($filters['city'] ?? '') === $city)>{{ $city }}</option>@endforeach</select></div>
            <div><label for="search-check-in">Check-in</label><input name="check_in" id="search-check-in" type="date" min="{{ $today }}" value="{{ $filters['check_in'] ?? '' }}"></div>
            <div><label for="search-check-out">Check-out</label><input name="check_out" id="search-check-out" type="date" min="{{ $today }}" value="{{ $filters['check_out'] ?? '' }}"></div>
            <div><label for="stay-guests">Guests</label><input name="guests" id="stay-guests" type="number" min="1" max="100" placeholder="2" value="{{ $filters['guests'] ?? '' }}"></div>
            <button class="stay-button" type="submit">Find stays</button>
        </div>
        <details class="stay-filter-details" @if(!empty($filters['q']) || isset($filters['bedrooms']) || !empty($filters['amenities']) || isset($filters['min_price']) || isset($filters['max_price'])) open @endif><summary>More ways to find your stay</summary>
            <div class="stay-filter-grid"><div><label for="stay-q">Apartment or neighbourhood</label><input type="search" id="stay-q" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="e.g. Nyali"></div><div><label for="stay-bedrooms">Bedrooms</label><select id="stay-bedrooms" name="bedrooms"><option value="">Any size</option>@for($bedrooms=0;$bedrooms<=6;$bedrooms++)<option value="{{ $bedrooms }}" @selected(isset($filters['bedrooms']) && $filters['bedrooms'] !== null && (int)$filters['bedrooms'] === $bedrooms)>{{ $bedrooms === 0 ? 'Studio' : $bedrooms.' bedroom'.($bedrooms === 1 ? '' : 's') }}</option>@endfor</select></div><div><label for="min-price">Minimum starting price ({{ $tenant->currency }})</label><input type="number" min="0" step="1" id="min-price" name="min_price" value="{{ $filters['min_price'] ?? '' }}"></div><div><label for="max-price">Maximum starting price ({{ $tenant->currency }})</label><input type="number" min="0" step="1" id="max-price" name="max_price" value="{{ $filters['max_price'] ?? '' }}"></div></div>
            <div class="stay-amenity-filters">@foreach($amenities as $key=>$label)<label class="stay-check"><input type="checkbox" name="amenities[]" value="{{ $key }}" @checked(in_array($key, $filters['amenities'] ?? [], true))><span>{{ $label }}</span></label>@endforeach</div>
        </details>
        <div style="margin-top:18px;display:flex;gap:22px;justify-content:space-between;flex-wrap:wrap"><input type="hidden" name="available_only" value="0"><label class="stay-check"><input type="checkbox" name="available_only" value="1" @checked(!isset($filters['available_only']) || $filters['available_only'])><span>When dates are selected, show only stays with up-to-date availability.</span></label><a href="{{ route('public-stays.index', $tenant->slug) }}" class="stay-help">Clear filters</a></div>
    </form>
    <section class="stay-section" aria-labelledby="stays-heading"><div class="stay-results-head"><div><span class="stay-eyebrow">Somewhere you'll love</span><h2 id="stays-heading">Find your next stay</h2><p>{{ number_format($stays->total()) }} {{ $stays->total() === 1 ? 'stay' : 'stays' }} {{ !empty($filters['check_in']) ? 'matching your dates and preferences' : 'to explore' }} &middot; Prices shown are starting nightly rates.</p></div><div><label for="stay-sort">Sort stays</label><select id="stay-sort" name="sort" form="stay-search-form" data-sort-stays><option value="recommended" @selected(($filters['sort'] ?? 'recommended') === 'recommended')>Recommended</option><option value="price_asc" @selected(($filters['sort'] ?? '') === 'price_asc')>Lowest starting price</option><option value="price_desc" @selected(($filters['sort'] ?? '') === 'price_desc')>Highest starting price</option></select></div></div>
        @if($stays->count())
            <div class="stay-grid">@foreach($stays as $stay)@include('public_stays.card')@endforeach</div>{{ $stays->links('public_stays.pagination') }}
        @else
            <div class="stay-empty"><h2>Let's find the right stay for you.</h2><p>No published stays match these preferences right now. Try another destination or different dates, or contact our team for help.</p><a class="stay-button secondary" href="{{ route('public-stays.index', $tenant->slug) }}">Explore all stays</a></div>
        @endif
    </section>
</div>
<section class="stay-why"><div class="stay-container stay-why-grid"><div><strong>A stay that suits you</strong><p>Explore the details, amenities and house rules before deciding.</p></div><div><strong>A real team to help</strong><p>Enquire directly with our team about your apartment and dates.</p></div><div><strong>A thoughtful welcome</strong><p>We'll confirm availability and your agreed price before your booking is final.</p></div></div></section>
@endsection
