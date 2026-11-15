@extends('public_stays.layout')
@section('content')
<section class="stay-about-hero">
    <div class="stay-container">
        @if($audience === 'owners')
            <span class="stay-eyebrow">For property owners</span>
            <h1>Earn more from your property in Kenya</h1>
            <p>Practical advice on short-term rental income, pricing, regulations and management from the {{ $tenant->name }} team. <a href="{{ route('public-stays.owners', $tenant->slug) }}">Talk to us about managing your property &rarr;</a></p>
        @else
            <span class="stay-eyebrow">Travel tips &amp; guides</span>
            <h1>Plan a better stay in Kenya</h1>
            <p>Neighbourhood guides, travel tips and news from the {{ $tenant->name }} team.</p>
        @endif
    </div>
</section>
<div class="stay-container stay-section">
    @if($audienceCounts['guests'] > 0 && $audienceCounts['owners'] > 0)
        <nav class="stay-blog-tabs" aria-label="Guides for">
            <a href="{{ route('public-stays.blog', $tenant->slug) }}" @if($audience === 'guests') aria-current="page" @endif>For guests <span>{{ $audienceCounts['guests'] }}</span></a>
            <a href="{{ route('public-stays.blog', [$tenant->slug, 'for' => 'owners']) }}" @if($audience === 'owners') aria-current="page" @endif>For property owners <span>{{ $audienceCounts['owners'] }}</span></a>
        </nav>
    @endif
    @if($posts->count())
        <div class="stay-blog-grid">
            @foreach($posts as $post)
                <article class="stay-blog-card">
                    <a class="stay-blog-image" href="{{ route('public-stays.blog.show', [$tenant->slug, $post->slug]) }}" tabindex="-1" aria-hidden="true">@if($post->cover_url)<img src="{{ \App\Services\PublicBlog::sized($post->cover_url, 480) }}" srcset="{{ \App\Services\PublicBlog::srcset($post->cover_url) }}" sizes="(max-width: 700px) 100vw, 400px" alt="" loading="lazy" decoding="async" width="640" height="360">@else<span>{{ mb_substr($post->title, 0, 1) }}</span>@endif</a>
                    <div class="stay-blog-body">
                        <p class="stay-blog-meta"><time datetime="{{ \Carbon\CarbonImmutable::parse($post->published_at)->toDateString() }}">{{ \Carbon\CarbonImmutable::parse($post->published_at)->format('j M Y') }}</time> &middot; {{ $post->reading_minutes }} min read</p>
                        <h2><a href="{{ route('public-stays.blog.show', [$tenant->slug, $post->slug]) }}">{{ $post->title }}</a></h2>
                        <p>{{ $post->summary }}</p>
                        <a class="stay-blog-more" href="{{ route('public-stays.blog.show', [$tenant->slug, $post->slug]) }}" aria-label="Read {{ $post->title }}">Read more &rarr;</a>
                    </div>
                </article>
            @endforeach
        </div>
        {{ $posts->links('public_stays.pagination') }}
    @else
        <div class="stay-empty"><h2>New guides are on the way</h2><p>We're writing our first travel tips and neighbourhood guides. In the meantime, explore our stays or ask our team for local advice.</p><a class="stay-button" href="{{ route('public-stays.index', $tenant->slug) }}#find-stay">Find a stay &rarr;</a></div>
    @endif
</div>
@endsection
