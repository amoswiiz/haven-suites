@php
    $checks = \App\Services\ListingDetails::checklist($listing, $photos, $quality['context'] ?? []);
    $doneCount = collect($checks)->where('done', true)->count();
    $score = (int) round($doneCount / max(1, count($checks)) * 100);
    $duplicateOf = $quality['duplicate_of'] ?? null;
@endphp
<details class="card listing-quality" @if($score < 100 || $duplicateOf) open @endif>
    <summary><strong>Listing quality: {{ $score }}%</strong> <span class="muted">{{ $doneCount }} of {{ count($checks) }} done</span>
        <span class="listing-quality-bar" aria-hidden="true"><span style="width:{{ $score }}%"></span></span></summary>
    <p class="muted">Complete listings get more enquiries and rank higher on Google. Each item below is saved with this form.</p>
    @if($duplicateOf)
        <p class="listing-quality-duplicate" role="status"><strong>Possible duplicate:</strong> this may be the same apartment as <a href="{{ route('public-listings.edit', ['tenant' => app('currentTenant')->slug, 'id' => $duplicateOf->id]) }}">{{ $duplicateOf->title }}</a>. If it is, keep the better listing and archive the other.</p>
    @endif
    <ul class="listing-quality-list">
        @foreach($checks as $check)
            <li class="{{ $check['done'] ? 'is-done' : '' }}"><span aria-hidden="true">{{ $check['done'] ? '✓' : '○' }}</span> <strong>{{ $check['label'] }}</strong>@unless($check['done'])<small class="muted">{{ $check['tip'] }}</small>@endunless<span class="sr-only">{{ $check['done'] ? ' (done)' : ' (to do)' }}</span></li>
        @endforeach
    </ul>
</details>
