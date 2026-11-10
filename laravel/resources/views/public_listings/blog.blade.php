@extends('saas.layout')
@section('title', 'Blog')
@section('content')
@php($tenantSlug = app('currentTenant')->slug)
<p><a href="{{ route('public-listings.index', $tenantSlug) }}">&larr; Public listings</a></p>
<div class="split"><div><h1>Blog</h1><p class="muted">Write travel tips, neighbourhood guides and news. Helpful posts bring visitors from Google and build trust before guests enquire.</p></div>
    <div class="actions"><a class="button" href="{{ route('public-listings.blog.create', $tenantSlug) }}">+ New post</a>@if($site?->enabled)<a class="button secondary" href="{{ route('public-stays.blog', $tenantSlug) }}" target="_blank" rel="noopener">View blog</a>@endif</div></div>
<section class="card">
    @forelse($posts as $post)
        @php($scheduled = $post->status === 'published' && $post->published_at && \Illuminate\Support\Carbon::parse($post->published_at)->isFuture())
        <article style="display:flex;justify-content:space-between;gap:16px;align-items:center;border-top:1px solid #e2e7ef;padding:14px 0">
            <div><h2 style="font-size:17px;margin:0"><a href="{{ route('public-listings.blog.edit', [$tenantSlug, $post->id]) }}">{{ $post->title }}</a></h2>
                <p class="muted" style="margin:4px 0 0"><span class="pill">{{ $scheduled ? 'Scheduled' : ($post->status === 'published' ? 'Published' : 'Draft') }}</span> {{ $post->published_at ? \Illuminate\Support\Carbon::parse($post->published_at)->format('j M Y') : 'Not published' }} &middot; /blog/{{ $post->slug }}</p></div>
            <div class="actions"><a href="{{ route('public-listings.blog.edit', [$tenantSlug, $post->id]) }}">Edit</a>@if($post->status === 'published' && !$scheduled && $site?->enabled)<a href="{{ route('public-stays.blog.show', [$tenantSlug, $post->slug]) }}" target="_blank" rel="noopener">View</a>@endif</div>
        </article>
    @empty
        <p>No posts yet. Ideas to start with: <em>"Best areas to stay in Nairobi for business trips"</em>, <em>"A weekend in Nyali: beaches and restaurants"</em>, <em>"Getting from JKIA to Kilimani"</em>.</p>
    @endforelse
    {{ $posts->links() }}
</section>
@endsection
