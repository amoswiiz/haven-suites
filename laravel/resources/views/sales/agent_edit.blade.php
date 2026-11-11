@extends('saas.layout')
@section('title','Edit referral agent')
@section('content')
@php($slug = app('currentTenant')->slug)
<p><a href="{{ route('sales.agents', $slug) }}">&larr; Referral agents</a></p>
<h1>Edit {{ $agent->name }}</h1>
<form method="post" action="{{ route('sales.agent.update', [$slug, $agent->id]) }}">@csrf
    <x-ui.card title="Agent details">
        <x-ui.field name="name" label="Agent name" :value="$agent->name" required maxlength="150" />
        <x-ui.field name="phone" label="Phone" :value="$agent->phone" maxlength="50" />
        <x-ui.field name="whatsapp" label="WhatsApp number" :value="$agent->whatsapp" maxlength="50" placeholder="0712 345 678" hint="Leave blank to use the phone number." />
        <x-ui.field name="email" label="Email" type="email" :value="$agent->email" />
        <x-ui.field name="commission_terms" label="Commission terms, payable trigger and cancellation policy" type="textarea" rows="4" maxlength="2000" :value="$agent->commission_terms" />
        <x-ui.field name="active" label="Active — can be chosen on new leads" type="checkbox" :checked="(bool) $agent->active" />
        <x-ui.field name="broadcast_opt_out" label="Does not want broadcast SMS (availability and offers)" type="checkbox" :checked="(bool) ($agent->broadcast_opt_out ?? false)" />
        <div class="ui-actions"><x-ui.button>Save changes</x-ui.button><x-ui.button :href="route('sales.agents', $slug)" variant="secondary">Cancel</x-ui.button></div>
    </x-ui.card>
</form>
@endsection
