@extends('saas.layout')
@section('title','Investor reports')
@section('content')
<h1>Investor reports</h1><p class="muted">Filter your published summaries and download the same selection.</p>
<section class="card"><form method="get" action="{{ route('investor.reports',app('currentTenant')->slug) }}"><div class="grid">
<div><label for="period">Report period</label><select id="period" name="period"><option value="monthly" @selected($period==='monthly')>Monthly</option><option value="yearly" @selected($period==='yearly')>Yearly</option></select></div>
<div><label for="month">Month (monthly report)</label><input id="month" type="month" name="month" value="{{ $month }}" required></div>
<div><label for="year">Year (yearly report)</label><input id="year" type="number" name="year" min="2000" max="2100" value="{{ $year }}" required></div>
<div><label for="unit">Unit</label><select id="unit" name="unit_id"><option value="0">All your units</option>@foreach($units as $unit)<option value="{{ $unit->id }}" @selected($unitId==$unit->id)>{{ $unit->property_name }} / {{ $unit->unit_number }}</option>@endforeach</select></div>
</div><div class="actions"><button>Show report</button></div></form></section>
<section class="card"><div class="actions"><a class="button" href="{{ route('investor.reports',[app('currentTenant')->slug,...$filters,'download'=>'pdf']) }}">Download PDF</a><a class="button" href="{{ route('investor.reports',[app('currentTenant')->slug,...$filters,'download'=>'csv']) }}">Download CSV</a></div>@include('investors.report_content')</section>
@endsection
