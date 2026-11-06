@if($paginator->hasPages())
<nav class="stay-pagination" aria-label="Listing pages">
    @if($paginator->onFirstPage())<span>Previous</span>@else<a href="{{ $paginator->previousPageUrl() }}" rel="prev">&larr; Previous</a>@endif
    <span>Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>
    @if($paginator->hasMorePages())<a href="{{ $paginator->nextPageUrl() }}" rel="next">Next &rarr;</a>@else<span>Next</span>@endif
</nav>
@endif
