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
        <label for="post-title">Title</label><input id="post-title" name="title" required minlength="5" maxlength="180" value="{{ old('title', $post->title ?? '') }}" placeholder="Best areas to stay in Nairobi for a business trip">
        <label for="post-slug">Web address</label><input id="post-slug" name="slug" maxlength="160" pattern="[a-z0-9]+(-[a-z0-9]+)*" value="{{ old('slug', $post->slug ?? '') }}" placeholder="Leave blank to create it from the title"><small class="muted">/blog/<strong>this-part</strong>. Lowercase letters, numbers and hyphens.</small>
        <label for="post-excerpt">Short summary <small class="muted">optional, shown in the blog list</small></label><textarea id="post-excerpt" name="excerpt" rows="2" maxlength="320">{{ old('excerpt', $post->excerpt ?? '') }}</textarea>
        <label for="post-body">Post</label>
        <div class="post-editor" data-post-editor data-upload-url="{{ route('public-listings.blog.image', $tenantSlug) }}" hidden></div>
        <textarea id="post-body" name="body" rows="20" required minlength="50" maxlength="60000" style="font-family:ui-monospace,Consolas,monospace" data-post-body>{{ old('body', $post->body ?? '') }}</textarea>
        <small class="muted" data-editor-help>Use the toolbar for headings, bold, lists, links, quotes, tables and photos. Drag a photo into the text or paste it to upload it (JPG, PNG or WEBP up to 5 MB). Switch to <strong>Markdown</strong> at the bottom right to edit the plain text.</small>
        <small class="muted" data-editor-fallback>Leave a blank line between paragraphs. <code>## Heading</code> for a section title, <code>- item</code> for a list, <code>**bold**</code>, and <code>[link text](https://…)</code> for a link.</small>
        <p class="muted" data-editor-status aria-live="polite"></p>
    </section>
    <section class="card"><h2>Cover photo</h2>
        @if(!empty($post?->cover_path))<p class="muted">A cover photo is set.</p><label><input type="checkbox" name="remove_cover" value="1" style="width:auto"> Remove the cover photo</label>@endif
        <label for="post-cover">{{ !empty($post?->cover_path) ? 'Replace cover photo' : 'Upload cover photo' }}</label><input id="post-cover" name="cover" type="file" accept="image/jpeg,image/png,image/webp"><small class="muted">A wide landscape photo works best. JPG, PNG or WEBP up to 5 MB. Location data is removed.</small>
        <label for="post-caption">Photo description</label><input id="post-caption" name="cover_caption" maxlength="180" value="{{ old('cover_caption', $post->cover_caption ?? '') }}" placeholder="Evening view over Kilimani">
    </section>
    <section class="card"><h2>Publishing</h2><div class="grid">
        <div><label for="post-status">Status</label><select id="post-status" name="status"><option value="draft" @selected(old('status', $post->status ?? 'draft') === 'draft')>Draft (private)</option><option value="published" @selected(old('status', $post->status ?? '') === 'published')>Published</option></select></div>
        <div><label for="post-date">Publish date <small class="muted">optional</small></label><input id="post-date" name="published_at" type="datetime-local" value="{{ $publishedAt }}"><small class="muted">Leave blank to publish now. A future date schedules the post.</small></div>
    </div>
        <div class="grid"><div><label for="post-seo-title">Search title <small class="muted">optional</small></label><input id="post-seo-title" name="seo_title" maxlength="180" value="{{ old('seo_title', $post->seo_title ?? '') }}"></div>
        <div><label for="post-seo-description">Search description <small class="muted">optional</small></label><textarea id="post-seo-description" name="seo_description" maxlength="320" rows="2">{{ old('seo_description', $post->seo_description ?? '') }}</textarea></div></div>
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
[data-editor-help]{display:none}
.has-rich-editor [data-editor-help]{display:block}
.has-rich-editor [data-editor-fallback]{display:none}
</style>
@endpush

@push('scripts')
<script src="{{ \App\Support\AssetVersion::url('assets/editor/toastui-editor-3.2.2/toastui-editor-all.min.js') }}" defer></script>
<script src="{{ \App\Support\AssetVersion::url('assets/js/post-editor.js') }}" defer></script>
@endpush
