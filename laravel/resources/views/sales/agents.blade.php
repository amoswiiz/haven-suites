@extends('saas.layout')
@section('title','Referral agents')
@section('content')
<h1>Referral agents</h1><p class="muted">Referral commission terms are separate from partner-property payouts. Recording terms does not create a payment obligation.</p>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Agent</th><th>Contact</th><th>Commission terms</th></tr></thead><tbody>@forelse($agents as $agent)<tr><td>{{ $agent->name }}</td><td>{{ $agent->phone }}<br>{{ $agent->email }}</td><td>{{ $agent->commission_terms ?: 'Not agreed' }}</td></tr>@empty<tr><td colspan="3">No referral agents yet.</td></tr>@endforelse</tbody></table></div>{{ $agents->links() }}</section>
@if(auth()->user()->can('agents.manage'))<form class="card" method="post" action="{{ route('sales.agent.store',app('currentTenant')->slug) }}">@csrf<h2>Add agent</h2><label for="name">Agent name</label><input id="name" name="name" required maxlength="150"><label for="phone">Phone</label><input id="phone" name="phone" maxlength="50"><label for="email">Email</label><input type="email" id="email" name="email"><label for="terms">Commission terms, payable trigger and cancellation policy</label><textarea id="terms" name="commission_terms" rows="4" maxlength="2000"></textarea><div class="actions"><button>Save agent</button></div></form>@endif
@endsection
