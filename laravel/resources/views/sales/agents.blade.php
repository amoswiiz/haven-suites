@extends('saas.layout')
@section('title','Referral agents')
@section('content')
@php($canManageAgents = auth()->user()->can('agents.manage'))
<h1>Referral agents</h1><p class="muted">Referral commission terms are separate from partner-property payouts. Recording terms does not create a payment obligation.</p>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Agent</th><th>Contact</th><th>WhatsApp</th><th>Commission terms</th>@if($canManageAgents)<th>Actions</th>@endif</tr></thead><tbody>
@forelse($agents as $agent)
    <tr>
        <td>{{ $agent->name }}@if(!$agent->active)<br><span class="muted">Inactive</span>@endif</td>
        <td>@if($agent->phone)<a href="tel:{{ preg_replace('/[^+0-9]/', '', $agent->phone) }}">{{ $agent->phone }}</a>@endif @if($agent->email)<br><a href="mailto:{{ $agent->email }}">{{ $agent->email }}</a>@endif</td>
        <td>@if($agent->whatsapp_url)<a href="{{ $agent->whatsapp_url }}" target="_blank" rel="noopener noreferrer" aria-label="Chat with {{ $agent->name }} on WhatsApp">Chat on WhatsApp &nearr;</a>@else<span class="muted">No mobile number</span>@endif</td>
        <td>{{ $agent->commission_terms ?: 'Not agreed' }}</td>
        @if($canManageAgents)<td><a href="{{ route('sales.agent.edit', [app('currentTenant')->slug, $agent->id]) }}" aria-label="Edit {{ $agent->name }}">Edit</a></td>@endif
    </tr>
@empty
    <tr><td colspan="{{ $canManageAgents ? 5 : 4 }}">No referral agents yet.</td></tr>
@endforelse
</tbody></table></div>{{ $agents->links() }}</section>
@if($canManageAgents)<form class="card" method="post" action="{{ route('sales.agent.store',app('currentTenant')->slug) }}">@csrf<h2>Add agent</h2>
    <label for="name">Agent name</label><input id="name" name="name" required maxlength="150" value="{{ old('name') }}">
    <label for="phone">Phone</label><input id="phone" name="phone" maxlength="50" value="{{ old('phone') }}">
    <label for="whatsapp">WhatsApp number <small class="muted">Leave blank to use the phone number</small></label><input id="whatsapp" name="whatsapp" maxlength="50" value="{{ old('whatsapp') }}" placeholder="0712 345 678">
    <label for="email">Email</label><input type="email" id="email" name="email" value="{{ old('email') }}">
    <label for="terms">Commission terms, payable trigger and cancellation policy</label><textarea id="terms" name="commission_terms" rows="4" maxlength="2000">{{ old('commission_terms') }}</textarea>
    <div class="actions"><button>Save agent</button></div></form>@endif
@endsection
