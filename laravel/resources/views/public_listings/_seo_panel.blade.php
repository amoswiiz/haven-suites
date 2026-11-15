@php
    $colours = ['good' => '#1a7f4b', 'ok' => '#b8650f', 'fix' => '#c0392b'];
    $icons = ['good' => '✓', 'ok' => '!', 'fix' => '✕'];
    $labels = ['good' => 'Good', 'ok' => 'Improve', 'fix' => 'Fix'];
@endphp
<div class="seo-score">
    <div class="seo-score-head"><strong>Search score</strong><span class="seo-score-number" style="color:{{ $colours[$seo['grade']] }}">{{ $seo['score'] }}<small>/100</small></span></div>
    <div class="seo-bar" role="progressbar" aria-label="Search score" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $seo['score'] }}"><span style="width:{{ $seo['score'] }}%;background:{{ $colours[$seo['grade']] }}"></span></div>
    <p class="muted" style="margin:6px 0 0">{{ $seo['grade'] === 'good' ? 'Ready to compete. Keep it fresh by updating facts and links.' : ($seo['grade'] === 'ok' ? 'Good start. Work through the items marked Fix and Improve.' : 'Needs work before it can rank well.') }}</p>
</div>
<div class="seo-snippet" aria-label="Google preview">
    <small class="muted">Google preview</small>
    <span class="seo-snippet-url">{{ $seo['snippet']['url'] }}</span>
    <span class="seo-snippet-title">{{ $seo['snippet']['title'] ?: 'Your post title' }}</span>
    <span class="seo-snippet-description">{{ $seo['snippet']['description'] ?: 'Your search description appears here.' }}</span>
</div>
<dl class="seo-stats">
    <div><dt>Words</dt><dd>{{ number_format($seo['stats']['words']) }}</dd></div>
    <div><dt>Read time</dt><dd>{{ $seo['stats']['reading_minutes'] }} min</dd></div>
    <div><dt>Sections</dt><dd>{{ $seo['stats']['sections'] }}</dd></div>
    <div><dt>Links to your pages</dt><dd>{{ $seo['stats']['internal_links'] }}</dd></div>
    <div><dt>Links to sources</dt><dd>{{ $seo['stats']['external_links'] }}</dd></div>
    <div><dt>Linked from posts</dt><dd>{{ count($seo['stats']['linked_from']) }}</dd></div>
    <div><dt>Photos</dt><dd>{{ $seo['stats']['images'] }}</dd></div>
</dl>
@if($seo['stats']['linked_from'] !== [])
    <details class="seo-linked"><summary>Posts linking here ({{ count($seo['stats']['linked_from']) }})</summary><ul>@foreach($seo['stats']['linked_from'] as $from)<li>{{ $from }}</li>@endforeach</ul></details>
@endif
<ul class="seo-checks">
    @foreach(collect($seo['checks'])->sortBy(fn ($check) => ['fix' => 0, 'ok' => 1, 'good' => 2][$check['status']]) as $check)
        <li><span class="seo-icon" style="background:{{ $colours[$check['status']] }}" aria-hidden="true">{{ $icons[$check['status']] }}</span><span><strong>{{ $check['label'] }}</strong> <span class="seo-status">{{ $labels[$check['status']] }}</span><br><span class="muted">{{ $check['detail'] }}</span></span></li>
    @endforeach
</ul>
