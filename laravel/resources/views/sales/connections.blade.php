@extends('saas.layout')
@section('title','Channel connections')
@section('content')
@php
    $tenantSlug = app('currentTenant')->slug;
    $canManage = auth()->user()->can('messaging.manage');
    $metaReady = \App\Services\Social\MetaGraph::configured();
    $whatsapp = $connections->firstWhere('channel', 'whatsapp');
@endphp
<h1>Channel connections</h1>
<p class="muted">Connect your social accounts so comments and messages arrive in the <a href="{{ route('social.index', $tenantSlug) }}">Social inbox</a>, where your team can reply.</p>
<section class="card" aria-labelledby="meta-heading">
    <h2 id="meta-heading">Facebook and Instagram</h2>
    <p>Comments on your posts, Messenger and Instagram messages. Connecting your Facebook Page also connects the Instagram business account linked to it. Replies your team sends from the Facebook, Instagram or Business Suite apps are recorded too.</p>
    @if($socialAccounts->whereIn('platform', ['facebook', 'instagram'])->isNotEmpty())
        <div class="table-wrap"><table><thead><tr><th>Account</th><th>Status</th><th>Last activity</th><th></th></tr></thead><tbody>
            @foreach($socialAccounts->whereIn('platform', ['facebook', 'instagram']) as $account)
                <tr>
                    <td><strong>{{ \App\Services\Social\SocialInbox::PLATFORMS[$account->platform] }}</strong>: {{ $account->name }}@if($account->username) <span class="muted">{{ '@'.$account->username }}</span>@endif</td>
                    <td><span class="pill">{{ $account->status === 'connected' ? 'Connected' : 'Needs attention' }}</span>@if($account->last_error)<br><small class="muted">{{ $account->last_error }}</small>@endif</td>
                    <td>{{ $account->last_event_at ? \Illuminate\Support\Carbon::parse($account->last_event_at)->diffForHumans() : 'Nothing received yet' }}</td>
                    <td>@if($canManage && $account->platform === 'facebook')<form method="post" action="{{ route('social.disconnect', [$tenantSlug, $account->id]) }}" class="inline" onsubmit="return confirm('Disconnect {{ e($account->name) }} and its Instagram account? New comments and messages will stop arriving. Past conversations are kept.')">@csrf<button type="submit" class="secondary">Disconnect</button></form>@endif</td>
                </tr>
            @endforeach
        </tbody></table></div>
    @endif
    @if($canManage)
        @if($metaReady)
            <form method="post" action="{{ route('social.connect', $tenantSlug) }}">@csrf<button type="submit">{{ $socialAccounts->isEmpty() ? 'Connect Facebook & Instagram' : 'Connect another Page or refresh access' }}</button></form>
            <p class="muted">You will sign in to Facebook and choose your Page. Use a Facebook account that is an admin of the Page, and make sure your Instagram account is a business account linked to that Page.</p>
        @else
            <p class="muted"><strong>Waiting for Meta setup.</strong> The platform's Meta app ID and secret are not set on this server yet. Once Meta approves the app, the Connect button appears here.</p>
        @endif
    @endif
</section>
<div class="grid">
    <section class="card"><h2>WhatsApp Business</h2><span class="pill">{{ ucfirst($whatsapp?->status ?? 'Coming next') }}</span><p>WhatsApp will connect with coexistence: your number keeps working in the WhatsApp Business app on the phone, and its chats also appear in the Social inbox.</p></section>
    <section class="card"><h2>Google reviews</h2><span class="pill">Coming later</span><p>New Google reviews in the inbox, with replies posted to your Google Business Profile.</p></section>
    <section class="card"><h2>TikTok</h2><span class="pill">Use the TikTok app</span><p>TikTok does not let small business apps read or answer comments and messages yet. Keep replying in the TikTok app.</p></section>
</div>
<p><a href="{{ route('sales.index', $tenantSlug) }}">Back to sales pipeline</a></p>
@endsection
