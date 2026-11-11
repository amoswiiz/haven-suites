{{-- Guest details for a listing: rooms, property facts, location and nearby places, prices by date, 360° tour, private check-in guide. --}}
@php
    $currency = app('currentTenant')->currency;
    $decode = fn (?string $json): array => is_string($json) ? (json_decode($json, true) ?: []) : [];
    $rooms = old('rooms', $decode($listing->rooms ?? null));
    $nearby = old('nearby', $decode($listing->nearby ?? null));
    $seasons = old('seasonal_rates', $decode($listing->seasonal_rates ?? null));
    $locationValue = old('location', ($listing->latitude ?? null) !== null ? rtrim(rtrim((string) $listing->latitude, '0'), '.').', '.rtrim(rtrim((string) $listing->longitude, '0'), '.') : '');
    $photoOptions = $photos->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values();
@endphp

<section class="card" aria-labelledby="rooms-heading"><h2 id="rooms-heading">Where guests sleep <small class="muted">Optional</small></h2>
    <p class="muted">Add each bedroom (and sofa beds in the living room) with its beds. Guests see a card for each room with its photo.</p>
    <div class="listing-rows">
        @for($roomIndex = 0; $roomIndex < min(\App\Services\ListingDetails::MAX_ROOMS, max(3, count($rooms) + 1)); $roomIndex++)
            @php($room = $rooms[$roomIndex] ?? [])
            <fieldset class="listing-row"><legend class="muted">Room {{ $roomIndex + 1 }}</legend><div class="grid">
                <div><label for="room-name-{{ $roomIndex }}">Room name</label><input id="room-name-{{ $roomIndex }}" name="rooms[{{ $roomIndex }}][name]" maxlength="40" value="{{ $room['name'] ?? '' }}" placeholder="e.g. Master bedroom"></div>
                <div><label for="room-beds-{{ $roomIndex }}">Beds</label><input id="room-beds-{{ $roomIndex }}" name="rooms[{{ $roomIndex }}][beds]" maxlength="80" value="{{ $room['beds'] ?? '' }}" placeholder="e.g. 1 king bed"></div>
                <div><label for="room-photo-{{ $roomIndex }}">Photo</label><select id="room-photo-{{ $roomIndex }}" name="rooms[{{ $roomIndex }}][photo_id]"><option value="">No photo</option>@foreach($photoOptions as $photoOption)<option value="{{ $photoOption->id }}" @selected((int) ($room['photo_id'] ?? 0) === (int) $photoOption->id)>Photo {{ $loop->iteration }}{{ $photoOption->caption ? ': '.\Illuminate\Support\Str::limit($photoOption->caption, 40) : '' }}</option>@endforeach</select>@if($photoOptions->isEmpty())<small class="muted">Save photos first to choose one.</small>@endif</div>
                <div class="listing-row-check"><input type="hidden" name="rooms[{{ $roomIndex }}][ensuite]" value="0"><label><input style="width:auto" type="checkbox" name="rooms[{{ $roomIndex }}][ensuite]" value="1" @checked(!empty($room['ensuite']))> Ensuite bathroom</label></div>
            </div></fieldset>
        @endfor
    </div>
    <small class="muted">Save to add more rows (up to {{ \App\Services\ListingDetails::MAX_ROOMS }}). Clear a room's name and beds to remove it.</small>
</section>

<section class="card" aria-labelledby="facts-heading"><h2 id="facts-heading">Property facts <small class="muted">Optional</small></h2>
    <p class="muted">Security, backup power, water and the lift are under Amenities above. These facts appear next to them.</p>
    <div class="grid">
        <div><label for="size_sqm">Size (m²)</label><input id="size_sqm" name="size_sqm" type="number" min="10" max="5000" value="{{ old('size_sqm', $listing->size_sqm ?? '') }}" placeholder="e.g. 95"></div>
        <div><label for="floor_level">Floor</label><input id="floor_level" name="floor_level" maxlength="30" value="{{ old('floor_level', $listing->floor_level ?? '') }}" placeholder="e.g. 5th floor or Ground floor"></div>
        <div><label for="parking_spaces">Parking spaces</label><input id="parking_spaces" name="parking_spaces" type="number" min="0" max="20" value="{{ old('parking_spaces', $listing->parking_spaces ?? '') }}"></div>
    </div>
</section>

