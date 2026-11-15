{{-- Original search form (used when the new search layout is off). Field names match home_search. --}}
    <form class="stay-search" id="stay-search-form" action="{{ route('public-stays.index', $tenant->slug) }}#find-stay" method="get" aria-label="Find accommodation">
        <input type="hidden" name="collection" value="{{ $filters['collection'] ?? '' }}">
        <div class="stay-keyword"><label for="stay-q">Search stays</label><div class="stay-keyword-field"><input type="search" id="stay-q" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Try &quot;Nyali&quot;, &quot;pool&quot;, &quot;sea view&quot; or a listing code" autocomplete="off" enterkeyhint="search"><button class="stay-button" type="submit">Search</button></div></div>
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
        <details class="stay-filter-details" @if(!empty($filters['area']) || !empty($filters['type']) || isset($filters['bedrooms']) || !empty($filters['amenities']) || isset($filters['min_price']) || isset($filters['max_price'])) open @endif><summary>More filters</summary>
            <div class="stay-filter-grid">
                @if(collect($destinations)->sum(fn ($destination) => count($destination['areas'])) > 0)
                    <div><label for="stay-area">Locality</label><select id="stay-area" name="area"><option value="">Any locality</option>@foreach($destinations as $destination)@if($destination['areas'])<optgroup label="{{ $destination['city'] }}">@foreach($destination['areas'] as $area)<option value="{{ $area['name'] }}" @selected(($filters['area'] ?? '') === $area['name'])>{{ $area['name'] }} ({{ $area['count'] }})</option>@endforeach</optgroup>@endif @endforeach</select></div>
                @endif
                @if(count($propertyTypes) > 1)
                    <div><label for="stay-type">Property type</label><select id="stay-type" name="type"><option value="">Any type</option>@foreach($propertyTypes as $typeKey => $typeLabel)<option value="{{ $typeKey }}" @selected(($filters['type'] ?? '') === $typeKey)>{{ $typeLabel }}</option>@endforeach</select></div>
                @endif
                <div><label for="stay-bedrooms">Bedrooms</label><select id="stay-bedrooms" name="bedrooms"><option value="">Any size</option>@for($bedrooms=0;$bedrooms<=6;$bedrooms++)<option value="{{ $bedrooms }}" @selected(isset($filters['bedrooms']) && $filters['bedrooms'] !== null && (int)$filters['bedrooms'] === $bedrooms)>{{ $bedrooms === 0 ? 'Studio' : ($bedrooms === 6 ? '6+ bedrooms' : $bedrooms.' bedroom'.($bedrooms === 1 ? '' : 's')) }}</option>@endfor</select></div>
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
