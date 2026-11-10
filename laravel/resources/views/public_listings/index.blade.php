@extends('saas.layout')
@section('title', 'Public listings')
@push('styles')<link rel="stylesheet" href="{{ asset('assets/css/listing-management.css') }}?v=1">@endpush
@push('scripts')<script src="{{ asset('assets/js/listing-management.js') }}?v=1" defer></script>@endpush
@section('content')
@php
    $tenantSlug = app('currentTenant')->slug;
    $publicLinks = $publicLinks ?? [];
    $photoCounts = $photoCounts ?? [];
    $liveCount = count($publicLinks);
    $draftCount = $listings->where('status', 'draft')->count();
    $settingsUrl = route('public-listings.settings', $tenantSlug);
@endphp
<div class="listing-management">
    <div class="lm-heading">
        <div><p class="lm-eyebrow">Website &amp; listings</p><h1>Public listings</h1><p class="muted">Manage the apartments guests can discover, explore and enquire about.</p></div>
        <div class="lm-actions"><a class="button" href="{{ route('public-listings.create', $tenantSlug) }}">Create listing</a><a class="button secondary" href="{{ $settingsUrl }}">Website settings</a></div>
    </div>
    <section class="lm-overview" aria-label="Listing overview">
        <div><span>Total listings</span><strong>{{ $listings->count() }}</strong></div>
        <div><span>Live on website</span><strong>{{ $liveCount }}</strong></div>
        <div><span>Private drafts</span><strong>{{ $draftCount }}</strong></div>
        <div class="lm-site-state"><span>Public website</span><strong>{{ $site?->enabled ? 'Enabled' : 'Offline' }}</strong>@if($site?->enabled)<a href="{{ route('public-stays.index', ['company' => $tenantSlug]) }}" target="_blank" rel="noopener noreferrer">Open public site &nearr;</a>@else<a href="{{ $settingsUrl }}">Complete website setup &rarr;</a>@endif</div>
    </section>
    @unless($site?->enabled)
        <div class="lm-notice" role="status"><strong>Your public website is offline.</strong> You can prepare and preview listings here. Choose your guest contact details and enquiry assignee in <a href="{{ $settingsUrl }}">Website settings</a>, then enable the site when ready.</div>
    @endunless
    <section class="lm-listings" aria-labelledby="listings-heading">
        <div class="lm-section-heading"><h2 id="listings-heading">{{ ($showArchived ?? false) ? 'Archived listings' : 'Your listings' }}</h2><p class="muted">{{ ($showArchived ?? false) ? 'Archived listings are hidden from the website. Restore one to edit and publish it again.' : 'Edit details, review a private preview, or open the guest experience.' }} @if($showArchived ?? false)<a href="{{ route('public-listings.index', $tenantSlug) }}">Back to current listings</a>@elseif(($archivedCount ?? 0) > 0)<a href="{{ route('public-listings.index', $tenantSlug) }}?archived=1">Show archived listings ({{ $archivedCount }})</a>@endif</p></div>
        @forelse($listings as $listing)
            @php
                $reference = $listing->unit_id !== null ? 'unit:'.$listing->unit_id : 'partner:'.$listing->partner_apartment_id;
                $apartment = $apartments->get($reference);
                $links = $publicLinks[$listing->id] ?? null;
                $photoCount = (int) ($photoCounts[$listing->id] ?? 0);
                $incomplete = $listing->max_guests === null || $listing->min_nights === null || trim($listing->description) === '' || trim($listing->city) === '';
                $hiddenReason = match (true) {
                    !$site?->enabled => 'Enable the public website in Website settings to share this listing.',
                    $listing->status === 'draft' => 'This draft is private. Finish the details and publish it to enable guest links.',
                    $listing->status === 'paused' => 'This listing is paused. Publish it again to enable guest links.',
                    $listing->status === 'archived' => 'This listing is archived and hidden from the website. Restore it to edit and publish it again.',
                    !$apartment || $apartment->status !== 'active' => 'The linked apartment is unavailable or inactive.',
                    $incomplete => 'Complete the description, destination, guest capacity and minimum stay.',
                    $photoCount < 1 => 'Add at least one apartment photo before sharing publicly.',
                    default => 'This listing is not currently available to guests. Review its details before sharing.',
                };
            @endphp
            <article class="lm-listing" aria-labelledby="listing-title-{{ $listing->id }}" data-listing-id="{{ $listing->id }}">
                <div class="lm-listing-main">
                    <div class="lm-listing-title"><h3 id="listing-title-{{ $listing->id }}">{{ $listing->title }}</h3><span class="lm-status {{ $links ? 'is-live' : '' }}">{{ $links ? 'Live' : ucfirst($listing->status) }}</span></div>
                    <p class="lm-apartment">{{ $apartment ? $apartment->property_name.($apartment->unit_number ? ' / '.$apartment->unit_number : '') : 'Apartment no longer available' }}</p>
                    <p class="muted lm-listing-meta">{{ $apartment ? ucfirst($apartment->kind).' apartment' : 'Apartment needs review' }} <span aria-hidden="true">&middot;</span> {{ $listing->city ?: 'Destination needed' }} <span aria-hidden="true">&middot;</span> {{ $photoCount }} {{ $photoCount === 1 ? 'photo' : 'photos' }}@if($incomplete) <span aria-hidden="true">&middot;</span> Guest details needed @endif</p>
                </div>
                <div class="lm-listing-controls">
                    <div class="lm-actions">
                        @if($listing->status === 'archived')<form method="post" action="{{ route('public-listings.restore', ['tenant' => $tenantSlug, 'id' => $listing->id]) }}" class="inline">@csrf<button type="submit">Restore listing</button></form>@else<a class="button" href="{{ route('public-listings.edit', ['tenant' => $tenantSlug, 'id' => $listing->id]) }}">Edit listing</a>@endif
                        <a class="button secondary" href="{{ route('public-listings.preview', ['tenant' => $tenantSlug, 'id' => $listing->id]) }}" target="_blank" rel="noopener noreferrer">Private preview &nearr;</a>
                    </div>
                    <div class="lm-guest-actions">
                        @if($links)
                            <a href="{{ $links['public_url'] }}" target="_blank" rel="noopener noreferrer">View public listing &nearr;</a>
                            <a href="{{ $links['enquiry_url'] }}" target="_blank" rel="noopener noreferrer">Guest enquiry &nearr;</a>
                            @if(!empty($links['whatsapp_url']))<a href="{{ $links['whatsapp_url'] }}" target="_blank" rel="noopener noreferrer">WhatsApp enquiry &nearr;</a>@endif
                        @else
                            <span class="lm-disabled" aria-disabled="true">View public listing</span><span class="lm-disabled" aria-disabled="true">Guest enquiry</span>
                        @endif
                    </div>
                </div>
                @if($links)
                    <div class="lm-share" data-share-link><label for="enquiry-link-{{ $listing->id }}">Quick enquiry link</label><div><input id="enquiry-link-{{ $listing->id }}" type="url" readonly value="{{ $links['enquiry_url'] }}" aria-describedby="copy-status-{{ $listing->id }}"><button type="button" class="secondary" data-copy-link>Copy link</button></div><p id="copy-status-{{ $listing->id }}" class="lm-copy-status" role="status" aria-live="polite"></p></div>
                @else
                    <p class="lm-visibility-note">{{ $hiddenReason }}</p>
                @endif
            </article>
        @empty
            <div class="card lm-empty"><h3>Your first public listing starts here</h3><p>Create a listing for an existing apartment, or prepare drafts from your apartment records below. Add accurate details and photos, then review it before publishing.</p><a class="button" href="{{ route('public-listings.create', $tenantSlug) }}">Create listing</a></div>
        @endforelse
    </section>
    <details class="card lm-prepare"><summary>Prepare listings from your apartment records</summary><div><p class="muted">{{ $apartments->where('status', 'active')->where('kind', 'managed')->count() }} active managed apartments and {{ $apartments->where('status', 'active')->where('kind', 'partner')->count() }} active partner apartments. Prepare missing private drafts, then add descriptions, guest capacity, minimum stays and accurate photos. Existing listings remain unchanged.</p><form method="post" action="{{ route('public-listings.prepare', $tenantSlug) }}">@csrf<button type="submit" class="secondary">Prepare listings from existing apartments</button></form></div></details>
</div>
@endsection
