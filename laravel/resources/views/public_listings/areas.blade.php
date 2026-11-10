@extends('saas.layout')
@section('title', 'Area pages')
@section('content')
@php($tenantSlug = app('currentTenant')->slug)
<p><a href="{{ route('public-listings.index', $tenantSlug) }}">&larr; Public listings</a></p>
<h1>Area pages</h1>
<p class="muted">Every city and neighbourhood with published stays has its own page, such as "Stays in Kilimani, Nairobi". Add a short local introduction so guests and Google learn what the area is like. Leave it blank to use a standard introduction.</p>
@forelse($destinations as $destination)
    @foreach(array_merge([null], array_column($destination['areas'], 'name')) as $area)
        @php($guide = $guides[$destination['city'].'|'.($area ?? '')] ?? null)
        @continue($area !== null && \Illuminate\Support\Str::slug($area) === '')
        <details class="card" @if($errors->any() && old('city') === $destination['city'] && old('area') === $area) open @endif>
            <summary style="cursor:pointer"><strong>{{ $area ? $area.', '.$destination['city'] : $destination['city'] }}</strong> <span class="pill">{{ $guide && $guide->intro ? 'Custom introduction' : 'Standard introduction' }}</span></summary>
            <p class="muted"><a href="{{ $areaGuides->url(app('currentTenant'), $destination['city'], $area) }}" target="_blank" rel="noopener">{{ $areaGuides->url(app('currentTenant'), $destination['city'], $area) }}</a></p>
            <form method="post" action="{{ route('public-listings.areas.save', $tenantSlug) }}">
                @csrf<input type="hidden" name="city" value="{{ $destination['city'] }}"><input type="hidden" name="area" value="{{ $area }}">
                <label for="intro-{{ $loop->parent->index }}-{{ $loop->index }}">Introduction</label><textarea id="intro-{{ $loop->parent->index }}-{{ $loop->index }}" name="intro" rows="4" maxlength="4000" placeholder="What is it like to stay here? Who is it good for, what is nearby, how far is the airport or the beach?">{{ $guide->intro ?? '' }}</textarea>
                <label for="highlights-{{ $loop->parent->index }}-{{ $loop->index }}">Highlights <small class="muted">one per line</small></label><textarea id="highlights-{{ $loop->parent->index }}-{{ $loop->index }}" name="highlights" rows="3" maxlength="2000" placeholder="10 minutes to Yaya Centre&#10;Quiet, leafy streets">{{ $guide->highlights ?? '' }}</textarea>
                <button type="submit">Save</button>
            </form>
        </details>
    @endforeach
@empty
    <section class="card"><p>Publish a listing first. Area pages appear automatically for every city and neighbourhood with published stays.</p></section>
@endforelse
@endsection
