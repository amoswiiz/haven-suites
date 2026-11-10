@extends('saas.layout')
@section('title', 'Guest reviews')
@section('content')
@php
    $tenantSlug = app('currentTenant')->slug;
    $tabs = ['pending' => 'Waiting for approval', 'approved' => 'On the website', 'hidden' => 'Hidden', 'requested' => 'Links sent'];
@endphp
<p><a href="{{ route('public-listings.index', $tenantSlug) }}">&larr; Public listings</a></p>
<div class="split"><div><h1>Guest reviews</h1><p class="muted">Ask guests to rate their stay, approve what they write, and show the best on your website. Nothing appears until you approve it.</p></div></div>

@if($newLink)
    <section class="card" aria-labelledby="new-link-heading" style="border-color:#1f4ba8">
        <h2 id="new-link-heading">Share this review link</h2>
        <p class="muted">The link works once and expires in {{ \App\Services\PublicReviews::LINK_DAYS }} days. It is shown only now.</p>
        <label for="review-link">Review link</label><input id="review-link" readonly value="{{ $newLink['url'] }}" onclick="this.select()">
        <label for="review-message">Message</label><textarea id="review-message" rows="3" readonly onclick="this.select()">{{ $newLink['message'] }}</textarea>
        <div class="actions"><a class="button" href="{{ $newLink['whatsapp_url'] }}" target="_blank" rel="noopener noreferrer">Send on WhatsApp</a></div>
    </section>
@endif

<div class="grid">
    <section class="card" aria-labelledby="request-heading">
        <h2 id="request-heading">Ask a guest for a review</h2>
        <p class="muted">Send after check-out. The guest gets a private link and their review is marked "Verified stay".</p>
        <form method="post" action="{{ route('public-listings.reviews.request', $tenantSlug) }}">
            @csrf
            <label for="request-listing">Apartment</label><select id="request-listing" name="listing_id"><option value="">General (not one apartment)</option>@foreach($listings as $listing)<option value="{{ $listing->id }}" @selected((int) old('listing_id') === (int) $listing->id)>{{ $listing->title }}{{ $listing->listing_code ? ' ('.$listing->listing_code.')' : '' }}</option>@endforeach</select>
            <label for="request-name">Guest name</label><input id="request-name" name="guest_name" required maxlength="100" value="{{ old('guest_name') }}">
            <label for="request-phone">Guest mobile <small class="muted">for SMS or WhatsApp</small></label><input id="request-phone" name="guest_phone" type="tel" inputmode="tel" maxlength="40" placeholder="+254 712 345 678" value="{{ old('guest_phone', \App\Support\PhoneNumber::DEFAULT_PREFIX.' ') }}">
            <label for="request-stayed">Check-out date <small class="muted">optional</small></label><input id="request-stayed" name="stayed_on" type="date" max="{{ now()->toDateString() }}" value="{{ old('stayed_on') }}">
            <label><input type="checkbox" name="send_sms" value="1" style="width:auto" @checked(old('send_sms', $smsAvailable)) @disabled(!$smsAvailable)> Send the link by SMS @unless($smsAvailable)<small class="muted">(SMS is not set up: share on WhatsApp instead)</small>@endunless</label>
            <button type="submit">Create review link</button>
        </form>
    </section>
    <section class="card" aria-labelledby="manual-heading">
        <h2 id="manual-heading">Add a review from another site</h2>
        <p class="muted">Copy a genuine review a guest left on Airbnb, Booking.com or Google. It is shown with its source.</p>
        <form method="post" action="{{ route('public-listings.reviews.store', $tenantSlug) }}">
            @csrf
            <label for="manual-listing">Apartment</label><select id="manual-listing" name="listing_id"><option value="">General (not one apartment)</option>@foreach($listings as $listing)<option value="{{ $listing->id }}">{{ $listing->title }}</option>@endforeach</select>
            <div class="grid"><div><label for="manual-source">Source</label><select id="manual-source" name="source">@foreach($sources as $key => $label)@continue($key === 'direct')<option value="{{ $key }}" @selected(old('source') === $key)>{{ $label }}</option>@endforeach</select></div>
            <div><label for="manual-rating">Rating</label><select id="manual-rating" name="rating">@for($star = 5; $star >= 1; $star--)<option value="{{ $star }}" @selected((int) old('rating', 5) === $star)>{{ $star }} star{{ $star === 1 ? '' : 's' }}</option>@endfor</select></div></div>
            <label for="manual-name">Guest name</label><input id="manual-name" name="guest_name" required maxlength="100" value="{{ old('guest_name') }}">
            <label for="manual-comment">Review text</label><textarea id="manual-comment" name="comment" rows="3" required minlength="10" maxlength="2000">{{ old('comment') }}</textarea>
            <label for="manual-stayed">Stay date <small class="muted">optional</small></label><input id="manual-stayed" name="stayed_on" type="date" max="{{ now()->toDateString() }}" value="{{ old('stayed_on') }}">
            <button type="submit">Add review</button>
        </form>
    </section>
