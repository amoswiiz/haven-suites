@extends('public_stays.layout')
@section('content')
<div class="stay-container stay-review-page">
    @if($submitted)
        <div class="stay-review-form-card">
            <span class="stay-eyebrow">Thank you</span>
            <h1>Thanks for your review</h1>
            <p>We read every review. Once our team has checked it, it will appear on our website to help other guests.</p>
            <a class="stay-button" href="{{ route('public-stays.index', $tenant->slug) }}">Back to {{ $tenant->name }} <span aria-hidden="true">&rarr;</span></a>
        </div>
    @elseif(!$review)
        <div class="stay-review-form-card">
            <span class="stay-eyebrow">Review link</span>
            <h1>This review link has expired or was already used</h1>
            <p>If you'd still like to share your experience, please contact our team and we'll send you a new link.</p>
            <a class="stay-button" href="{{ route('public-stays.index', $tenant->slug) }}">Visit {{ $tenant->name }} <span aria-hidden="true">&rarr;</span></a>
        </div>
    @else
        <form class="stay-review-form-card" method="post" action="{{ route('public-stays.review.submit', [$tenant->slug, $token]) }}">
            @csrf
            <span class="stay-eyebrow">Rate your stay</span>
            <h1>How was your stay{{ $listingTitle ? ' at '.$listingTitle : '' }}?</h1>
            <p>Your honest feedback helps other guests choose, and helps us improve.</p>
            <div class="stay-honey" aria-hidden="true"><label for="website">Website</label><input type="text" id="website" name="website" tabindex="-1" autocomplete="off"></div>
            <fieldset class="stay-rating-input"><legend>Your rating *</legend>
                <div class="stay-rating-options">@for($star = 5; $star >= 1; $star--)<input type="radio" id="rating-{{ $star }}" name="rating" value="{{ $star }}" required @checked((int) old('rating') === $star)><label for="rating-{{ $star }}" title="{{ $star }} star{{ $star === 1 ? '' : 's' }}"><span class="stay-visually-hidden">{{ $star }} star{{ $star === 1 ? '' : 's' }}</span>&#9733;</label>@endfor</div>
            </fieldset>
            <div class="stay-field"><label for="review-comment">Tell us about your stay *</label><textarea id="review-comment" name="comment" rows="5" required minlength="10" maxlength="2000" placeholder="What did you enjoy? Was anything missing?">{{ old('comment') }}</textarea></div>
            <div class="stay-field"><label for="review-name">Your name *</label><input id="review-name" name="display_name" required minlength="2" maxlength="100" autocomplete="name" value="{{ old('display_name', $review->guest_name) }}"><small class="stay-help">We show your first name and last initial, e.g. "{{ \App\Services\PublicReviews::displayName($review->guest_name) }}".</small></div>
            <label class="stay-check stay-field"><input type="checkbox" name="consent" value="1" required @checked(old('consent'))><span>{{ $tenant->name }} may show my rating, review and name on its website.</span></label>
            <button type="submit" class="stay-button wide">Send my review &rarr;</button>
        </form>
    @endif
</div>
@endsection
