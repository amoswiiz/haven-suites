@extends('public_stays.layout')
@section('content')
<section class="stay-about-hero">
    <div class="stay-container">
        <span class="stay-eyebrow">Travel tips &amp; guides</span>
        <h1>Plan a better stay in Kenya</h1>
        <p>Neighbourhood guides, travel tips and news from the {{ $tenant->name }} team.</p>
    </div>
</section>
<div class="stay-container stay-section">
    @if($posts->count())
        <div class="stay-blog-grid">
            @foreach($posts as $post)
                <article class="stay-blog-card">
                    <a class="stay-blog-image" href="{{ route('public-stays.blog.show', [$tenant->slug, $post->slug]) }}" tabindex="-1" aria-hidden="true">@if($post->cover_url)<img src="{{ $post->cover_url }}" alt="" loading="lazy" width="640" height="360">@else<span>{{ mb_substr($post->title, 0, 1) }}</span>@endif</a>
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
