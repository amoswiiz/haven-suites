@if($listing['public_location'] || !empty($listing['area_map']) || !empty($listing['nearby']))
    <section class="stay-detail-section" id="stay-location" aria-labelledby="stay-location-heading">
        <h2 id="stay-location-heading">Your neighbourhood</h2>
        @if($listing['public_location'])<p class="stay-muted">{{ $listing['public_location'] }}</p>@endif
        @if(!empty($listing['area_map']) && empty($preview))
            <div class="stay-map">
                <iframe src="{{ $listing['area_map']['embed_url'] }}" title="Map of the area around {{ $listing['title'] }}" loading="lazy" referrerpolicy="no-referrer"></iframe>
            </div>
            <p class="stay-map-note">The map shows the general area. We share the exact address and directions once your booking is confirmed. <a href="{{ $listing['area_map']['link_url'] }}" target="_blank" rel="noopener noreferrer">Open larger map &nearr;</a></p>
        @elseif($listing['public_location'])
            <a href="https://www.google.com/maps/search/?api=1&amp;query={{ rawurlencode($listing['public_location'].' '.$listing['city']) }}" target="_blank" rel="noopener noreferrer">Explore the area on a map &nearr;</a>
        @endif
        @if(!empty($listing['nearby']))
            <h3 class="stay-nearby-heading">Nearby</h3>
            <ul class="stay-nearby">
                @foreach($listing['nearby'] as $place)
                    <li><span>{{ $place['name'] }}</span><strong>{{ $place['minutes'] }} min {{ $place['mode'] === 'walk' ? 'walk' : 'drive' }}</strong></li>
                @endforeach
            </ul>
        @endif
    </section>
@endif
