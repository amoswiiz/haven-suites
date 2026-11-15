{{-- Google rating entered in Website settings, linking to the company's Google page. Shown only when both are set. --}}
@php
    $googleRating = isset($site->google_rating) && $site->google_rating !== null ? (float) $site->google_rating : null;
    $googleUrl = \App\Services\GoogleReviewLink::for($tenant);
@endphp
@if($googleRating && $googleUrl)
    <a class="stay-google-rating" href="{{ $googleUrl }}" target="_blank" rel="noopener noreferrer">
        <span class="stay-google-stars" aria-hidden="true">{{ str_repeat('★', (int) round($googleRating)) }}<span>{{ str_repeat('★', 5 - (int) round($googleRating)) }}</span></span>
        <strong>{{ number_format($googleRating, 1) }}</strong> on Google
        @if(!empty($site->google_review_count))<span>&middot; {{ number_format((int) $site->google_review_count) }} reviews</span>@endif
        <span aria-hidden="true">&nearr;</span>
    </a>
@endif
