@if(!empty($listing['videos']))
    <section class="stay-detail-section" id="stay-videos" aria-labelledby="stay-videos-heading">
        <span class="stay-eyebrow">A closer look</span><h2 id="stay-videos-heading">Picture yourself here</h2>
        <p class="stay-muted">Explore the apartment before you enquire. Select a preview to play its YouTube video.</p>
        <div class="stay-video-grid {{ count($listing['videos']) === 1 ? 'single' : '' }}">
            @foreach($listing['videos'] as $video)
                <article class="stay-video-card" data-video-card>
                    <div class="stay-video-player" data-video-player>
                        <a class="stay-video-preview" href="{{ $video['watch_url'] }}" target="_blank" rel="noopener noreferrer" data-play-video data-video-url="{{ $video['embed_url'] }}" data-video-title="{{ $video['title'] }}" aria-label="Play {{ $video['title'] }} on YouTube">
                            <img src="{{ $video['thumbnail_url'] }}" alt="" width="480" height="360" loading="lazy" decoding="async" referrerpolicy="no-referrer" @if(!empty($listing['photos'][0]['url'])) data-video-poster-fallback="{{ $listing['photos'][0]['url'] }}" @endif>
                            <span class="stay-video-play"><span class="stay-play-icon" aria-hidden="true">&#9654;</span><span>Play video {{ $loop->iteration }}</span></span>
                        </a>
                    </div>
                    <div class="stay-video-description"><h3>{{ $video['title'] }}</h3><p data-video-status aria-live="polite">Hosted on YouTube.</p><a href="{{ $video['watch_url'] }}" target="_blank" rel="noopener noreferrer">Watch on YouTube &nearr;</a></div>
                </article>
            @endforeach
        </div>
    </section>
@endif
