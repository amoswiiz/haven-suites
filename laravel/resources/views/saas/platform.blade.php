@extends('saas.layout')
@section('title','Platform administration')
@section('content')<h1>Platform administration</h1><p class="muted">Manage company access and subscriptions. This role does not grant access to company operational records.</p>
<div class="actions"><a class="button" href="{{ route('platform.company.create') }}">Create company</a></div>
@foreach($companies as $company)<section class="card"><div class="split"><div><h2>{{ $company->name }}</h2><p>{{ $company->slug }} · {{ $company->users_count }} members</p>@if($company->requested_plan)<span class="pill">Requested {{ ucfirst($company->requested_plan) }}</span>@endif</div><span class="pill">{{ $company->active?'Enabled':'Suspended' }}</span></div>
@foreach($administratorInvitations->where('tenant_id',$company->id) as $invitation)<p>Administrator invitation: {{ $invitation->email }} &middot; {{ $invitation->revoked_at ? 'Revoked' : ($invitation->expires_at->isPast() ? 'Expired' : 'Awaiting acceptance') }}</p><form method="post" action="{{ route('platform.invitation.resend',$invitation->id) }}">@csrf<button class="secondary">Resend invitation</button></form>@endforeach
<form method="post" action="{{ route('platform.company.update',$company->id) }}">@csrf<div class="grid">
<div><label for="access-{{ $company->id }}">Access</label><select name="active" id="access-{{ $company->id }}"><option value="1" @selected($company->active)>Enabled</option><option value="0" @selected(!$company->active)>Suspended</option></select></div>
<div><label for="plan-{{ $company->id }}">Plan</label><select name="plan" id="plan-{{ $company->id }}">@foreach(['legacy',...array_keys(config('saas.plans'))] as $plan)<option @selected($company->plan===$plan)>{{ $plan }}</option>@endforeach</select></div>
<div><label for="status-{{ $company->id }}">Subscription status</label><select name="subscription_status" id="status-{{ $company->id }}">@foreach(['legacy','trial','active','expired'] as $status)<option @selected($company->subscription_status===$status)>{{ $status }}</option>@endforeach</select></div>
<div><label for="until-{{ $company->id }}">Paid access until</label><input type="date" name="subscription_ends_at" id="until-{{ $company->id }}" value="{{ $company->subscription_ends_at?->format('Y-m-d') }}"></div><div><label for="trial-{{ $company->id }}">Trial ends</label><input type="date" name="trial_ends_at" id="trial-{{ $company->id }}" value="{{ $company->trial_ends_at?->format('Y-m-d') }}"></div></div><div class="actions"><button>Save company access</button></div></form>
<details style="margin-top:24px"><summary>Company modules</summary>
<p class="muted">Disabling a module hides its navigation and blocks access. Existing records remain saved. Only platform administrators can change these switches. Messaging activation does not connect any external account.</p>
<form method="post" action="{{ route('platform.company.modules',$company->id) }}">
@csrf
<div class="grid">
@foreach(\App\Support\CompanyModules::LABELS as $key => $label)
<label style="display:flex;align-items:center;gap:8px"><input style="width:auto" type="checkbox" name="modules[]" value="{{ $key }}" @checked($company->modules[$key] ?? true)> {{ $label }}</label>
@endforeach
</div>
<label for="module-reason-{{ $company->id }}">Reason for changing modules</label><input id="module-reason-{{ $company->id }}" name="reason" maxlength="500" required>
<div class="actions"><button>Save company modules</button></div>
</form>
<h3>Recent module changes</h3>
@forelse($moduleEvents->where('tenant_id',$company->id) as $event)
<p><strong>{{ $event->actor_name }}</strong> &middot; {{ $event->created_at }}<br>{{ json_decode($event->details,true)['reason'] ?? '' }}</p>
@empty
<p class="muted">No module changes recorded.</p>
@endforelse
</details></section>
@endforeach
{{ $companies->links() }}
<section class="card"><h2>Sandbox manual invoices awaiting review</h2><p class="muted">These are test invoices. Approval only simulates a paid subscription.</p>@forelse($payments as $payment)<div><strong>{{ $payment->tenant->name }}</strong> · {{ $payment->reference }} · KES {{ number_format($payment->amount_minor/100,2) }}<p>Submitted reference: {{ $payment->metadata['submitted_reference']??'None' }}</p><form method="post" action="{{ route('platform.payment.approve',$payment->id) }}">@csrf<label for="approve-{{ $payment->id }}">Verified test reference</label><input id="approve-{{ $payment->id }}" name="transaction_reference" required maxlength="100"><div class="actions"><button>Approve sandbox invoice</button></div></form></div>@empty<p>No invoices awaiting review.</p>@endforelse</section>
@endsection
