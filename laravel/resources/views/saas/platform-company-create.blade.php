@extends('saas.layout')
@section('title','Create company')
@section('content')
<h1>Create company</h1><p class="muted">Set up a workspace and invite its administrator. New companies start a {{ config('saas.trial_days') }}-day trial. No payment is collected here.</p>
<form method="post" action="{{ route('platform.company.store') }}">@csrf
<section class="card"><h2>1. Company details</h2>
<label for="name">Company name</label><input id="name" name="name" value="{{ old('name') }}" maxlength="150" required>
<label for="slug">Company address</label><input id="slug" name="slug" value="{{ old('slug') }}" minlength="3" maxlength="50" pattern="[a-z0-9]+(-[a-z0-9]+)*" placeholder="coastal-homes" required><p class="muted">Use lowercase letters, numbers and hyphens. Example: /t/coastal-homes</p>
<label for="contact_email">Business email (optional)</label><input id="contact_email" name="contact_email" type="email" value="{{ old('contact_email') }}"><label for="contact_phone">Business phone (optional)</label><input id="contact_phone" name="contact_phone" type="tel" maxlength="40" value="{{ old('contact_phone') }}"></section>
<section class="card"><h2>2. Administrator and plan</h2><label for="admin_email">Administrator email</label><input id="admin_email" name="admin_email" type="email" maxlength="180" required value="{{ old('admin_email') }}"><p class="muted">They will register or sign in with this email and accept their invitation. They manage this company; platform access is not granted.</p><label for="plan">Subscription plan</label><select id="plan" name="plan">@foreach(config('saas.plans') as $key=>$plan)<option value="{{ $key }}" @selected(old('plan','starter')===$key)>{{ $plan['name'] }} &middot; {{ $plan['units'] }} apartments / {{ $plan['members'] }} team members</option>@endforeach</select></section>
<section class="card"><h2>3. Enabled modules</h2><p class="muted">You can change these later. Keep Team management enabled so the administrator can join.</p><div class="grid">@foreach(\App\Support\CompanyModules::LABELS as $key=>$label)<label style="display:flex;align-items:center;gap:10px"><input style="width:auto" type="checkbox" name="modules[]" value="{{ $key }}" @checked(in_array($key,old('modules',array_keys(\App\Support\CompanyModules::LABELS))))>{{ $label }}</label>@endforeach</div></section>
<div class="actions"><button>Create company &amp; send invitation</button><a href="{{ route('platform.index') }}">Cancel</a></div>
</form>
@endsection
