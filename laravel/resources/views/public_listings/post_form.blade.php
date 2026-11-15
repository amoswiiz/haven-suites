@extends('saas.layout')
@section('title', $post ? 'Edit post' : 'New post')
@section('content')
@php
    $tenantSlug = app('currentTenant')->slug;
    $publishedAt = old('published_at', $post?->published_at ? \Illuminate\Support\Carbon::parse($post->published_at)->format('Y-m-d\TH:i') : '');
@endphp
<p><a href="{{ route('public-listings.blog', $tenantSlug) }}">&larr; All posts</a></p>
<h1>{{ $post ? 'Edit post' : 'New post' }}</h1>
<form method="post" enctype="multipart/form-data" action="{{ $post ? route('public-listings.blog.update', [$tenantSlug, $post->id]) : route('public-listings.blog.store', $tenantSlug) }}">
    @csrf
    @if($post)<input type="hidden" name="version" value="{{ $post->version }}">@endif
    <section class="card">
        <label for="post-title">Title</label><input id="post-title" data-seo-field name="title" required minlength="5" maxlength="180" value="{{ old('title', $post->title ?? '') }}" placeholder="Best areas to stay in Nairobi for a business trip">
        <label for="post-slug">Web address</label><input id="post-slug" data-seo-field name="slug" maxlength="160" pattern="[a-z0-9]+(-[a-z0-9]+)*" value="{{ old('slug', $post->slug ?? '') }}" placeholder="Leave blank to create it from the title"><small class="muted">/blog/<strong>this-part</strong>. Lowercase letters, numbers and hyphens.</small>
        <label for="post-excerpt">Short summary <small class="muted">optional, shown in the blog list</small></label><textarea id="post-excerpt" data-seo-field name="excerpt" rows="2" maxlength="320">{{ old('excerpt', $post->excerpt ?? '') }}</textarea>
        <label for="post-body">Post</label>
        <div class="post-editor" data-post-editor data-upload-url="{{ route('public-listings.blog.image', $tenantSlug) }}" hidden></div>
        <textarea id="post-body" name="body" rows="20" required minlength="50" maxlength="60000" style="font-family:ui-monospace,Consolas,monospace" data-post-body>{{ old('body', $post->body ?? '') }}</textarea>
        <small class="muted" data-editor-help>Use the toolbar for headings, bold, lists, links, quotes, tables and photos. Drag a photo into the text or paste it to upload it (JPG, PNG or WEBP up to 5 MB). Switch to <strong>Markdown</strong> at the bottom right to edit the plain text.</small>
        <small class="muted" data-editor-fallback>Leave a blank line between paragraphs. <code>## Heading</code> for a section title, <code>- item</code> for a list, <code>**bold**</code>, and <code>[link text](https://…)</code> for a link.</small>
        <p class="muted" data-editor-status aria-live="polite"></p>
    </section>
    <section class="card seo-card" aria-labelledby="seo-heading"><h2 id="seo-heading">Search optimisation</h2>
        <p class="muted">Choose the phrase guests type into Google, then work through the checklist. It updates as you write; nothing is saved until you press Save.</p>
        <div class="grid">
            <div><label for="post-focus">Focus keyword</label><input id="post-focus" name="focus_keyword" maxlength="100" value="{{ old('focus_keyword', $post->focus_keyword ?? '') }}" placeholder="serviced apartments in Kilimani" data-seo-field><small class="muted">One phrase per post. Use a different phrase for each post.</small></div>
            <div><label for="post-seo-title">Search title <small class="muted">optional</small></label><input id="post-seo-title" name="seo_title" maxlength="180" value="{{ old('seo_title', $post->seo_title ?? '') }}" data-seo-field><small class="muted">Leave blank to use the title and company name. 30 to 60 characters.</small></div>
        </div>
        <label for="post-seo-description">Search description <small class="muted">optional</small></label><textarea id="post-seo-description" name="seo_description" maxlength="320" rows="2" data-seo-field>{{ old('seo_description', $post->seo_description ?? '') }}</textarea><small class="muted">The text under the title on Google. 120 to 160 characters that make people click.</small>
        <div class="seo-panel" data-seo-panel data-check-url="{{ route('public-listings.blog.check', $tenantSlug) }}" data-post-id="{{ $post->id ?? '' }}" data-has-cover="{{ !empty($post?->cover_path) ? 1 : 0 }}" aria-live="polite">@include('public_listings._seo_panel', ['seo' => $seo])</div>
    </section>
    <section class="card"><h2>Cover photo</h2>
        @if(!empty($post?->cover_path))<p class="muted">A cover photo is set.</p><label><input type="checkbox" name="remove_cover" value="1" style="width:auto"> Remove the cover photo</label>@endif
        <label for="post-cover">{{ !empty($post?->cover_path) ? 'Replace cover photo' : 'Upload cover photo' }}</label><input id="post-cover" data-seo-field name="cover" type="file" accept="image/jpeg,image/png,image/webp"><small class="muted">A wide landscape photo works best. JPG, PNG or WEBP up to 5 MB. Location data is removed.</small>
        <label for="post-caption">Photo description</label><input id="post-caption" name="cover_caption" maxlength="180" value="{{ old('cover_caption', $post->cover_caption ?? '') }}" placeholder="Evening view over Kilimani">
    </section>
    <section class="card"><h2>Publishing</h2><div class="grid">
        <div><label for="post-status">Status</label><select id="post-status" name="status"><option value="draft" @selected(old('status', $post->status ?? 'draft') === 'draft')>Draft (private)</option><option value="published" @selected(old('status', $post->status ?? '') === 'published')>Published</option></select></div>
        <div><label for="post-date">Publish date <small class="muted">optional</small></label><input id="post-date" name="published_at" type="datetime-local" value="{{ $publishedAt }}"><small class="muted">Leave blank to publish now. A future date schedules the post.</small></div>
    </div>
    </section>
    <div class="actions"><button type="submit" data-post-submit>Save post</button><a href="{{ route('public-listings.blog', $tenantSlug) }}">Cancel</a></div>
