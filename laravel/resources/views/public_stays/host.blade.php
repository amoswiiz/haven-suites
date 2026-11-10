@php($hostLogo = !empty($site?->logo_path) ? route('public-stays.logo', $tenant->slug).'?v='.strtotime((string) $site->logo_updated_at) : null)
<section class="stay-host" aria-labelledby="stay-host-heading">
    <div class="stay-host-badge" aria-hidden="true">@if($hostLogo)<img src="{{ $hostLogo }}" alt="" width="56" height="56">@else{{ mb_strtoupper(mb_substr($tenant->name, 0, 1)) }}@endif</div>
    <div>
        <span class="stay-eyebrow">Your hosts</span>
        <h2 id="stay-host-heading">Hosted by the {{ $tenant->name }} team</h2>
        <p>{{ $guestInfo['host_intro'] }}</p>
        <ul class="stay-host-facts"><li>{{ $guestInfo['reply_time'] }}</li><li>Speaks {{ $guestInfo['languages'] }}</li>@if(!empty($reviews['count']))<li>{{ $reviews['count'] }} {{ $reviews['count'] === 1 ? 'review' : 'reviews' }} for this stay</li>@endif</ul>
    </div>
</section>
