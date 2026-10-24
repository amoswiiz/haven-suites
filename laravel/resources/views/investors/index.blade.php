@extends('saas.layout')
@section('title','Investors and property owners')
@section('content')
<div class="split"><div><h1>Investors and property owners</h1><p class="muted">Property assignments control portal access. Statements are visible only after publication.</p></div><a href="{{ route('investor.login') }}">Investor sign-in link</a></div>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Investor</th><th>Contact</th><th>Properties / Latest activity</th><th>Portal access</th><th>Action</th></tr></thead><tbody>
@forelse($investors as $investor)<tr><td>{{ $investor->name }}</td><td>{{ $investor->email }}<br>{{ $investor->phone }}</td><td>{{ $assignments->where('investor_id',$investor->id)->count() }} assignments<br>Statement: {{ $statements->firstWhere('investor_id',$investor->id)?->period_month ?: 'None published' }}<br>Payout: {{ $payouts->firstWhere('investor_id',$investor->id)?->payment_date ?: 'None published' }}</td><td>{{ !$investor->active?'Suspended':($investor->user_id?'Login linked':($invitations->firstWhere('investor_id',$investor->id) ? 'Invitation issued / Check details' : 'Not invited')) }}</td><td><a href="{{ route('investors.show',[app('currentTenant')->slug,$investor->id]) }}">View investor</a></td></tr>@empty<tr><td colspan="5">No investors yet.</td></tr>@endforelse
</tbody></table></div>{{ $investors->links() }}</section>
@if(auth()->user()->can('investors.manage'))
<form method="post" action="{{ route('investors.store',app('currentTenant')->slug) }}" class="card">@csrf<h2>Add investor</h2><div class="grid"><div><label for="name">Full name</label><input id="name" name="name" value="{{ old('name') }}" maxlength="150" required></div><div><label for="email">Email</label><input type="email" id="email" name="email" value="{{ old('email') }}" maxlength="180" required></div><div><label for="phone">Phone, including country code</label><input id="phone" name="phone" value="{{ old('phone') }}" placeholder="+254712345678"></div></div><div class="actions"><button>Create investor</button></div></form>
@endif
@endsection