</form>
@if($post)
    <form method="post" action="{{ route('public-listings.blog.destroy', [$tenantSlug, $post->id]) }}" onsubmit="return confirm('Delete this post permanently?')" style="margin-top:24px">@csrf<button type="submit" class="secondary">Delete post</button></form>
@endif
@endsection

@push('styles')
<link rel="stylesheet" href="{{ \App\Support\AssetVersion::url('assets/editor/toastui-editor-3.2.2/toastui-editor.min.css') }}">
<style>
.post-editor{margin:6px 0 8px;background:#fff;border-radius:8px}
.post-editor .toastui-editor-defaultUI{border-color:#cbd5e1;border-radius:8px}
.post-editor .toastui-editor-contents{font:16px/1.7 Arial,sans-serif;color:#203456}
.post-editor .toastui-editor-contents h2{border:0;font-size:1.45rem}
.post-editor .toastui-editor-contents img{max-width:100%;border-radius:8px}
.seo-panel{margin-top:16px;border-top:1px solid #e2e7ef;padding-top:14px}
.seo-panel.is-checking{opacity:.6}
.seo-score-head{display:flex;justify-content:space-between;align-items:baseline}
.seo-score-number{font-size:30px;font-weight:700}.seo-score-number small{font-size:14px;color:#64748b}
.seo-bar{height:10px;border-radius:5px;background:#e2e7ef;overflow:hidden}.seo-bar span{display:block;height:100%;border-radius:5px;transition:width .3s}
.seo-snippet{display:grid;gap:2px;margin:16px 0;padding:12px 14px;border:1px solid #e2e7ef;border-radius:8px;background:#fff;font-family:Arial,sans-serif}
.seo-snippet-url{color:#202124;font-size:13px}.seo-snippet-title{color:#1a0dab;font-size:19px}.seo-snippet-description{color:#4d5156;font-size:14px}
.seo-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;margin:0 0 12px}
.seo-stats div{background:#f4f7fb;border-radius:8px;padding:8px 10px}.seo-stats dt{font-size:12px;color:#64748b}.seo-stats dd{margin:0;font-size:20px;font-weight:700}
.seo-checks{list-style:none;padding:0;margin:0;display:grid;gap:10px}.seo-checks li{display:flex;gap:10px;align-items:flex-start}
.seo-icon{flex:0 0 22px;height:22px;border-radius:50%;color:#fff;font-size:13px;font-weight:700;display:grid;place-items:center}
.seo-status{font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.04em}
[data-editor-help]{display:none}
.has-rich-editor [data-editor-help]{display:block}
.has-rich-editor [data-editor-fallback]{display:none}
</style>
@endpush

@push('scripts')
<script src="{{ \App\Support\AssetVersion::url('assets/editor/toastui-editor-3.2.2/toastui-editor-all.min.js') }}" defer></script>
<script src="{{ \App\Support\AssetVersion::url('assets/js/post-editor.js') }}" defer></script>
<script src="{{ \App\Support\AssetVersion::url('assets/js/post-seo.js') }}" defer></script>
@endpush
