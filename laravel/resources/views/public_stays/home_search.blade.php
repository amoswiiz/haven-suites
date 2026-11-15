{{--
    New homepage search (Website settings → Homepage layout, step 1). It submits exactly the same fields to the same
    address as home_classic_search, so links shared by agents, bookmarks and search engines keep working.
--}}
@php
    $drawerCount = (int) !empty($filters['q']) + (int) !empty($filters['area']) + (int) !empty($filters['type'])
        + (int) (isset($filters['bedrooms']) && $filters['bedrooms'] !== null) + (int) (isset($filters['min_price']) || isset($filters['max_price']))
        + count($filters['amenities'] ?? []);
    $collectionIcons = [
        'beachfront' => '<path d="M2 17c2 0 2-1.5 4-1.5S8 17 10 17s2-1.5 4-1.5 2 1.5 4 1.5 2-1.5 4-1.5M2 21c2 0 2-1.5 4-1.5S8 21 10 21s2-1.5 4-1.5 2 1.5 4 1.5 2-1.5 4-1.5M12 3v9m0-9c3 0 6 2 7 5H5c1-3 4-5 7-5z"/>',
        'sea_view' => '<path d="M2 18c2 0 2-1.5 4-1.5S8 18 10 18s2-1.5 4-1.5 2 1.5 4 1.5 2-1.5 4-1.5M7 12a5 5 0 0 1 10 0M12 3v2M4.9 6.9l1.4 1.4M19.1 6.9l-1.4 1.4"/>',
        'guest_favourite' => '<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/>',
        'special_offer' => '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8zM7.5 7.5h.01"/>',
        'long_stay' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/>',
        'business' => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2M3 13h18"/>',
    ];
    $topAmenities = array_slice(\App\Services\PublicListingCatalog::AMENITIES, 0, 8, true);
    $moreAmenities = array_slice(\App\Services\PublicListingCatalog::AMENITIES, 8, null, true);
    $collectionUrl = fn (?string $key): string => request()->fullUrlWithQuery(['collection' => $key, 'page' => null]).'#find-stay';
@endphp
<div class="stay-home-band{{ !empty($hero['photos'][0]) ? ' has-photo' : '' }}">
    @if(!empty($hero['photos'][0]))
        <img class="stay-home-band-photo" src="{{ $hero['photos'][0]['url'] }}?w=1200" srcset="{{ \App\Services\ListingPhotoVariants::srcset($hero['photos'][0]['url']) }}" sizes="100vw" alt="" width="1200" height="500" fetchpriority="high" decoding="async">
    @endif
    <div class="stay-home-band-copy">
        <h1>{{ $site->headline ?: 'Find a place that feels like yours.' }}</h1>
        <p>{{ $site->tagline ?: 'Comfortable apartments, thoughtful hosting.' }}</p>
        @include('public_stays.google_rating')
    </div>
