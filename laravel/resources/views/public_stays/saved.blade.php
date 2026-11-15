@extends('public_stays.layout')
@section('content')
<div class="stay-container stay-saved" data-saved-page data-saved-base="{{ route('public-stays.saved', $tenant->slug) }}" data-requested="{{ $requested }}">
    <p class="stay-breadcrumb"><a href="{{ route('public-stays.index', $tenant->slug) }}">All stays</a> &nbsp;/&nbsp; Saved</p>
    <h1>Saved stays</h1>
    <p class="stay-muted">Tap ♡ on any stay to save it here. Your list stays on this device only; we don't store it.</p>
    @if($stays->isNotEmpty())
        <div class="stay-grid">@foreach($stays as $stay)@include('public_stays.card')@endforeach</div>
    @else
        <div class="stay-empty" data-saved-empty><h2>No saved stays yet</h2><p>Browse our apartments and tap ♡ on the ones you like to compare them later.</p>
            <div class="stay-track-actions" style="justify-content:center"><a class="stay-button" href="{{ route('public-stays.index', $tenant->slug) }}#find-stay">Find a stay</a></div></div>
    @endif
</div>
@endsection
