{{-- Original homepage hero (used when the new search layout is off). --}}
<section class="stay-hero"><div class="stay-container stay-hero-grid"><div class="stay-hero-copy"><span class="stay-eyebrow">Thoughtful stays. Warm welcomes.</span><h1>{{ $site->headline ?: 'Find a place that feels like yours.' }}</h1><p>{{ $site->tagline ?: 'Explore comfortable apartments, find dates that work for you, and let our team help you plan a memorable stay.' }}</p><div class="stay-hero-actions"><a class="stay-button" href="#find-stay">Find your stay <span aria-hidden="true">&darr;</span></a>@if(!empty($websiteContactLinks['whatsapp_url']))<a class="stay-hero-contact" href="{{ $websiteContactLinks['whatsapp_url'] }}" target="_blank" rel="noopener noreferrer">Talk to our team on WhatsApp <span aria-hidden="true">&nearr;</span></a>@endif</div><p class="stay-hero-note">Browse the photos. Explore your dates. Enquire directly with our team.</p></div><div class="stay-hero-image">
    @if(!empty($hero['photos'][0]))
        <img src="{{ $hero['photos'][0]['url'] }}?w=1200" srcset="{{ \App\Services\ListingPhotoVariants::srcset($hero['photos'][0]['url']) }}" sizes="(max-width: 900px) 100vw, 50vw" alt="{{ $hero['photos'][0]['caption'] }}" width="900" height="700" fetchpriority="high">
    @else
        <div class="stay-hero-empty">A little space<br>to feel at home.</div>
    @endif
</div></div></section>
