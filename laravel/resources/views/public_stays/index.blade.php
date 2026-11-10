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
    @php
        $activeFilters = [];
        $removeFilter = fn (string ...$keys): string => request()->fullUrlWithQuery(array_fill_keys([...$keys, 'page'], null)).'#find-stay';
        if (!empty($filters['city'])) { $activeFilters[] = ['label' => $filters['city'], 'url' => $removeFilter('city', 'area')]; }
        if (!empty($filters['area'])) { $activeFilters[] = ['label' => $filters['area'], 'url' => $removeFilter('area')]; }
        if (!empty($filters['collection'])) { $activeFilters[] = ['label' => \App\Services\PublicListingCatalog::COLLECTIONS[$filters['collection']], 'url' => $removeFilter('collection')]; }
        if (!empty($filters['type'])) { $activeFilters[] = ['label' => \App\Services\PublicListingCatalog::PROPERTY_TYPES[$filters['type']], 'url' => $removeFilter('type')]; }
        if (isset($filters['bedrooms']) && $filters['bedrooms'] !== null) { $activeFilters[] = ['label' => (int) $filters['bedrooms'] === 0 ? 'Studio' : $filters['bedrooms'].' bedroom'.((int) $filters['bedrooms'] === 1 ? '' : 's'), 'url' => $removeFilter('bedrooms')]; }
        if (!empty($filters['q'])) { $activeFilters[] = ['label' => '“'.$filters['q'].'”', 'url' => $removeFilter('q')]; }
        if (isset($filters['min_price']) || isset($filters['max_price'])) { $activeFilters[] = ['label' => 'Price', 'url' => $removeFilter('min_price', 'max_price')]; }
        if (!empty($filters['amenities'])) { $activeFilters[] = ['label' => count($filters['amenities']).' '.(count($filters['amenities']) === 1 ? 'amenity' : 'amenities'), 'url' => $removeFilter('amenities')]; }
    @endphp
    <form class="stay-search" id="stay-search-form" action="{{ route('public-stays.index', $tenant->slug) }}#find-stay" method="get" aria-label="Find accommodation">
        <input type="hidden" name="collection" value="{{ $filters['collection'] ?? '' }}">
        <div class="stay-search-main">
            <div><label for="stay-city">Where would you like to stay?</label><select name="city" id="stay-city"><option value="">All destinations</option>@foreach($destinations as $destination)<option value="{{ $destination['city'] }}" @selected(($filters['city'] ?? '') === $destination['city'])>{{ $destination['city'] }} ({{ $destination['count'] }})</option>@endforeach</select></div>
            <div><label for="search-check-in">Check-in</label><input name="check_in" id="search-check-in" type="date" min="{{ $today }}" value="{{ $filters['check_in'] ?? '' }}"></div>
            <div><label for="search-check-out">Check-out</label><input name="check_out" id="search-check-out" type="date" min="{{ $today }}" value="{{ $filters['check_out'] ?? '' }}"></div>
            <div><label for="stay-guests">Guests</label><input name="guests" id="stay-guests" type="number" min="1" max="100" placeholder="2" value="{{ $filters['guests'] ?? '' }}"></div>
            <button class="stay-button" type="submit">Search stays</button>
        </div>
        @if($collectionCounts)
            <div class="stay-collections" aria-label="Browse collections">
                @foreach($collectionCounts as $collectionKey => $collection)
                    @php($isActiveCollection = ($filters['collection'] ?? null) === $collectionKey)
                    <a class="stay-chip{{ $isActiveCollection ? ' is-active' : '' }}" href="{{ request()->fullUrlWithQuery(['collection' => $isActiveCollection ? null : $collectionKey, 'page' => null]) }}#find-stay" @if($isActiveCollection) aria-current="true" @endif>{{ $collection['label'] }} <span>{{ $collection['count'] }}</span></a>
                @endforeach
            </div>
        @endif
        <details class="stay-filter-details" @if(!empty($filters['q']) || !empty($filters['area']) || !empty($filters['type']) || isset($filters['bedrooms']) || !empty($filters['amenities']) || isset($filters['min_price']) || isset($filters['max_price'])) open @endif><summary>More filters</summary>
            <div class="stay-filter-grid">
                @if(collect($destinations)->sum(fn ($destination) => count($destination['areas'])) > 0)
                    <div><label for="stay-area">Locality</label><select id="stay-area" name="area"><option value="">Any locality</option>@foreach($destinations as $destination)@if($destination['areas'])<optgroup label="{{ $destination['city'] }}">@foreach($destination['areas'] as $area)<option value="{{ $area['name'] }}" @selected(($filters['area'] ?? '') === $area['name'])>{{ $area['name'] }} ({{ $area['count'] }})</option>@endforeach</optgroup>@endif @endforeach</select></div>
                @endif
                @if(count($propertyTypes) > 1)
                    <div><label for="stay-type">Property type</label><select id="stay-type" name="type"><option value="">Any type</option>@foreach($propertyTypes as $typeKey => $typeLabel)<option value="{{ $typeKey }}" @selected(($filters['type'] ?? '') === $typeKey)>{{ $typeLabel }}</option>@endforeach</select></div>
                @endif
                <div><label for="stay-q">Apartment name, area or listing code</label><input type="search" id="stay-q" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="e.g. Nyali or A19"></div>
                <div><label for="stay-bedrooms">Bedrooms</label><select id="stay-bedrooms" name="bedrooms"><option value="">Any size</option>@for($bedrooms=0;$bedrooms<=6;$bedrooms++)<option value="{{ $bedrooms }}" @selected(isset($filters['bedrooms']) && $filters['bedrooms'] !== null && (int)$filters['bedrooms'] === $bedrooms)>{{ $bedrooms === 0 ? 'Studio' : $bedrooms.' bedroom'.($bedrooms === 1 ? '' : 's') }}</option>@endfor</select></div>
                <div><label for="min-price">Minimum starting price ({{ $tenant->currency }})</label><input type="number" min="0" step="1" id="min-price" name="min_price" value="{{ $filters['min_price'] ?? '' }}"></div>
                <div><label for="max-price">Maximum starting price ({{ $tenant->currency }})</label><input type="number" min="0" step="1" id="max-price" name="max_price" value="{{ $filters['max_price'] ?? '' }}"></div>
            </div>
            <div class="stay-amenity-filters">@foreach($amenities as $key=>$label)<label class="stay-check"><input type="checkbox" name="amenities[]" value="{{ $key }}" @checked(in_array($key, $filters['amenities'] ?? [], true))><span>{{ $label }}</span></label>@endforeach</div>
        </details>
        <div class="stay-search-foot"><input type="hidden" name="available_only" value="0"><label class="stay-check"><input type="checkbox" name="available_only" value="1" @checked(!isset($filters['available_only']) || $filters['available_only'])><span>When dates are selected, show only stays with up-to-date availability.</span></label><a href="{{ route('public-stays.index', $tenant->slug) }}#find-stay" class="stay-help">Clear all filters</a></div>
        @if($activeFilters)
            <div class="stay-active-filters" aria-label="Active filters"><span>Showing:</span>@foreach($activeFilters as $activeFilter)<a class="stay-chip is-active" href="{{ $activeFilter['url'] }}">{{ $activeFilter['label'] }} <span aria-hidden="true">&times;</span><span class="stay-visually-hidden">Remove filter</span></a>@endforeach</div>
        @endif
    </form>
    <section class="stay-section" aria-labelledby="stays-heading"><div class="stay-results-head"><div><span class="stay-eyebrow">Somewhere you'll love</span><h2 id="stays-heading">Find your next stay</h2><p>{{ number_format($stays->total()) }} {{ $stays->total() === 1 ? 'stay' : 'stays' }} {{ !empty($filters['check_in']) ? 'matching your dates and preferences' : 'to explore' }} &middot; Prices shown are starting nightly rates.</p></div><div><label for="stay-sort">Sort stays</label><select id="stay-sort" name="sort" form="stay-search-form" data-sort-stays><option value="recommended" @selected(($filters['sort'] ?? 'recommended') === 'recommended')>Recommended</option><option value="price_asc" @selected(($filters['sort'] ?? '') === 'price_asc')>Lowest starting price</option><option value="price_desc" @selected(($filters['sort'] ?? '') === 'price_desc')>Highest starting price</option></select></div></div>
        @if($stays->count())
            <div class="stay-grid">@foreach($stays as $stay)@include('public_stays.card')@endforeach</div>{{ $stays->links('public_stays.pagination') }}
        @else
            <div class="stay-empty"><h2>Let's find the right stay for you.</h2><p>No stays match these preferences right now. Try removing a filter, choosing flexible dates or another destination, or ask our team: we often know of options that are not listed yet.</p><div class="stay-track-actions" style="justify-content:center"><a class="stay-button secondary" href="{{ route('public-stays.index', $tenant->slug) }}#find-stay">Show all stays</a>@if(!empty($websiteContactLinks['whatsapp_url']))<a class="stay-button" href="{{ $websiteContactLinks['whatsapp_url'] }}" target="_blank" rel="noopener noreferrer">Ask us on WhatsApp <span aria-hidden="true">&nearr;</span></a>@endif</div></div>
        @endif
    </section>
</div>
<section class="stay-why"><div class="stay-container stay-why-grid"><div><strong>A stay that suits you</strong><p>Explore the details, amenities and house rules before deciding.</p></div><div><strong>A real team to help</strong><p>Enquire directly with our team about your apartment and dates.</p></div><div><strong>A thoughtful welcome</strong><p>We'll confirm availability and your agreed price before your booking is final.</p></div></div></section>
@endsection
