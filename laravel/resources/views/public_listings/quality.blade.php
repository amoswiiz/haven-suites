@php
    $checks = \App\Services\ListingDetails::checklist($listing, $photos);
    $doneCount = collect($checks)->where('done', true)->count();
    $score = (int) round($doneCount / max(1, count($checks)) * 100);
@endphp
<details class="card listing-quality" @if($score < 100) open @endif>
    <summary><strong>Listing quality: {{ $score }}%</strong> <span class="muted">{{ $doneCount }} of {{ count($checks) }} done</span>
        <span class="listing-quality-bar" aria-hidden="true"><span style="width:{{ $score }}%"></span></span></summary>
    <p class="muted">Complete listings get more enquiries. Each item below is saved with this form.</p>
    <ul class="listing-quality-list">
        @foreach($checks as $check)
            <li class="{{ $check['done'] ? 'is-done' : '' }}"><span aria-hidden="true">{{ $check['done'] ? '✓' : '○' }}</span> <strong>{{ $check['label'] }}</strong>@unless($check['done'])<small class="muted">{{ $check['tip'] }}</small>@endunless<span class="sr-only">{{ $check['done'] ? ' (done)' : ' (to do)' }}</span></li>
        @endforeach
    </ul>
</details>