</div>
<form class="stay-search stay-search-v2" id="stay-search-form" action="{{ route('public-stays.index', $tenant->slug) }}#find-stay" method="get" aria-label="Find accommodation">
    <input type="hidden" name="collection" value="{{ $filters['collection'] ?? '' }}">
    @if(in_array(request()->query('layout'), ['new', 'classic'], true))<input type="hidden" name="layout" value="{{ request()->query('layout') }}">@endif
    <div class="stay-pill" role="group" aria-label="Where, when and who">
        <label class="stay-pill-field"><span>Where</span><select name="city" id="stay-city"><option value="">Anywhere</option>@foreach($destinations as $destination)<option value="{{ $destination['city'] }}" @selected(($filters['city'] ?? '') === $destination['city'])>{{ $destination['city'] }} ({{ $destination['count'] }})</option>@endforeach</select></label>
        <label class="stay-pill-field"><span>Check-in</span><input name="check_in" id="search-check-in" type="date" min="{{ $today }}" value="{{ $filters['check_in'] ?? '' }}"></label>
        <label class="stay-pill-field"><span>Check-out</span><input name="check_out" id="search-check-out" type="date" min="{{ $today }}" value="{{ $filters['check_out'] ?? '' }}"></label>
        <label class="stay-pill-field"><span>Guests</span><input name="guests" id="stay-guests" type="number" min="1" max="100" placeholder="Add guests" value="{{ $filters['guests'] ?? '' }}"></label>
        <button class="stay-pill-submit" type="submit"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><span>Search</span></button>
    </div>

    <div class="stay-toolbar">
        <nav class="stay-categories" aria-label="Browse collections">
            <a class="stay-category{{ empty($filters['collection']) ? ' is-active' : '' }}" href="{{ $collectionUrl(null) }}" @if(empty($filters['collection'])) aria-current="true" @endif><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z"/></svg><span>All stays</span></a>
            @foreach($collectionCounts as $collectionKey => $collection)
                @php($isActiveCollection = ($filters['collection'] ?? null) === $collectionKey)
                <a class="stay-category{{ $isActiveCollection ? ' is-active' : '' }}" href="{{ $collectionUrl($isActiveCollection ? null : $collectionKey) }}" @if($isActiveCollection) aria-current="true" @endif><svg viewBox="0 0 24 24" aria-hidden="true">{!! $collectionIcons[$collectionKey] ?? $collectionIcons['guest_favourite'] !!}</svg><span>{{ $collection['label'] }} <small>{{ $collection['count'] }}</small></span></a>
            @endforeach
        </nav>
        <div class="stay-toolbar-actions">
            <details class="stay-drawer" data-filter-drawer>
                <summary class="stay-filter-button"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4"/></svg> Filters @if($drawerCount)<span class="stay-filter-count">{{ $drawerCount }}</span>@endif</summary>
                <div class="stay-drawer-panel" role="dialog" aria-modal="true" aria-labelledby="filters-title">
                    <div class="stay-drawer-head"><h2 id="filters-title">Filters</h2><button type="button" class="stay-drawer-close" data-close-drawer aria-label="Close filters">&times;</button></div>
                    <div class="stay-drawer-body">
                        <div class="stay-field"><label for="stay-q">Keyword or listing code</label><input type="search" id="stay-q" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="e.g. Nyali, pool or a code like F5" autocomplete="off"></div>
                        @if(collect($destinations)->sum(fn ($destination) => count($destination['areas'])) > 0)
                            <div class="stay-field"><label for="stay-area">Locality</label><select id="stay-area" name="area"><option value="">Any locality</option>@foreach($destinations as $destination)@if($destination['areas'])<optgroup label="{{ $destination['city'] }}">@foreach($destination['areas'] as $area)<option value="{{ $area['name'] }}" @selected(($filters['area'] ?? '') === $area['name'])>{{ $area['name'] }} ({{ $area['count'] }})</option>@endforeach</optgroup>@endif @endforeach</select></div>
                        @endif
                        <fieldset class="stay-drawer-group"><legend>Bedrooms</legend><div class="stay-segments">
                            <label><input type="radio" name="bedrooms" value="" @checked(!isset($filters['bedrooms']) || $filters['bedrooms'] === null)><span>Any</span></label>
                            @for($bedroomOption = 0; $bedroomOption <= 6; $bedroomOption++)<label><input type="radio" name="bedrooms" value="{{ $bedroomOption }}" @checked(isset($filters['bedrooms']) && $filters['bedrooms'] !== null && (int) $filters['bedrooms'] === $bedroomOption)><span>{{ $bedroomOption === 0 ? 'Studio' : ($bedroomOption === 6 ? '6+' : $bedroomOption) }}</span></label>@endfor
                        </div></fieldset>
                        @if(count($propertyTypes) > 1)
                            <div class="stay-field"><label for="stay-type">Property type</label><select id="stay-type" name="type"><option value="">Any type</option>@foreach($propertyTypes as $typeKey => $typeLabel)<option value="{{ $typeKey }}" @selected(($filters['type'] ?? '') === $typeKey)>{{ $typeLabel }}</option>@endforeach</select></div>
                        @endif
                        <fieldset class="stay-drawer-group"><legend>Nightly price ({{ $tenant->currency }})</legend><div class="stay-price-range">
                            <label><span>Minimum</span><input type="number" min="0" step="1" id="min-price" name="min_price" value="{{ $filters['min_price'] ?? '' }}" inputmode="numeric"></label>
                            <label><span>Maximum</span><input type="number" min="0" step="1" id="max-price" name="max_price" value="{{ $filters['max_price'] ?? '' }}" inputmode="numeric"></label>
                        </div></fieldset>
                        <fieldset class="stay-drawer-group"><legend>Amenities</legend>
                            <div class="stay-amenity-filters">@foreach($topAmenities as $key => $label)<label class="stay-check"><input type="checkbox" name="amenities[]" value="{{ $key }}" @checked(in_array($key, $filters['amenities'] ?? [], true))><span>{{ $label }}</span></label>@endforeach</div>
                            <details class="stay-more-amenities" @if(array_intersect(array_keys($moreAmenities), $filters['amenities'] ?? [])) open @endif><summary>Show {{ count($moreAmenities) }} more</summary>
                                <div class="stay-amenity-filters">@foreach($moreAmenities as $key => $label)<label class="stay-check"><input type="checkbox" name="amenities[]" value="{{ $key }}" @checked(in_array($key, $filters['amenities'] ?? [], true))><span>{{ $label }}</span></label>@endforeach</div>
                            </details>
                        </fieldset>
                        <input type="hidden" name="available_only" value="0">
                        <label class="stay-check"><input type="checkbox" name="available_only" value="1" @checked(!isset($filters['available_only']) || $filters['available_only'])><span>When dates are chosen, show only stays with up-to-date availability</span></label>
                    </div>
                    <div class="stay-drawer-foot"><a href="{{ route('public-stays.index', [$tenant->slug, ...(in_array(request()->query('layout'), ['new', 'classic'], true) ? ['layout' => request()->query('layout')] : [])]) }}#find-stay">Clear all</a><button class="stay-button" type="submit">Show stays</button></div>
                </div>
            </details>
            @if(!empty($layout['explore']))
                <button type="button" class="stay-filter-button" data-map-toggle aria-pressed="false" hidden><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m9 4-6 2v14l6-2 6 2 6-2V4l-6 2-6-2zM9 4v14M15 6v14"/></svg> <span data-map-label>Map</span></button>
                <a class="stay-filter-button" href="{{ route('public-stays.saved', $tenant->slug) }}" data-saved-link><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 21s-7.5-4.6-9.5-9.2C1.2 8.6 3.4 5 7 5c2 0 3.4 1.1 5 3 1.6-1.9 3-3 5-3 3.6 0 5.8 3.6 4.5 6.8C19.5 16.4 12 21 12 21z"/></svg> Saved <span class="stay-filter-count" data-saved-count hidden></span></a>
            @endif
        </div>
    </div>
    @if($activeFilters)
        <div class="stay-active-filters" aria-label="Active filters"><span>Showing:</span>@foreach($activeFilters as $activeFilter)<a class="stay-chip is-active" href="{{ $activeFilter['url'] }}">{{ $activeFilter['label'] }} <span aria-hidden="true">&times;</span><span class="stay-visually-hidden">Remove filter</span></a>@endforeach</div>
    @endif
</form>
