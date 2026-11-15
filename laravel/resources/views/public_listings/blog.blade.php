@extends('saas.layout')
@section('title', 'Blog')
@section('content')
@php($tenantSlug = app('currentTenant')->slug)
<p><a href="{{ route('public-listings.index', $tenantSlug) }}">&larr; Public listings</a></p>
<div class="split"><div><h1>Blog</h1><p class="muted">Write travel tips, neighbourhood guides and news. Helpful posts bring visitors from Google and build trust before guests enquire.</p></div>
    <div class="actions"><a class="button" href="{{ route('public-listings.blog.create', $tenantSlug) }}">+ New post</a>@if($site?->enabled)<a class="button secondary" href="{{ route('public-stays.blog', $tenantSlug) }}" target="_blank" rel="noopener">View blog</a>@endif</div></div>
@php($seoColours = ['good' => '#1a7f4b', 'ok' => '#b8650f', 'fix' => '#c0392b'])
@if($checks->isNotEmpty())
    <section class="card" aria-labelledby="seo-overview-heading"><h2 id="seo-overview-heading">Search progress</h2>
        <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
            <div><p class="muted" style="margin:0">Average score</p><strong style="font-size:26px">{{ (int) round($checks->avg('score')) }}/100</strong></div>
            <div><p class="muted" style="margin:0">Ready to compete (80+)</p><strong style="font-size:26px">{{ $checks->where('grade', 'good')->count() }} of {{ $checks->count() }}</strong></div>
            <div><p class="muted" style="margin:0">Without a focus keyword</p><strong style="font-size:26px">{{ $checks->filter(fn ($check) => collect($check['checks'])->contains('label', 'Focus keyword'))->count() }}</strong></div>
            <div><p class="muted" style="margin:0">Published, no posts linking in</p><strong style="font-size:26px">{{ $orphans }}</strong></div>
        </div>
        <p class="muted" style="margin:10px 0 0">Scores on this page. Open a post to see its checklist; lowest scores are the quickest wins.</p>
    </section>
@endif
<section class="card">
    @forelse($posts as $post)
        @php($scheduled = $post->status === 'published' && $post->published_at && \Illuminate\Support\Carbon::parse($post->published_at)->isFuture())
        @php($seo = $checks[$post->id])
        <article style="display:flex;justify-content:space-between;gap:16px;align-items:center;border-top:1px solid #e2e7ef;padding:14px 0">
            <div><h2 style="font-size:17px;margin:0"><a href="{{ route('public-listings.blog.edit', [$tenantSlug, $post->id]) }}">{{ $post->title }}</a></h2>
                <p class="muted" style="margin:4px 0 0"><span class="pill">{{ $scheduled ? 'Scheduled' : ($post->status === 'published' ? 'Published' : 'Draft') }}</span> <span class="pill">{{ ($post->audience ?? 'guests') === 'owners' ? 'For owners' : 'For guests' }}</span> {{ $post->published_at ? \Illuminate\Support\Carbon::parse($post->published_at)->format('j M Y') : 'Not published' }} &middot; /blog/{{ $post->slug }}</p>
                <p class="muted" style="margin:4px 0 0"><strong style="color:{{ $seoColours[$seo['grade']] }}">Search score {{ $seo['score'] }}/100</strong> &middot; {{ number_format($seo['stats']['words']) }} words &middot; {{ $seo['stats']['internal_links'] }} links to your pages &middot; linked from {{ count($seo['stats']['linked_from']) }} {{ count($seo['stats']['linked_from']) === 1 ? 'post' : 'posts' }}{{ $post->focus_keyword ? ' · Keyword: '.$post->focus_keyword : '' }}</p></div>
            <div class="actions"><a href="{{ route('public-listings.blog.edit', [$tenantSlug, $post->id]) }}">Edit</a>@if($post->status === 'published' && !$scheduled && $site?->enabled)<a href="{{ route('public-stays.blog.show', [$tenantSlug, $post->slug]) }}" target="_blank" rel="noopener">View</a>@endif</div>
        </article>
    @empty
        <p>No posts yet. Ideas to start with: <em>"Best areas to stay in Nairobi for business trips"</em>, <em>"A weekend in Nyali: beaches and restaurants"</em>, <em>"Getting from JKIA to Kilimani"</em>.</p>
    @endforelse
    {{ $posts->links() }}
</section>
@endsection
