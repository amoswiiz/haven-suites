@extends('saas.layout')
@section('title','Investor statement')
@section('content')
<section class="card"><h1>{{ app('currentTenant')->name }} / Investor statement</h1><p>{{ $snapshot['property'] }} &middot; {{ substr($statement->period_month,0,7) }} &middot; Revision {{ $statement->revision }}</p><p>Ownership share: {{ $snapshot['share'] }}% &middot; Published {{ $statement->published_at }}</p>
@if($statement->superseded_at)<p class="alert error">This statement was superseded by a later revision.</p>@endif
@if($statement->correction_reason)<p><strong>Correction explanation:</strong> {{ $statement->correction_reason }}</p>@endif
@if(($snapshot['arrangement']??'legacy')==='leased')<p class="muted">Landlord statement: fixed rent owed and recorded rent payments. Company operating profit or loss is separate.</p>@elseif(($snapshot['accounting_policy']??'legacy-approved')==='company-funded-v2')<p class="muted">Managed-owner statement: company-funded operating expenses do not reduce your entitlement.</p>@else<p class="muted">This statement retains its original approved accounting terms.</p>@endif
<table><caption>Published amounts in {{ $snapshot['currency'] }}, allocated to your ownership share.</caption><tbody>@foreach($snapshot['totals'] as $key=>$value)<tr><th>{{ ucwords(str_replace('_',' ',$key)) }}</th><td>{{ number_format((float)$value,2) }}</td></tr>@endforeach</tbody></table><p class="muted">Closing balance is an accounting balance, not a promise of a scheduled payout. Payout history is shown separately.</p><div class="actions"><a class="button" href="{{ route('investor.statement',[app('currentTenant')->slug,$statement->id,'download'=>1]) }}">Download PDF</a><button class="secondary" onclick="window.print()">Print</button></div></section>
<style>@media print{header,footer,.actions,.hs-sidebar{display:none!important}body.has-company-sidebar main{margin:0!important}.card{box-shadow:none;border:0}}</style>
@endsection
