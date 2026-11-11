@php
    $stayUrl = route('public-stays.show', [$tenant->slug, $stay['slug'], ...array_intersect_key($filters ?? [], array_flip(['check_in', 'check_out', 'guests']))]);
    $bedroomLabel = $stay['layout_label'] ?? \App\Services\PublicListingCatalog::layoutLabel($stay['bedrooms'], $stay['property_type'] ?? null) ?? 'Bedroom details on request';
    $bedLabel = !empty($stay['beds']) ? $stay['beds'].' bed'.($stay['beds'] === 1 ? '' : 's') : null;
    $bathroomLabel = $stay['bathrooms'] === null ? 'Bathroom details on request' : $stay['bathrooms'].' bathroom'.($stay['bathrooms'] === 1 ? '' : 's');
    $quickLinks = $stay['links'] ?? ['enquiry_url' => $stayUrl.'#enquire', 'whatsapp_url' => null];
@endphp
<article class="stay-card">
    @php($cardPhotos = array_slice($stay['photos'], 0, 5))
    <div class="stay-card-image" data-card-slider>
        <div class="stay-card-track" data-card-track>
            @foreach($cardPhotos as $cardPhoto)
                <a class="stay-card-slide" href="{{ $stayUrl }}" @if(!$loop->first) tabindex="-1" aria-hidden="true" @endif><img src="{{ $cardPhoto['url'] }}?w=480" srcset="{{ \App\Services\ListingPhotoVariants::srcset($cardPhoto['url']) }}" sizes="(max-width: 640px) 100vw, (max-width: 1100px) 50vw, 380px" alt="{{ $loop->first ? $cardPhoto['caption'] : '' }}" loading="lazy" width="700" height="480" decoding="async"></a>
            @endforeach
        </div>
        @if(count($cardPhotos) > 1)
            <button type="button" class="stay-card-arrow is-prev" data-card-prev aria-label="Previous photo of {{ $stay['title'] }}" hidden>&#8249;</button>
            <button type="button" class="stay-card-arrow is-next" data-card-next aria-label="Next photo of {{ $stay['title'] }}" hidden>&#8250;</button>
            <span class="stay-card-dots" aria-hidden="true">@foreach($cardPhotos as $cardPhoto)<i @if($loop->first) class="is-active" @endif></i>@endforeach</span>
        @endif
        <span class="stay-card-media">{{ count($stay['photos']) }} photo{{ count($stay['photos']) === 1 ? '' : 's' }}@if(!empty($stay['videos'])) <span aria-hidden="true">&middot;</span> Video tour{{ count($stay['videos']) === 1 ? '' : 's' }}@endif</span>
    </div>
    <div class="stay-card-body">
        <p class="stay-location">{{ $stay['neighbourhood'] ? $stay['neighbourhood'].' · ' : '' }}{{ $stay['city'] }}</p>
        <h3><a href="{{ $stayUrl }}">{{ $stay['title'] }}</a></h3>
        @if(!empty($stay['rating']['count']))<p class="stay-card-rating">@include('public_stays.stars', ['rating' => $stay['rating']['average']]) <strong>{{ number_format($stay['rating']['average'], 1) }}</strong> <span class="stay-card-count">({{ $stay['rating']['count'] }})</span></p>@endif
        @if(!empty($stay['collection_labels']) || !empty($stay['listing_code']) || !empty($stay['weekly_discount']) || !empty($stay['monthly_discount']))<p class="stay-card-tags">@foreach(array_slice($stay['collection_labels'] ?? [], 0, 2) as $collectionLabel)<span class="stay-tag">{{ $collectionLabel }}</span>@endforeach @if(!empty($stay['monthly_discount']) || !empty($stay['weekly_discount']))<span class="stay-tag is-offer">{{ max((int) ($stay['weekly_discount'] ?? 0), (int) ($stay['monthly_discount'] ?? 0)) }}% off long stays</span>@endif @if(!empty($stay['listing_code']))<span class="stay-code">Code {{ $stay['listing_code'] }}</span>@endif</p>@endif
        @if(!empty($stay['availability']))
            <span class="stay-pill {{ !$stay['availability']['available'] ? 'unavailable' : (!$stay['availability']['fresh'] ? 'attention' : '') }}">{{ !$stay['availability']['available'] ? 'Unavailable for these dates' : (!$stay['availability']['fresh'] ? 'Ask us to confirm dates' : 'Dates currently available') }}</span>
        @endif
        <div class="stay-facts"><span>{{ $bedroomLabel }}</span>@if($bedLabel)<span>{{ $bedLabel }}</span>@endif<span>{{ $bathroomLabel }}</span><span>Up to {{ $stay['max_guests'] }} guests</span></div>
        <div class="stay-card-bottom"><div class="stay-price">
            @if((float)$stay['starting_price'] > 0)<small>From</small><strong>{{ $stay['currency'] }} {{ number_format((float)$stay['starting_price'], (float)$stay['starting_price'] == floor((float)$stay['starting_price']) ? 0 : 2) }}</strong><small>per night</small>@else<strong>Price on request</strong>@endif
        </div><a href="{{ $stayUrl }}">View details &rarr;</a></div>
        <div class="stay-card-actions">
            <a class="stay-button" href="{{ $quickLinks['enquiry_url'] ?? $stayUrl.'#enquire' }}" aria-label="Enquire about {{ $stay['title'] }}">Enquire</a>
            @if(!empty($quickLinks['whatsapp_url']))<a class="stay-button secondary stay-whatsapp-button" href="{{ $quickLinks['whatsapp_url'] }}" target="_blank" rel="noopener noreferrer" aria-label="Ask our team about {{ $stay['title'] }} on WhatsApp">WhatsApp <span aria-hidden="true">&nearr;</span></a>@endif
        </div>
    </div>
</article>
