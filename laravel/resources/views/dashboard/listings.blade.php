@php
    $tenantSlug = app('currentTenant')->slug;
    $cleanupPercent = $listingCleanup['total'] > 0 ? (int) round($listingCleanup['optimised'] / $listingCleanup['total'] * 100) : 0;
    $todoCount = $listingCleanup['total'] - $listingCleanup['optimised'];
@endphp
<section aria-labelledby="listing-cleanup-heading" class="mb-4 hs-listing-cleanup" data-listing-cleanup>
    <div class="hs-section-head">
        <div><h2 id="listing-cleanup-heading">Listing clean-up</h2><p class="mb-0 mt-1">Rename each listing, complete its details, then tick it as optimised. The quality score shows what is still missing.</p></div>
        <a href="{{ route('public-listings.index', $tenantSlug) }}?needs_work=1">Open listings that need work &rarr;</a>
    </div>
    <div class="hs-work-panel">
        <p class="mb-2"><strong>{{ $listingCleanup['optimised'] }} of {{ $listingCleanup['total'] }} listings optimised</strong> ({{ $cleanupPercent }}%)</p>
        <progress max="100" value="{{ $cleanupPercent }}" aria-label="Listings optimised">{{ $cleanupPercent }}%</progress>
        @if($listingCleanup['total'] === 0)
            <p class="hs-empty">No listings yet. <a href="{{ route('public-listings.create', $tenantSlug) }}">Create your first listing &rarr;</a></p>
        @else
            <div class="hs-cleanup-filters" role="group" aria-label="Show listings">
                <button type="button" class="btn btn-sm btn-primary" data-cleanup-filter="todo" aria-pressed="true">To do ({{ $todoCount }})</button>
                <button type="button" class="btn btn-sm btn-outline-primary" data-cleanup-filter="done" aria-pressed="false">Optimised ({{ $listingCleanup['optimised'] }})</button>
                <button type="button" class="btn btn-sm btn-outline-primary" data-cleanup-filter="all" aria-pressed="false">All ({{ $listingCleanup['total'] }})</button>
            </div>
            <ul class="hs-cleanup-list">
                @foreach($listingCleanup['rows'] as $row)
                    @php($item = $row['listing'])
                    @php($isDone = $item->optimised_at !== null)
                    <li id="cleanup-listing-{{ $item->id }}" class="hs-cleanup-row {{ $isDone ? 'is-done' : '' }}" data-cleanup-state="{{ $isDone ? 'done' : 'todo' }}">
                        <form method="post" action="{{ route('public-listings.optimised', ['tenant' => $tenantSlug, 'id' => $item->id]) }}" class="hs-cleanup-tick">
                            @csrf
                            <input type="hidden" name="optimised" value="{{ $isDone ? 0 : 1 }}">
                            <button type="submit" role="checkbox" aria-checked="{{ $isDone ? 'true' : 'false' }}" aria-label="{{ $isDone ? 'Optimised. Untick to move back to the to-do list' : 'Mark as optimised' }}: {{ $item->title }}"><span aria-hidden="true">{{ $isDone ? '✓' : '' }}</span></button>
                        </form>
                        <div class="hs-cleanup-body">
                            <a class="hs-cleanup-title" href="{{ route('public-listings.edit', ['tenant' => $tenantSlug, 'id' => $item->id]) }}">{{ $item->title }}</a>
                            <span class="hs-status {{ $row['score'] >= 100 ? 'clear' : ($row['score'] < 60 ? 'attention' : '') }}">Quality {{ $row['score'] }}%</span>
                            @if($item->status !== 'published')<span class="hs-status">{{ ucfirst($item->status) }}</span>@endif
                            <p>
                                @if($isDone)
                                    Optimised by {{ $row['optimised_by'] ?? 'staff' }}, {{ \Illuminate\Support\Carbon::parse($item->optimised_at)->timezone(app('currentTenant')->timezone ?: config('app.timezone'))->format('j M Y') }}.@if($row['todo'] !== []) Still missing: {{ implode(', ', $row['todo']) }}.@endif
                                @else
                                    @if($row['duplicate_of'])<strong>Possible duplicate of {{ $row['duplicate_of']->title }}.</strong> @endif
                                    {{ $row['todo'] === [] ? 'All checks done. Review it and tick it as optimised.' : 'To do: '.implode(', ', $row['todo']).'.' }}
                                @endif
                            </p>
                        </div>
                    </li>
                @endforeach
            </ul>
            <p class="hs-empty" data-cleanup-empty hidden>Nothing here yet.</p>
        @endif
    </div>
</section>
<style>
.hs-listing-cleanup progress{margin-bottom:14px}
.hs-cleanup-filters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:6px}.hs-cleanup-filters .btn{min-height:36px}
.hs-cleanup-list{list-style:none;margin:0;padding:0;max-height:560px;overflow:auto}
.hs-cleanup-row{display:flex;gap:14px;align-items:flex-start;padding:12px 4px;border-bottom:1px solid #e6ebf2}.hs-cleanup-row:last-child{border-bottom:0}
.hs-cleanup-tick button{width:28px;height:28px;margin-top:2px;border:2px solid #294780;border-radius:6px;background:#fff;color:#fff;font-weight:800;line-height:1;padding:0;display:flex;align-items:center;justify-content:center;cursor:pointer}
.hs-cleanup-tick button:hover{background:#f0f5ff}.hs-cleanup-row.is-done .hs-cleanup-tick button{background:#20573d;border-color:#20573d}
.hs-cleanup-body{min-width:0;flex:1}.hs-cleanup-title{font-weight:700;margin-right:6px;overflow-wrap:anywhere}.hs-cleanup-body .hs-status{margin-right:4px}.hs-cleanup-body p{margin:5px 0 0;font-size:.8rem}
.hs-cleanup-row.is-done .hs-cleanup-title{color:#20573d}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const card = document.querySelector('[data-listing-cleanup]');
    if (!card) return;
    const buttons = Array.from(card.querySelectorAll('[data-cleanup-filter]'));
    const rows = Array.from(card.querySelectorAll('[data-cleanup-state]'));
    const empty = card.querySelector('[data-cleanup-empty]');
    let current = 'todo';
    try { current = sessionStorage.getItem('haven.listingCleanup.filter') || 'todo'; } catch (e) {}
    function show(filter) {
        let visible = 0;
        rows.forEach(row => { row.hidden = filter !== 'all' && row.dataset.cleanupState !== filter; if (!row.hidden) visible++; });
        buttons.forEach(button => { const active = button.dataset.cleanupFilter === filter; button.setAttribute('aria-pressed', active ? 'true' : 'false'); button.classList.toggle('btn-primary', active); button.classList.toggle('btn-outline-primary', !active); });
        if (empty) empty.hidden = visible > 0;
        try { sessionStorage.setItem('haven.listingCleanup.filter', filter); } catch (e) {}
    }
    buttons.forEach(button => button.addEventListener('click', () => show(button.dataset.cleanupFilter)));
    show(current);
});
</script>
