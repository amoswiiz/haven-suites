@extends('saas.layout')
@section('title', 'Social inbox')
@section('content')
@php
    $tenantSlug = app('currentTenant')->slug;
    $link = fn (array $changes): string => route('social.index', $tenantSlug).'?'.http_build_query(array_filter(array_merge($filters, $changes), fn ($value) => $value !== null && $value !== ''));
    $platformLabel = \App\Services\Social\SocialInbox::PLATFORMS;
@endphp
<style>
.si-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:18px}.si-head h1{margin:0 0 4px}
.si-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}.si-tabs a{padding:8px 14px;border-radius:30px;border:1px solid #d6dfeb;color:#294780;text-decoration:none;font-weight:650;font-size:.88rem;background:#fff}
.si-tabs a.is-active{background:#294780;color:#fff;border-color:#294780}.si-tabs a span{opacity:.75;margin-left:4px}
.si-filters{display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-bottom:16px}.si-filters label{font-size:.78rem;font-weight:650;color:#52627a;display:block}.si-filters select,.si-filters input{min-height:40px}
.si-list{list-style:none;margin:0;padding:0;background:#fff;border:1px solid #dce3ed;border-radius:12px;overflow:hidden}
.si-row a{display:grid;grid-template-columns:110px 1fr auto;gap:14px;align-items:center;padding:14px 16px;border-bottom:1px solid #edf1f6;color:inherit;text-decoration:none}.si-row:last-child a{border-bottom:0}.si-row a:hover{background:#f6f9fd}
.si-row.is-unread .si-name{font-weight:800}.si-row.is-unread .si-name::before{content:'';display:inline-block;width:8px;height:8px;border-radius:50%;background:#E28D57;margin-right:7px;vertical-align:middle}
.si-platform{font-size:.75rem;font-weight:750;padding:4px 9px;border-radius:30px;text-align:center}.si-platform.facebook{background:#e7effd;color:#1f4ba8}.si-platform.instagram{background:#fbe8f1;color:#a2245f}.si-platform.whatsapp{background:#e4f6ea;color:#1b6b3c}.si-platform.google{background:#fff3df;color:#8a5200}
.si-name{font-weight:650;color:#1f2f4b}.si-preview{color:#52627a;font-size:.86rem;margin-top:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.si-meta{text-align:right;font-size:.78rem;color:#52627a;white-space:nowrap}.si-kind{display:inline-block;margin-left:6px;font-size:.72rem;font-weight:700;color:#52627a;background:#eef2f7;border-radius:30px;padding:2px 8px}
.si-empty{padding:34px 20px;text-align:center;color:#52627a}
@media(max-width:700px){.si-row a{grid-template-columns:1fr;gap:6px}.si-meta{text-align:left}.si-platform{justify-self:start}}
</style>
<div class="si-head">
    <div><h1>Social inbox</h1><p class="muted">Comments and messages from your connected Facebook and Instagram accounts. Replies sent from the Facebook, Instagram or Business Suite apps also appear here.</p></div>
    <a class="button secondary" href="{{ route('sales.connections', $tenantSlug) }}">Channel connections</a>
</div>
@if($accounts->isEmpty())
    <div class="card"><h2>Connect your accounts to start</h2><p>Connect your Facebook Page and its Instagram account in Channel connections. New comments and messages then arrive here automatically.</p><a class="button" href="{{ route('sales.connections', $tenantSlug) }}">Open Channel connections</a></div>
@endif
<nav class="si-tabs" aria-label="Conversation status">
    @foreach(['needs_reply' => 'Needs reply', 'open' => 'Replied', 'done' => 'Done', 'all' => 'All'] as $key => $label)
        <a href="{{ $link(['status' => $key, 'page' => null]) }}" class="{{ $filters['status'] === $key ? 'is-active' : '' }}" @if($filters['status'] === $key) aria-current="page" @endif>{{ $label }}@if($key !== 'all')<span>{{ (int) ($counts[$key] ?? 0) }}</span>@endif</a>
    @endforeach
</nav>
<form class="si-filters" method="get" action="{{ route('social.index', $tenantSlug) }}">
    <input type="hidden" name="status" value="{{ $filters['status'] }}">
    <div><label for="si-platform">Platform</label><select id="si-platform" name="platform"><option value="">All platforms</option>@foreach($platformLabel as $key => $label)<option value="{{ $key }}" @selected(($filters['platform'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></div>
    <div><label for="si-kind">Type</label><select id="si-kind" name="kind"><option value="">Comments and messages</option><option value="comment" @selected(($filters['kind'] ?? '') === 'comment')>Comments</option><option value="message" @selected(($filters['kind'] ?? '') === 'message')>Messages</option></select></div>
    <div><label for="si-q">Search</label><input id="si-q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name or message"></div>
    <label style="display:flex;gap:6px;align-items:center;font-size:.88rem;min-height:40px"><input type="checkbox" name="mine" value="1" style="width:auto" @checked(!empty($filters['mine']))> Assigned to me</label>
    <button type="submit" class="secondary">Filter</button>
</form>
<ul class="si-list">
    @forelse($threads as $thread)
        <li class="si-row {{ $thread->unread ? 'is-unread' : '' }}">
            <a href="{{ route('social.show', [$tenantSlug, $thread->id]) }}">
                <span class="si-platform {{ $thread->platform }}">{{ $platformLabel[$thread->platform] ?? ucfirst($thread->platform) }}</span>
                <span style="min-width:0"><span class="si-name">{{ $thread->participant_name ?: ($thread->participant_username ? '@'.$thread->participant_username : 'Unknown person') }}</span><span class="si-kind">{{ $thread->kind === 'comment' ? 'Comment' : 'Message' }}</span>
                    <span class="si-preview" style="display:block">{{ $thread->last_preview ?: '—' }}</span></span>
                <span class="si-meta">{{ $thread->last_message_at ? \Illuminate\Support\Carbon::parse($thread->last_message_at)->diffForHumans() : '' }}@if($thread->assigned_to)<br>{{ $members[$thread->assigned_to]->name ?? 'Assigned' }}@endif</span>
            </a>
        </li>
    @empty
        <li class="si-empty">{{ $filters['status'] === 'needs_reply' ? 'Nothing needs a reply. Well done!' : 'No conversations here yet.' }}</li>
    @endforelse
</ul>
<div style="margin-top:14px">{{ $threads->links() }}</div>
@endsection
