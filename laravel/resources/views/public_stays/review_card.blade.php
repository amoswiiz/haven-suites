<figure class="stay-review">
    <div class="stay-review-head">@include('public_stays.stars', ['rating' => $review->rating])<span class="stay-review-source{{ $review->source === 'direct' ? ' is-verified' : '' }}">{{ $review->source === 'direct' ? 'Verified stay' : 'From '.$review->source_label }}</span></div>
    <blockquote><p>{{ $review->comment }}</p></blockquote>
    <figcaption><strong>{{ $review->display_name }}</strong>@if($month = \App\Http\Controllers\PublicReviewController::stayMonth($review->stayed_on ?? $review->submitted_at)) <span>&middot; {{ $month }}</span>@endif
        @if(!empty($stayLink))<span>&middot; <a href="{{ route('public-stays.show', [$tenant->slug, $stayLink['slug']]) }}">{{ $stayLink['title'] }}</a></span>@endif
    </figcaption>
    @if(!empty($review->reply))<div class="stay-review-reply"><strong>Reply from {{ $tenant->name }}</strong><p>{{ $review->reply }}</p></div>@endif
</figure>
