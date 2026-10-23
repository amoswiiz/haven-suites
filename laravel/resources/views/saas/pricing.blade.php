@extends('saas.layout')
@section('title','Plans for your hosting business')
@section('content')
<h1>One workspace for your hosting business</h1><p class="muted">Manage your reservations, guests, housekeeping, and team. Start with a {{ config('saas.trial_days') }}-day Starter trial.</p>
<div class="grid">@foreach(config('saas.plans') as $key=>$plan)<section class="card"><h2>{{ $plan['name'] }}</h2><p class="price">KES {{ number_format(config('billing.prices_minor.'.$key)/100) }}<small class="muted"> / month</small></p><ul class="checklist"><li>Up to {{ $plan['units'] }} apartments</li><li>Up to {{ $plan['members'] }} company members</li><li>Reservations and availability calendar</li><li>Guest, housekeeping, and finance workflows</li><li>A separate company workspace</li></ul><a class="button" href="{{ route('register') }}">Start free trial</a></section>@endforeach</div>
<p class="muted">No card is required for the trial. Live payment collection is not yet enabled; payment integrations are available for sandbox testing.</p>
@endsection
