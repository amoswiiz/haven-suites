@extends('saas.layout')
@section('title','Sales quotation')
@section('content')
<section class="card"><div class="split"><div><h1>{{ app('currentTenant')->name }}</h1><h2>Quotation SQ-{{ $quote->id }}</h2></div><p>Issued {{ substr($quote->created_at,0,10) }}<br>Valid until {{ $quote->valid_until }}</p></div><p>Prepared for <strong>{{ $snapshot['guest'] }}</strong></p><p>{{ $snapshot['check_in'] ?: 'Check-in to confirm' }} &rarr; {{ $snapshot['check_out'] ?: 'Check-out to confirm' }} &middot; {{ $snapshot['guests'] }} guests</p><p style="white-space:pre-wrap">{{ $quote->description }}</p><h2>Total: {{ $snapshot['currency'] }} {{ number_format($quote->amount,2) }}</h2><p class="muted">Subject to availability and booking confirmation. This quotation does not reserve an apartment or confirm payment.</p><div class="actions"><button onclick="window.print()">Print / Save PDF</button><a href="{{ route('sales.show',[app('currentTenant')->slug,$lead->id]) }}">Back to enquiry</a></div></section>
<style>@media print{header,footer,.actions{display:none!important}body{background:white}.card{border:0;box-shadow:none;padding:0}}</style>
@endsection
