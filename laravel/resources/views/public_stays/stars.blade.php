@php($starValue = max(0, min(5, (float) $rating)))
<span class="stay-stars" role="img" aria-label="Rated {{ rtrim(rtrim(number_format($starValue, 1), '0'), '.') }} out of 5"><span class="stay-stars-fill" style="width:{{ $starValue * 20 }}%" aria-hidden="true">&#9733;&#9733;&#9733;&#9733;&#9733;</span><span aria-hidden="true">&#9733;&#9733;&#9733;&#9733;&#9733;</span></span>
