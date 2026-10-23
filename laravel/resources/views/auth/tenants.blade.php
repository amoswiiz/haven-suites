@extends('saas.layout')
@section('title','Your companies')
@section('content')<h1>Your companies</h1><div class="grid">@forelse($tenants as $tenant)<section class="card"><h2>{{ $tenant->name }}</h2><p class="muted">{{ $tenant->slug }}</p><a class="button" href="{{ url('/t/'.$tenant->slug.'/home') }}">Open workspace</a></section>@empty<section class="card"><p>No active company is available. Contact your company administrator if your workspace has been suspended.</p></section>@endforelse</div>@endsection
