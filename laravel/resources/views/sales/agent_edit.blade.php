@extends('saas.layout')
@section('title','Edit referral agent')
@section('content')
<p><a href="{{ route('sales.agents', app('currentTenant')->slug) }}">&larr; Referral agents</a></p>
<h1>Edit {{ $agent->name }}</h1>
<form class="card" method="post" action="{{ route('sales.agent.update', [app('currentTenant')->slug, $agent->id]) }}">@csrf
    <label for="name">Agent name</label><input id="name" name="name" required maxlength="150" value="{{ old('name', $agent->name) }}">
    <label for="phone">Phone</label><input id="phone" name="phone" maxlength="50" value="{{ old('phone', $agent->phone) }}">
    <label for="whatsapp">WhatsApp number <small class="muted">Leave blank to use the phone number</small></label><input id="whatsapp" name="whatsapp" maxlength="50" value="{{ old('whatsapp', $agent->whatsapp) }}" placeholder="0712 345 678">
    <label for="email">Email</label><input type="email" id="email" name="email" value="{{ old('email', $agent->email) }}">
    <label for="terms">Commission terms, payable trigger and cancellation policy</label><textarea id="terms" name="commission_terms" rows="4" maxlength="2000">{{ old('commission_terms', $agent->commission_terms) }}</textarea>
    <input type="hidden" name="active" value="0">
    <label><input style="width:auto" type="checkbox" name="active" value="1" @checked(old('active', $agent->active))> Active &mdash; can be chosen on new leads</label>
    <input type="hidden" name="broadcast_opt_out" value="0">
    <label><input style="width:auto" type="checkbox" name="broadcast_opt_out" value="1" @checked(old('broadcast_opt_out', $agent->broadcast_opt_out ?? false))> Does not want broadcast SMS (availability and offers)</label>
    <div class="actions"><button>Save changes</button> <a href="{{ route('sales.agents', app('currentTenant')->slug) }}">Cancel</a></div>
</form>
@endsection
