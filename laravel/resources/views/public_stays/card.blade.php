@php
    // Links keep the chosen dates and guests, so the listing's enquiry form arrives filled in.
    $stayUrl = route('public-stays.show', [$tenant->slug, $stay['slug'], ...array_intersect_key($filters ?? [], array_flip(['check_in', 'check_out', 'guests']))]);
    $bedroomLabel = $stay['layout_label'] ?? \App\Services\PublicListingCatalog::layoutLabel($stay['bedrooms'], $stay['property_type'] ?? null) ?? 'Bedroom details on request';
    $bedLabel = !empty($stay['beds']) ? $stay['beds'].' bed'.($stay['beds'] === 1 ? '' : 's') : null;
    $bathroomLabel = $stay['bathrooms'] === null ? 'Bathroom details on request' : $stay['bathrooms'].' bathroom'.($stay['bathrooms'] === 1 ? '' : 's');
    $quickLinks = $stay['links'] ?? ['enquiry_url' => $stayUrl.'#enquire', 'whatsapp_url' => null];
    $cardLayout = ($layout ?? []) + ['cards' => false, 'explore' => false];
    // The collection a guest is browsing is shown first, so its tag is never cut off.
    $activeCollectionLabel = !empty($filters['collection']) ? (\App\Services\PublicListingCatalog::COLLECTIONS[$filters['collection']] ?? null) : null;
    $tagLabels = collect($stay['collection_labels'] ?? [])->sortBy(fn (string $label): int => $label === $activeCollectionLabel ? 0 : 1)->values()->all();
    $cardPhotos = array_slice($stay['photos'], 0, 5);
    $price = (float) $stay['starting_price'];
    $otaSaving = $price > 0 && (float) ($stay['ota_price'] ?? 0) > $price ? (float) $stay['ota_price'] - $price : 0;
    $otaSaving = $otaSaving >= (float) ($stay['ota_price'] ?? 0) * 0.03 ? $otaSaving : 0;
    $money = fn (float $amount): string => $stay['currency'].' '.number_format($amount, $amount == floor($amount) ? 0 : 2);
    $badge = in_array('Guest favourite', $tagLabels, true) ? 'Guest favourite' : ($tagLabels[0] ?? null);
