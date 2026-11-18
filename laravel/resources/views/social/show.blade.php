@extends('saas.layout')
@section('title', 'Conversation')
@section('content')
@php
    $tenantSlug = app('currentTenant')->slug;
    $platform = $account->platform ?? 'facebook';
    $platformName = \App\Services\Social\SocialInbox::PLATFORMS[$platform] ?? ucfirst($platform);
    $person = $thread->participant_name ?: ($thread->participant_username ? '@'.$thread->participant_username : 'Unknown person');
    $canManage = auth()->user()->can('messaging.manage');
    $timezone = app('currentTenant')->timezone ?: config('app.timezone');
@endphp
<style>
.sc-wrap{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:20px;align-items:start}
.sc-thread{background:#fff;border:1px solid #dce3ed;border-radius:12px;padding:18px}
.sc-msg{max-width:78%;margin:0 0 14px;padding:10px 14px;border-radius:14px;background:#f1f4f9;color:#1f2f4b}.sc-msg.out{margin-left:auto;background:#e8effc}.sc-msg.is-deleted{opacity:.6;font-style:italic}
.sc-msg p{margin:0;white-space:pre-wrap;overflow-wrap:anywhere}.sc-by{font-size:.74rem;color:#52627a;margin-top:6px}.sc-msg.out .sc-by{text-align:right}
.sc-reply textarea{width:100%;min-height:96px}.sc-side .card{margin-bottom:14px}.sc-side h2{font-size:1rem;margin-top:0}
.sc-context{background:#f7f9fd;border:1px solid #e4eaf2;border-radius:10px;padding:10px 12px;margin-bottom:16px;font-size:.88rem;color:#3b4b63}
@media(max-width:900px){.sc-wrap{grid-template-columns:1fr}.sc-msg{max-width:92%}}
</style>
<p><a href="{{ route('social.index', $tenantSlug) }}">&larr; Social inbox</a></p>
<h1 style="margin-bottom:4px">{{ $person }}</h1>
<p class="muted">{{ $platformName }} {{ $thread->kind === 'comment' ? 'comment' : 'message' }}@if($account) to {{ $account->name }}@endif @if($thread->participant_username && $thread->participant_name) · {{ '@'.$thread->participant_username }}@endif</p>
<div class="sc-wrap">
    <div>
        @if($thread->kind === 'comment')
            <div class="sc-context">On the post: <strong>{{ $thread->context_title ?: 'a '.$platformName.' post' }}</strong>@if($thread->context_url) · <a href="{{ $thread->context_url }}" target="_blank" rel="noopener noreferrer">Open on {{ $platformName }} &nearr;</a>@endif</div>
        @endif
        <div class="sc-thread" aria-label="Conversation">
            @foreach($messages as $message)
                <div class="sc-msg {{ $message->direction === 'out' ? 'out' : '' }} {{ $message->status === 'deleted' ? 'is-deleted' : '' }}">
                    <p>{{ $message->status === 'deleted' ? 'This was deleted on '.$platformName.'.' : ($message->body ?? '') }}</p>
                    @foreach(json_decode((string) $message->attachments, true) ?: [] as $attachment)
                        <p>@if(!empty($attachment['url']))<a href="{{ $attachment['url'] }}" target="_blank" rel="noopener noreferrer">{{ ucfirst($attachment['type']) }} attachment &nearr;</a>@else{{ ucfirst($attachment['type']) }} attachment @endif</p>
                    @endforeach
                    <div class="sc-by">
                        @if($message->direction === 'in'){{ $message->author_name ?: $person }}
                        @elseif($message->origin === 'haven')Sent from Haven{{ $message->sent_by && isset($senders[$message->sent_by]) ? ' by '.$senders[$message->sent_by] : '' }}
                        @else Sent from the {{ $platformName }} app @endif
                        · {{ \Illuminate\Support\Carbon::parse($message->sent_at)->timezone($timezone)->format('j M Y, H:i') }}
                    </div>
                </div>
            @endforeach
        </div>
        @if($canManage)
            <form class="sc-reply card" method="post" action="{{ route('social.reply', [$tenantSlug, $thread->id]) }}" style="margin-top:14px">
                @csrf
                <label for="reply-body">{{ $thread->kind === 'comment' ? 'Reply publicly under this comment' : 'Reply to '.$person }}</label>
                @if($canReply)
                    <textarea id="reply-body" name="body" maxlength="2000" required>{{ old('body') }}</textarea>
                    <div class="actions"><button type="submit">Send reply</button></div>
                    @if($thread->kind === 'comment')<p class="muted">Everyone who can see the post will see this reply. Ask guests to message you for prices and personal details.</p>@endif
                @else
                    <p class="muted">@if(($account->status ?? null) !== 'connected')This account is not connected. Reconnect it in <a href="{{ route('sales.connections', $tenantSlug) }}">Channel connections</a> to reply from Haven.@else More than {{ \App\Services\Social\SocialInbox::REPLY_WINDOW_HOURS }} hours have passed since {{ $person }} last wrote, so Meta only allows a reply from the {{ $platformName }} app.@endif</p>
                @endif
            </form>
        @endif
    </div>
    <aside class="sc-side">
        <div class="card">
            <h2>Status: {{ \App\Services\Social\SocialInbox::STATUSES[$thread->status] ?? $thread->status }}</h2>
            @if($canManage)
                <form method="post" action="{{ route('social.update', [$tenantSlug, $thread->id]) }}">@csrf
                    <input type="hidden" name="status" value="{{ $thread->status === 'done' ? 'needs_reply' : 'done' }}">
                    <button type="submit" class="{{ $thread->status === 'done' ? 'secondary' : '' }}">{{ $thread->status === 'done' ? 'Reopen' : 'Mark as done' }}</button>
                </form>
                <form method="post" action="{{ route('social.update', [$tenantSlug, $thread->id]) }}" style="margin-top:12px">@csrf
                    <label for="assigned_to">Assigned to</label>
                    <select id="assigned_to" name="assigned_to" onchange="this.form.submit()"><option value="">Nobody</option>@foreach($members as $member)<option value="{{ $member->id }}" @selected((int) $thread->assigned_to === (int) $member->id)>{{ $member->name }}</option>@endforeach</select>
                    <noscript><button type="submit" class="secondary">Assign</button></noscript>
                </form>
            @endif
        </div>
        <div class="card">
            <h2>Sales enquiry</h2>
            @if($lead)
                <p><a href="{{ route('sales.show', [$tenantSlug, $lead->id]) }}">{{ $lead->name }} &rarr;</a></p><p class="muted">{{ config('sales.stages.'.$lead->stage) ?? $lead->stage }}</p>
            @elseif(auth()->user()->can('sales.manage'))
                <p class="muted">Asking about a stay? Turn this conversation into an enquiry so it is followed up in the sales pipeline.</p>
                <a class="button secondary" href="{{ $leadUrl }}">Create sales enquiry</a>
            @else
                <p class="muted">Not linked to an enquiry.</p>
            @endif
        </div>
    </aside>
</div>
@endsection
