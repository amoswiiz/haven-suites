@if(!empty($listing['rooms']))
    @php($photoUrls = collect($listing['photos'])->keyBy('id'))
    <section class="stay-detail-section" id="stay-rooms" aria-labelledby="stay-rooms-heading">
        <h2 id="stay-rooms-heading">Where you'll sleep</h2>
        <div class="stay-rooms">
            @foreach($listing['rooms'] as $room)
                @php($roomPhoto = $room['photo_id'] ? $photoUrls->get($room['photo_id']) : null)
                <article class="stay-room">
                    @if($roomPhoto)
                        <a class="stay-room-photo" href="{{ $roomPhoto['url'] }}?w=1200" data-gallery-photo data-photo-index="{{ collect($listing['photos'])->search(fn ($photo) => $photo['id'] === $roomPhoto['id']) }}" data-photo-caption="{{ $roomPhoto['caption'] }}" aria-label="View photo of {{ $room['name'] }}"><img src="{{ $roomPhoto['url'] }}?w=480" alt="{{ $roomPhoto['caption'] }}" width="480" height="320" loading="lazy" decoding="async"></a>
                    @else
                        <div class="stay-room-photo is-empty" aria-hidden="true"><svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 18v-6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v6M3 18h18M3 18v2m18-2v2M6 10V7a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v3"/></svg></div>
                    @endif
                    <h3>{{ $room['name'] }}</h3>
                    @if($room['beds'] !== '')<p>{{ $room['beds'] }}</p>@endif
                    @if($room['ensuite'])<p class="stay-room-ensuite">Ensuite bathroom</p>@endif
                </article>
            @endforeach
        </div>
    </section>
@endif