<section class="card" aria-labelledby="map-heading"><h2 id="map-heading">Map and nearby places <small class="muted">Recommended</small></h2>
    <p class="muted">Guests see a map of the <strong>surrounding area only</strong> (about 2 km across), never the exact building. Share directions privately after booking.</p>
    <label for="location">Location</label><input id="location" name="location" maxlength="1000" value="{{ $locationValue }}" placeholder="Paste a Google Maps link, or coordinates such as -4.0435, 39.7123">
    <small class="muted">In Google Maps, find the building, then copy the link from the address bar or Share button.</small>
    <h3 style="margin-top:22px">Nearby places</h3>
    <p class="muted">Beaches, malls, hospitals, the airport or business areas, with the usual travel time.</p>
    <div class="listing-rows">
        @for($placeIndex = 0; $placeIndex < min(\App\Services\ListingDetails::MAX_NEARBY, max(4, count($nearby) + 1)); $placeIndex++)
            @php($place = $nearby[$placeIndex] ?? [])
            <div class="grid listing-row-inline">
                <div><label for="nearby-name-{{ $placeIndex }}">Place</label><input id="nearby-name-{{ $placeIndex }}" name="nearby[{{ $placeIndex }}][name]" maxlength="60" value="{{ $place['name'] ?? '' }}" placeholder="e.g. Nyali Beach"></div>
                <div><label for="nearby-minutes-{{ $placeIndex }}">Minutes</label><input id="nearby-minutes-{{ $placeIndex }}" name="nearby[{{ $placeIndex }}][minutes]" type="number" min="1" max="600" value="{{ $place['minutes'] ?? '' }}"></div>
                <div><label for="nearby-mode-{{ $placeIndex }}">By</label><select id="nearby-mode-{{ $placeIndex }}" name="nearby[{{ $placeIndex }}][mode]">@foreach(['drive' => 'Car', 'walk' => 'Walking'] as $modeKey => $modeLabel)<option value="{{ $modeKey }}" @selected(($place['mode'] ?? 'drive') === $modeKey)>{{ $modeLabel }}</option>@endforeach</select></div>
            </div>
        @endfor
    </div>
</section>

<section class="card" aria-labelledby="prices-heading"><h2 id="prices-heading">Weekend and seasonal prices <small class="muted">Optional</small></h2>
    <p class="muted">The guest's price estimate uses these for each night: a seasonal price first, then the weekend price on Friday and Saturday nights, then the starting price. Your quote still confirms the final price.</p>
    <div class="grid"><div><label for="weekend_price">Weekend nightly price ({{ $currency }})</label><input id="weekend_price" name="weekend_price" type="number" step="0.01" min="0" value="{{ old('weekend_price', $listing->weekend_price ?? '') }}"><small class="muted">Friday and Saturday nights.</small></div></div>
    <h3 style="margin-top:18px">Seasons</h3>
    <div class="listing-rows">
        @for($seasonIndex = 0; $seasonIndex < min(\App\Services\ListingDetails::MAX_SEASONS, max(2, count($seasons) + 1)); $seasonIndex++)
            @php($season = $seasons[$seasonIndex] ?? [])
            <div class="grid listing-row-inline">
                <div><label for="season-name-{{ $seasonIndex }}">Name</label><input id="season-name-{{ $seasonIndex }}" name="seasonal_rates[{{ $seasonIndex }}][name]" maxlength="40" value="{{ $season['name'] ?? '' }}" placeholder="e.g. December holidays"></div>
                <div><label for="season-from-{{ $seasonIndex }}">From</label><input id="season-from-{{ $seasonIndex }}" name="seasonal_rates[{{ $seasonIndex }}][from]" type="date" value="{{ $season['from'] ?? '' }}"></div>
                <div><label for="season-to-{{ $seasonIndex }}">To (last night)</label><input id="season-to-{{ $seasonIndex }}" name="seasonal_rates[{{ $seasonIndex }}][to]" type="date" value="{{ $season['to'] ?? '' }}"></div>
                <div><label for="season-price-{{ $seasonIndex }}">Nightly price ({{ $currency }})</label><input id="season-price-{{ $seasonIndex }}" name="seasonal_rates[{{ $seasonIndex }}][price]" type="number" step="0.01" min="1" value="{{ $season['price'] ?? '' }}"></div>
            </div>
        @endfor
    </div>
    <small class="muted">Past seasons are hidden from guests automatically.</small>
</section>

<section class="card" aria-labelledby="tour-heading"><h2 id="tour-heading">360° virtual tour <small class="muted">Optional</small></h2>
    <p class="muted">Paste a Kuula or Matterport share link. Guests load the tour only when they choose to.</p>
    <label for="virtual_tour_url">Tour link</label><input id="virtual_tour_url" name="virtual_tour_url" type="url" maxlength="500" value="{{ old('virtual_tour_url', $listing->virtual_tour_url ?? '') }}" placeholder="https://kuula.co/share/..." inputmode="url">
</section>

<section class="card" aria-labelledby="guide-heading"><h2 id="guide-heading">Check-in guide <small class="muted">Private</small></h2>
    <p class="muted">Directions, gate or door access, Wi-Fi name and password, parking and house tips. <strong>Never shown on the website.</strong> Staff can send it to a confirmed guest from the enquiry page.</p>
    <label for="check_in_guide">Guide</label><textarea id="check_in_guide" name="check_in_guide" rows="7" maxlength="5000" placeholder="Directions from the main road, gate code, Wi-Fi name and password, parking spot, who to call on arrival...">{{ old('check_in_guide', $listing->check_in_guide ?? '') }}</textarea>
</section>