</div>

<nav class="actions" aria-label="Review status">@foreach($tabs as $key => $label)<a href="{{ route('public-listings.reviews', [$tenantSlug, 'status' => $key]) }}" @if($status === $key) aria-current="page" style="font-weight:700" @endif>{{ $label }} <span class="pill">{{ $counts[$key] ?? 0 }}</span></a>@endforeach</nav>

<section class="card" aria-labelledby="list-heading">
    <h2 id="list-heading">{{ $tabs[$status] }}</h2>
    @forelse($reviews as $review)
        <article style="border-top:1px solid #e2e7ef;padding:14px 0">
            <p style="margin:0"><strong>{{ $review->guest_name }}</strong> &middot; <span class="muted">{{ $review->listing_id ? ($listings[$review->listing_id]->title ?? 'Removed listing') : 'General' }}</span> &middot; <span class="pill">{{ $sources[$review->source] ?? $review->source }}</span>
                @if($review->rating) &middot; <span aria-label="{{ $review->rating }} out of 5" style="color:#e28d57">{{ str_repeat('★', (int) $review->rating) }}<span style="color:#cbd5e1">{{ str_repeat('★', 5 - (int) $review->rating) }}</span></span>@endif</p>
            @if($review->comment)<p style="white-space:pre-line">{{ $review->comment }}</p>@endif
            @if($status === 'requested')<p class="muted">Link sent {{ \Illuminate\Support\Carbon::parse($review->created_at)->diffForHumans() }}{{ $review->sms_status ? ' · SMS '.$review->sms_status : '' }} · expires {{ \Illuminate\Support\Carbon::parse($review->expires_at)->format('j M Y') }}</p>@endif
            @if($review->reply)<p class="muted"><strong>Your reply:</strong> {{ $review->reply }}</p>@endif
            <div class="actions">
                @if(in_array($status, ['pending', 'hidden'], true))<form method="post" action="{{ route('public-listings.reviews.moderate', [$tenantSlug, $review->id]) }}">@csrf<input type="hidden" name="action" value="approve"><button type="submit">Approve</button></form>@endif
                @if(in_array($status, ['pending', 'approved'], true))<form method="post" action="{{ route('public-listings.reviews.moderate', [$tenantSlug, $review->id]) }}">@csrf<input type="hidden" name="action" value="hide"><button type="submit" class="secondary">Hide</button></form>@endif
                <form method="post" action="{{ route('public-listings.reviews.moderate', [$tenantSlug, $review->id]) }}" onsubmit="return confirm('Delete this review permanently?')">@csrf<input type="hidden" name="action" value="delete"><button type="submit" class="secondary">{{ $status === 'requested' ? 'Cancel link' : 'Delete' }}</button></form>
            </div>
            @if($status !== 'requested')
                <details><summary>{{ $review->reply ? 'Edit reply' : 'Reply publicly' }}</summary>
                    <form method="post" action="{{ route('public-listings.reviews.moderate', [$tenantSlug, $review->id]) }}">@csrf<input type="hidden" name="action" value="reply"><label for="reply-{{ $review->id }}">Reply shown under the review</label><textarea id="reply-{{ $review->id }}" name="reply" rows="2" maxlength="1000">{{ $review->reply }}</textarea><button type="submit">Save reply</button></form>
                </details>
            @endif
        </article>
    @empty
        <p class="muted">Nothing here yet.</p>
    @endforelse
    {{ $reviews->links() }}
</section>
@endsection
