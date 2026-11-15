@extends('public_stays.layout')
@section('content')
<article class="stay-post">
    <header class="stay-container stay-post-head">
        <nav class="stay-breadcrumb" aria-label="Breadcrumb"><a href="{{ route('public-stays.index', $tenant->slug) }}">Home</a> <span aria-hidden="true">›</span> <a href="{{ route('public-stays.blog', $tenant->slug) }}">Travel tips &amp; guides</a> <span aria-hidden="true">›</span> <span aria-current="page">{{ \Illuminate\Support\Str::limit($post->title, 60) }}</span></nav>
        <h1>{{ $post->title }}</h1>
        <p class="stay-blog-meta"><time datetime="{{ \Carbon\CarbonImmutable::parse($post->published_at)->toDateString() }}">{{ \Carbon\CarbonImmutable::parse($post->published_at)->format('j F Y') }}</time> &middot; {{ $post->reading_minutes }} min read{{ $authorName ? ' · By '.$authorName : '' }}</p>
        @if($post->excerpt)<p class="stay-post-lead">{{ $post->excerpt }}</p>@endif
    </header>
    @if($post->cover_url)<figure class="stay-container stay-post-cover"><img src="{{ \App\Services\PublicBlog::sized($post->cover_url, 1200) }}" srcset="{{ \App\Services\PublicBlog::srcset($post->cover_url) }}" sizes="(max-width: 1240px) 100vw, 1200px" alt="{{ $post->cover_caption ?: $post->title }}" width="1200" height="640" fetchpriority="high" decoding="async">@if($post->cover_caption)<figcaption>{{ $post->cover_caption }}</figcaption>@endif</figure>@endif
    <div class="stay-container">
        @if($contents !== [])
            <nav class="stay-post-contents" aria-labelledby="post-contents-heading"><strong id="post-contents-heading">In this guide</strong><ol>@foreach($contents as $section)<li><a href="#{{ $section['id'] }}">{{ $section['title'] }}</a></li>@endforeach</ol></nav>
        @endif
        <div class="stay-post-body">{!! $html !!}</div>
        <aside class="stay-post-cta"><strong>Planning a stay?</strong><p>Browse our serviced apartments and send an enquiry. Our team confirms your dates and price.</p><div class="stay-track-actions"><a class="stay-button" href="{{ route('public-stays.index', $tenant->slug) }}#find-stay">Find a stay &rarr;</a>@if(!empty($websiteContactLinks['whatsapp_url']))<a class="stay-button secondary" href="{{ $websiteContactLinks['whatsapp_url'] }}" target="_blank" rel="noopener noreferrer">WhatsApp us <span aria-hidden="true">&nearr;</span></a>@endif</div></aside>
    </div>
</article>
@if($related->isNotEmpty())
    <section class="stay-container stay-section" aria-labelledby="related-heading"><span class="stay-eyebrow">Keep reading</span><h2 id="related-heading">More guides</h2>
        <div class="stay-blog-grid">@foreach($related as $other)<article class="stay-blog-card"><div class="stay-blog-body"><p class="stay-blog-meta">{{ \Carbon\CarbonImmutable::parse($other->published_at)->format('j M Y') }}</p><h3><a href="{{ route('public-stays.blog.show', [$tenant->slug, $other->slug]) }}">{{ $other->title }}</a></h3><p>{{ $other->summary }}</p></div></article>@endforeach</div>
    </section>
@endif
@endsection
