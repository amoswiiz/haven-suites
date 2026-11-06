@php
    $stayUrl = route('public-stays.show', [$tenant->slug, $stay['slug'], ...array_intersect_key($filters ?? [], array_flip(['check_in', 'check_out', 'guests']))]);
@endphp
<article class="stay-card">
    <a class="stay-card-image" href="{{ $stayUrl }}"><img src="{{ $stay['photos'][0]['url'] }}" alt="{{ $stay['photos'][0]['caption'] }}" loading="lazy" width="700" height="480"></a>
    <div class="stay-card-body">
        <p class="stay-location">{{ $stay['neighbourhood'] ? $stay['neighbourhood'].' · ' : '' }}{{ $stay['city'] }}</p>
        <h3><a href="{{ $stayUrl }}">{{ $stay['title'] }}</a></h3>
        @if(!empty($stay['availability']))
            <span class="stay-pill {{ !$stay['availability']['available'] ? 'unavailable' : (!$stay['availability']['fresh'] ? 'attention' : '') }}">{{ !$stay['availability']['available'] ? 'Unavailable for these dates' : (!$stay['availability']['fresh'] ? 'Ask us to confirm dates' : 'Dates currently available') }}</span>
        @endif
        <div class="stay-facts"><span>{{ $stay['bedrooms'] === 0 ? 'Studio' : $stay['bedrooms'].' bedroom'.($stay['bedrooms'] === 1 ? '' : 's') }}</span><span>{{ $stay['bathrooms'] }} bathroom{{ $stay['bathrooms'] === 1 ? '' : 's' }}</span><span>Up to {{ $stay['max_guests'] }} guests</span></div>
        <div class="stay-card-bottom"><div class="stay-price">
            @if((float)$stay['starting_price'] > 0)<small>From</small><strong>{{ $stay['currency'] }} {{ number_format((float)$stay['starting_price'], (float)$stay['starting_price'] == floor((float)$stay['starting_price']) ? 0 : 2) }}</strong><small>per night</small>@else<strong>Price on request</strong>@endif
        </div><a href="{{ $stayUrl }}">Explore stay &rarr;</a></div>
    </div>
</article>
