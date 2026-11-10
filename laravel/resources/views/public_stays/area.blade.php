@extends('public_stays.layout')
@section('content')
@php($placeName = $place['area'] ? $place['area'].', '.$place['city'] : $place['city'])
<section class="stay-about-hero">
    <div class="stay-container">
        <p class="stay-breadcrumb"><a href="{{ route('public-stays.index', $tenant->slug) }}">All stays</a> &nbsp;/&nbsp; @if($place['area'])<a href="{{ $areaGuides->url($tenant, $place['city']) }}">{{ $place['city'] }}</a> &nbsp;/&nbsp; {{ $place['area'] }}@else{{ $place['city'] }}@endif</p>
        <span class="stay-eyebrow">{{ $place['area'] ? 'Neighbourhood guide' : 'Destination' }}</span>
        <h1>Stays in {{ $placeName }}</h1>
        @foreach($guide['intro'] as $paragraph)<p>{{ $paragraph }}</p>@endforeach
        @if($guide['highlights'])<ul class="stay-area-highlights">@foreach($guide['highlights'] as $highlight)<li>{{ $highlight }}</li>@endforeach</ul>@endif
    </div>
</section>
<div class="stay-container stay-section">
    @if(!$place['area'] && count($place['areas']) > 1)
        <nav class="stay-collections" aria-label="Neighbourhoods in {{ $place['city'] }}">@foreach($place['areas'] as $area)@if(\Illuminate\Support\Str::slug($area['name']) !== '')<a class="stay-chip" href="{{ $areaGuides->url($tenant, $place['city'], $area['name']) }}">{{ $area['name'] }} <span>{{ $area['count'] }}</span></a>@endif @endforeach</nav>
    @elseif($place['area'] && count($place['areas']) > 1)
        <nav class="stay-collections" aria-label="Other neighbourhoods in {{ $place['city'] }}">@foreach($place['areas'] as $area)@if($area['name'] !== $place['area'] && \Illuminate\Support\Str::slug($area['name']) !== '')<a class="stay-chip" href="{{ $areaGuides->url($tenant, $place['city'], $area['name']) }}">{{ $area['name'] }} <span>{{ $area['count'] }}</span></a>@endif @endforeach</nav>
    @endif
    <div class="stay-results-head"><div><h2>{{ $stays->count() }} {{ $stays->count() === 1 ? 'stay' : 'stays' }} in {{ $placeName }}</h2><p>Prices shown are starting nightly rates. Choose dates on an apartment to see a price estimate.</p></div></div>
    <div class="stay-grid">@foreach($stays as $stay)@include('public_stays.card')@endforeach</div>
    <div class="stay-empty" style="margin-top:40px"><h2>Can't find the right place in {{ $place['area'] ?? $place['city'] }}?</h2><p>Tell us your dates and budget. We often know of apartments that are not listed yet.</p><div class="stay-track-actions" style="justify-content:center"><a class="stay-button secondary" href="{{ route('public-stays.index', $tenant->slug) }}#find-stay">Search all stays</a>@if(!empty($websiteContactLinks['whatsapp_url']))<a class="stay-button" href="{{ $websiteContactLinks['whatsapp_url'] }}" target="_blank" rel="noopener noreferrer">Ask us on WhatsApp <span aria-hidden="true">&nearr;</span></a>@endif</div></div>
</div>
@endsection
