@php
    $photoCount = count($listing['photos']);
    $videoCount = count($listing['videos'] ?? []);
@endphp
<section class="stay-media" id="stay-photos" aria-label="Apartment photo gallery">
    @if($photoCount)
        <div class="stay-slider" data-photo-slider aria-roledescription="carousel" aria-label="{{ $listing['title'] }} photos">
            <div class="stay-slider-track" data-slider-track tabindex="0" aria-label="Photos. Swipe or use the arrow keys.">
                @foreach($listing['photos'] as $photo)
                    <a class="stay-slide" href="{{ $photo['url'] }}?w=1200" data-gallery-photo data-slide data-photo-index="{{ $loop->index }}" data-photo-caption="{{ $photo['caption'] }}" role="group" aria-roledescription="slide" aria-label="Photo {{ $loop->iteration }} of {{ $photoCount }}: {{ $photo['caption'] }}">
                        <img src="{{ $photo['url'] }}?w=1200" srcset="{{ \App\Services\ListingPhotoVariants::srcset($photo['url']) }}" sizes="(max-width: 1100px) 100vw, 1060px" alt="{{ $photo['caption'] }}" width="1200" height="800" @if($loop->first) fetchpriority="high" @else loading="lazy" @endif decoding="async">
                    </a>
                @endforeach
            </div>
            @if($photoCount > 1)
                <button type="button" class="stay-slider-arrow is-prev" data-slider-prev aria-label="Previous photo" hidden>&#8249;</button>
                <button type="button" class="stay-slider-arrow is-next" data-slider-next aria-label="Next photo" hidden>&#8250;</button>
                <span class="stay-slider-count" data-slider-count aria-live="polite">1 / {{ $photoCount }}</span>
            @endif
            <span class="stay-slider-caption" data-slider-caption>{{ $listing['photos'][0]['caption'] }}</span>
        </div>
        @if($photoCount > 1)
            <div class="stay-slider-thumbs" data-slider-thumbs aria-label="Choose a photo">
                @foreach($listing['photos'] as $photo)
                    <button type="button" data-slide-go="{{ $loop->index }}" aria-label="Show photo {{ $loop->iteration }}: {{ $photo['caption'] }}" @if($loop->first) aria-current="true" @endif><img src="{{ $photo['url'] }}?w=480" alt="" width="120" height="80" loading="lazy" decoding="async"></button>
                @endforeach
            </div>
        @endif
        <div class="stay-media-actions">
            <span>{{ $photoCount }} photo{{ $photoCount === 1 ? '' : 's' }}@if($videoCount) &middot; {{ $videoCount }} video{{ $videoCount === 1 ? '' : 's' }}@endif</span>
            <div><a class="stay-button secondary" href="#photo-grid" data-open-gallery>View all {{ $photoCount }} photo{{ $photoCount === 1 ? '' : 's' }}</a>@if($videoCount)<a class="stay-button secondary" href="#stay-videos">Watch the tour{{ $videoCount === 1 ? '' : 's' }} &darr;</a>@endif</div>
        </div>
        <details class="stay-photo-collection" id="all-photos">
            <summary>Browse every photo</summary>
            @php
                $indexedPhotos = collect($listing['photos'])->map(fn ($photo, $index) => $photo + ['index' => $index]);
                $roomGroups = collect(\App\Services\ListingDetails::ROOM_TAGS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'photos' => $indexedPhotos->where('room', $key)->values()])
                    ->push(['key' => 'untagged', 'label' => 'More photos', 'photos' => $indexedPhotos->whereNull('room')->values()])
                    ->filter(fn ($group) => $group['photos']->isNotEmpty())->values();
                $grouped = $roomGroups->count() > 1;
            @endphp
            @if($grouped)<nav class="stay-photo-rooms" aria-label="Photos by room">@foreach($roomGroups as $group)<a href="#photos-{{ $group['key'] }}">{{ $group['label'] }} <span>{{ $group['photos']->count() }}</span></a>@endforeach</nav>@endif
            <div class="stay-photo-grid" id="photo-grid">
                @foreach($roomGroups as $group)
                    @if($grouped)<h3 class="stay-photo-room-title" id="photos-{{ $group['key'] }}">{{ $group['label'] }}</h3>@endif
                    @foreach($group['photos'] as $photo)
                        <a href="{{ $photo['url'] }}?w=1200" data-gallery-photo data-photo-index="{{ $photo['index'] }}" data-photo-caption="{{ $photo['caption'] }}" aria-label="View photo {{ $photo['index'] + 1 }} of {{ $photoCount }}: {{ $photo['caption'] }}">
                            <img src="{{ $photo['url'] }}?w=480" alt="{{ $photo['caption'] }}" width="700" height="480" loading="lazy" decoding="async"><span>{{ $photo['caption'] }}</span>
                        </a>
                    @endforeach
                @endforeach
            </div>
        </details>
        <dialog class="stay-photo-dialog" data-photo-dialog aria-labelledby="gallery-title">
            <div class="stay-photo-toolbar"><h2 id="gallery-title">{{ $listing['title'] }} &middot; Photos</h2><button type="button" data-close-photo aria-label="Close photo gallery">Close &times;</button></div>
            <figure class="stay-photo-stage"><img data-current-photo alt=""><figcaption data-photo-caption></figcaption></figure>
            <div class="stay-photo-controls"><button type="button" data-photo-prev aria-label="Previous photo">&larr;</button><span data-photo-count role="status" aria-live="polite">1 / {{ $photoCount }}</span><button type="button" data-photo-next aria-label="Next photo">&rarr;</button></div>
            <div class="stay-photo-thumbnails" aria-label="Choose a photo">
                @foreach($listing['photos'] as $photo)
                    <button type="button" data-photo-go="{{ $loop->index }}" aria-label="Show photo {{ $loop->iteration }}: {{ $photo['caption'] }}"><img src="{{ $photo['url'] }}?w=480" alt="" width="96" height="72" loading="lazy" decoding="async"></button>
                @endforeach
            </div>
        </dialog>
    @else
        <div class="stay-gallery-missing">Upload apartment photos in the listing editor to complete this preview.</div>
    @endif
</section>
