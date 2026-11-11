@php($photoCount = count($listing['photos']))
@php($videoCount = count($listing['videos'] ?? []))
<section class="stay-media" id="stay-photos" aria-label="Apartment photo gallery">
    @if($photoCount)
        <div class="stay-gallery {{ $photoCount === 1 ? 'single' : ($photoCount === 2 ? 'two' : '') }}">
            @foreach(array_slice($listing['photos'], 0, 3) as $photo)
                <a href="{{ $photo['url'] }}?w=1200" data-gallery-photo data-photo-index="{{ $loop->index }}" data-photo-caption="{{ $photo['caption'] }}" aria-label="View photo {{ $loop->iteration }} of {{ $photoCount }}: {{ $photo['caption'] }}">
                    <img src="{{ $photo['url'] }}?w=1200" srcset="{{ \App\Services\ListingPhotoVariants::srcset($photo['url']) }}" sizes="(max-width: 760px) 100vw, 60vw" alt="{{ $photo['caption'] }}" width="900" height="650" @if($loop->first) fetchpriority="high" @else loading="lazy" @endif decoding="async">
                </a>
            @endforeach
        </div>
        <div class="stay-media-actions">
            <span>{{ $photoCount }} photo{{ $photoCount === 1 ? '' : 's' }}@if($videoCount) &middot; {{ $videoCount }} video{{ $videoCount === 1 ? '' : 's' }}@endif</span>
            <div><a class="stay-button secondary" href="#photo-grid" data-open-gallery>View all {{ $photoCount }} photo{{ $photoCount === 1 ? '' : 's' }}</a>@if($videoCount)<a class="stay-button secondary" href="#stay-videos">Watch the tour{{ $videoCount === 1 ? '' : 's' }} &darr;</a>@endif</div>
        </div>
        <details class="stay-photo-collection" id="all-photos">
            <summary>Browse every photo</summary>
            <div class="stay-photo-grid" id="photo-grid">
                @foreach($listing['photos'] as $photo)
                    <a href="{{ $photo['url'] }}?w=1200" data-gallery-photo data-photo-index="{{ $loop->index }}" data-photo-caption="{{ $photo['caption'] }}" aria-label="View photo {{ $loop->iteration }} of {{ $photoCount }}: {{ $photo['caption'] }}">
                        <img src="{{ $photo['url'] }}?w=480" alt="{{ $photo['caption'] }}" width="700" height="480" loading="lazy" decoding="async"><span>{{ $photo['caption'] }}</span>
                    </a>
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