@endphp
<article class="stay-card{{ $cardLayout['cards'] ? ' is-v2' : '' }}" data-stay-card data-slug="{{ $stay['slug'] }}"
    @if($cardLayout['explore'] && !empty($stay['area_point'])) data-map-lat="{{ $stay['area_point'][0] }}" data-map-lng="{{ $stay['area_point'][1] }}" data-map-title="{{ $stay['title'] }}" data-map-url="{{ $stayUrl }}" data-map-price="{{ $price > 0 ? $money($price) : '' }}" @endif>
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
        @if($cardLayout['cards'])
            @if($badge)<span class="stay-card-badge">{{ $badge }}</span>@endif
        @else
            <span class="stay-card-media">{{ count($stay['photos']) }} photo{{ count($stay['photos']) === 1 ? '' : 's' }}@if(!empty($stay['videos'])) <span aria-hidden="true">&middot;</span> Video tour{{ count($stay['videos']) === 1 ? '' : 's' }}@endif</span>
        @endif
        @if($cardLayout['explore'])
            <button type="button" class="stay-heart" data-save-stay="{{ $stay['slug'] }}" aria-pressed="false" aria-label="Save {{ $stay['title'] }}" hidden><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M12 21s-7.5-4.6-9.5-9.2C1.2 8.6 3.4 5 7 5c2 0 3.4 1.1 5 3 1.6-1.9 3-3 5-3 3.6 0 5.8 3.6 4.5 6.8C19.5 16.4 12 21 12 21z"/></svg></button>
        @endif
    </div>
    @if($cardLayout['cards'])
        <div class="stay-card-body">
            <div class="stay-card-line">
                <p class="stay-location">{{ $bedroomLabel }} · {{ $stay['neighbourhood'] ?: $stay['city'] }}{{ $stay['neighbourhood'] ? ', '.$stay['city'] : '' }}</p>
                @if(!empty($stay['rating']['count']))<p class="stay-card-score"><span aria-hidden="true">★</span> {{ number_format($stay['rating']['average'], 1) }} <span class="stay-card-count">({{ $stay['rating']['count'] }})</span></p>@endif
            </div>
            <h3><a class="stay-card-link" href="{{ $stayUrl }}">{{ $stay['title'] }}</a></h3>
            <p class="stay-card-facts">{{ implode(' · ', array_filter(['Up to '.$stay['max_guests'].' guests', $bedLabel, $stay['bathrooms'] !== null ? $stay['bathrooms'].' bath'.($stay['bathrooms'] === 1 ? '' : 's') : null])) }}</p>
            @if(!empty($stay['availability']))
                <span class="stay-availability {{ !$stay['availability']['available'] ? 'unavailable' : (!$stay['availability']['fresh'] ? 'attention' : '') }}">{{ !$stay['availability']['available'] ? 'Unavailable for these dates' : (!$stay['availability']['fresh'] ? 'Ask us to confirm dates' : 'Dates currently available') }}</span>
            @endif
            @if($otaSaving > 0)<p class="stay-card-saving">Save {{ $money($otaSaving) }} a night booking direct</p>@endif
            <div class="stay-card-foot">
                <p class="stay-card-price">
                    @if(!empty($stay['estimate']))
                        <strong>{{ $money((float) $stay['estimate']['total']) }}</strong> <span>est. total · {{ $stay['estimate']['nights'] }} night{{ $stay['estimate']['nights'] === 1 ? '' : 's' }}</span><small>We confirm your final price</small>
                    @elseif($price > 0)
                        <strong>{{ $money($price) }}</strong> <span>night</span>@if(!empty($stay['weekend_price']) || !empty($stay['seasonal_rates']))<small>Prices vary by date</small>@endif
                    @else
                        <strong>Price on request</strong>
                    @endif
                </p>
                <div class="stay-card-quick">
                    @if(!empty($quickLinks['whatsapp_url']))<a class="stay-card-wa" href="{{ $quickLinks['whatsapp_url'] }}" target="_blank" rel="noopener noreferrer" aria-label="Ask our team about {{ $stay['title'] }} on WhatsApp"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.3-.4.8-1.4.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2-.1-.1-.3-.2-.5-.3z"/></svg></a>@endif
                </div>
            </div>
        </div>
    @else
        <div class="stay-card-body">
            <p class="stay-location"><span>{{ $stay['neighbourhood'] ? $stay['neighbourhood'].' · ' : '' }}{{ $stay['city'] }}</span>@if(!empty($stay['listing_code']))<span class="stay-location-code">Code {{ $stay['listing_code'] }}</span>@endif</p>
            <h3><a href="{{ $stayUrl }}">{{ $stay['title'] }}</a></h3>
            @if(!empty($stay['rating']['count']))<p class="stay-card-rating">@include('public_stays.stars', ['rating' => $stay['rating']['average']]) <strong>{{ number_format($stay['rating']['average'], 1) }}</strong> <span class="stay-card-count">({{ $stay['rating']['count'] }})</span></p>@endif
            @if(!empty($tagLabels) || !empty($stay['weekly_discount']) || !empty($stay['monthly_discount']))<p class="stay-card-tags">@foreach(array_slice($tagLabels, 0, 2) as $collectionLabel)<span class="stay-tag">{{ $collectionLabel }}</span>@endforeach @if(!empty($stay['monthly_discount']) || !empty($stay['weekly_discount']))<span class="stay-tag is-offer">{{ max((int) ($stay['weekly_discount'] ?? 0), (int) ($stay['monthly_discount'] ?? 0)) }}% off long stays</span>@endif</p>@endif
            @if(!empty($stay['availability']))
                <span class="stay-availability {{ !$stay['availability']['available'] ? 'unavailable' : (!$stay['availability']['fresh'] ? 'attention' : '') }}">{{ !$stay['availability']['available'] ? 'Unavailable for these dates' : (!$stay['availability']['fresh'] ? 'Ask us to confirm dates' : 'Dates currently available') }}</span>
            @endif
            @if($otaSaving > 0)<p class="stay-card-saving">Save {{ $money($otaSaving) }} a night booking direct</p>@endif
            <div class="stay-facts"><span>{{ $bedroomLabel }}</span>@if($bedLabel)<span>{{ $bedLabel }}</span>@endif<span>{{ $bathroomLabel }}</span><span>Up to {{ $stay['max_guests'] }} guests</span></div>
            <div class="stay-card-bottom"><div class="stay-price">
                @if($price > 0)<small>From</small> <strong>{{ $money($price) }}</strong> <small>/ night</small>@else<strong>Price on request</strong>@endif
            </div><a href="{{ $stayUrl }}">View details &rarr;</a></div>
            <div class="stay-card-actions">
                <a class="stay-button" href="{{ $quickLinks['enquiry_url'] ?? $stayUrl.'#enquire' }}" aria-label="Enquire about {{ $stay['title'] }}">Enquire</a>
                @if(!empty($quickLinks['whatsapp_url']))<a class="stay-button secondary stay-whatsapp-button" href="{{ $quickLinks['whatsapp_url'] }}" target="_blank" rel="noopener noreferrer" aria-label="Ask our team about {{ $stay['title'] }} on WhatsApp">WhatsApp <span aria-hidden="true">&nearr;</span></a>@endif
            </div>
        </div>
    @endif
</article>
