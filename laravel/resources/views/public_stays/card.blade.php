@php
    $stayUrl = route('public-stays.show', [$tenant->slug, $stay['slug'], ...array_intersect_key($filters ?? [], array_flip(['check_in', 'check_out', 'guests']))]);
    $bedroomLabel = $stay['bedrooms'] === null ? 'Bedroom details on request' : ($stay['bedrooms'] === 0 ? 'Studio' : $stay['bedrooms'].' bedroom'.($stay['bedrooms'] === 1 ? '' : 's'));
    $bathroomLabel = $stay['bathrooms'] === null ? 'Bathroom details on request' : $stay['bathrooms'].' bathroom'.($stay['bathrooms'] === 1 ? '' : 's');
    $quickLinks = $stay['links'] ?? ['enquiry_url' => $stayUrl.'#enquire', 'whatsapp_url' => null];
@endphp
<article class="stay-card">
    <a class="stay-card-image" href="{{ $stayUrl }}"><img src="{{ $stay['photos'][0]['url'] }}" alt="{{ $stay['photos'][0]['caption'] }}" loading="lazy" width="700" height="480" decoding="async"><span class="stay-card-media">{{ count($stay['photos']) }} photo{{ count($stay['photos']) === 1 ? '' : 's' }}@if(!empty($stay['videos'])) <span aria-hidden="true">&middot;</span> Video tour{{ count($stay['videos']) === 1 ? '' : 's' }}@endif</span></a>
    <div class="stay-card-body">
        <p class="stay-location">{{ $stay['neighbourhood'] ? $stay['neighbourhood'].' · ' : '' }}{{ $stay['city'] }}</p>
        <h3><a href="{{ $stayUrl }}">{{ $stay['title'] }}</a></h3>
        @if(!empty($stay['collection_labels']) || !empty($stay['listing_code']))<p class="stay-card-tags">@foreach(array_slice($stay['collection_labels'] ?? [], 0, 2) as $collectionLabel)<span class="stay-tag">{{ $collectionLabel }}</span>@endforeach @if(!empty($stay['listing_code']))<span class="stay-code">Code {{ $stay['listing_code'] }}</span>@endif</p>@endif
        @if(!empty($stay['availability']))
            <span class="stay-pill {{ !$stay['availability']['available'] ? 'unavailable' : (!$stay['availability']['fresh'] ? 'attention' : '') }}">{{ !$stay['availability']['available'] ? 'Unavailable for these dates' : (!$stay['availability']['fresh'] ? 'Ask us to confirm dates' : 'Dates currently available') }}</span>
        @endif
        <div class="stay-facts"><span>{{ $bedroomLabel }}</span><span>{{ $bathroomLabel }}</span><span>Up to {{ $stay['max_guests'] }} guests</span></div>
        <div class="stay-card-bottom"><div class="stay-price">
            @if((float)$stay['starting_price'] > 0)<small>From</small><strong>{{ $stay['currency'] }} {{ number_format((float)$stay['starting_price'], (float)$stay['starting_price'] == floor((float)$stay['starting_price']) ? 0 : 2) }}</strong><small>per night</small>@else<strong>Price on request</strong>@endif
        </div><a href="{{ $stayUrl }}">View details &rarr;</a></div>
        <div class="stay-card-actions">
            <a class="stay-button" href="{{ $quickLinks['enquiry_url'] ?? $stayUrl.'#enquire' }}" aria-label="Enquire about {{ $stay['title'] }}">Enquire</a>
            @if(!empty($quickLinks['whatsapp_url']))<a class="stay-button secondary stay-whatsapp-button" href="{{ $quickLinks['whatsapp_url'] }}" target="_blank" rel="noopener noreferrer" aria-label="Ask our team about {{ $stay['title'] }} on WhatsApp">WhatsApp <span aria-hidden="true">&nearr;</span></a>@endif
        </div>
    </div>
</article>
