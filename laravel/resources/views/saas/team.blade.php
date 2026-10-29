@extends('saas.layout')
@section('title','Your team')
@section('content')
<h1>Your team</h1><p class="muted">Give each person their own login and the access they need.</p>
<div class="grid"><section class="card"><h2>Invite a team member</h2><form method="post" action="{{ route('company.invite',app('currentTenant')->slug) }}">@csrf
<label for="email">Email address</label><input id="email" type="email" name="email" value="{{ old('email') }}" required>
<label for="role">Company role</label>
<select id="role" name="role">
    @foreach(config('saas.invitable_roles') as $role)
        @if($role!=='admin'||auth()->user()->inGroup('superadmin'))
            <option value="{{ $role }}">{{ ucfirst($role) }}</option>
        @endif
    @endforeach
</select>
<p class="muted">Owner is an investor role within this company. An independent host should register their own company.</p><button>Send invitation</button></form></section>
<section class="card"><h2>Members</h2><div class="table-wrap"><table>
<thead><tr><th>Person</th><th>Role</th><th>Action</th></tr></thead>
<tbody>
@foreach($members as $member)
@php($memberRoles = json_decode($member->pivot->roles, true) ?: [])
<tr>
    <td>{{ $member->name }}<br><small>{{ $member->email }}</small></td>
    <td>{{ implode(', ', $memberRoles) }}</td>
    <td>
        @if(!session()->has('haven.impersonation')
            && auth()->user()->active
            && (auth()->user()->is_platform_admin || auth()->user()->inGroup('superadmin'))
            && (int) $member->id !== (int) auth()->id()
            && $member->active
            && !$member->is_platform_admin
            && (!$member->requires_email_verification || $member->hasVerifiedEmail())
            && (auth()->user()->is_platform_admin || !in_array('superadmin', $memberRoles, true)))
            <a href="{{ url('/impersonation').'?'.http_build_query(['company' => app('currentTenant')->id, 'user' => $member->id]) }}" aria-label="Log in as {{ $member->name }}">Log in as</a>
        @else
            <span class="muted">&mdash;</span>
        @endif
    </td>
</tr>
@endforeach
</tbody></table></div></section></div>
<section class="card"><h2>Invitations</h2><div class="table-wrap"><table><thead><tr><th>Email</th><th>Role</th><th>Status</th><th>Action</th></tr></thead><tbody>@forelse($invitations as $invitation)<tr><td>{{ $invitation->email }}</td><td>{{ $invitation->role }}</td><td>{{ $invitation->accepted_at?'Accepted':($invitation->revoked_at?'Revoked':($invitation->expires_at->isPast()?'Expired':'Pending')) }}</td><td>@if(!$invitation->accepted_at&&!$invitation->revoked_at)<form method="post" action="{{ route('company.invite.revoke',[app('currentTenant')->slug,$invitation->id]) }}">@csrf<button class="secondary">Revoke</button></form>@endif</td></tr>@empty<tr><td colspan="4">No invitations sent yet.</td></tr>@endforelse</tbody></table></div></section>
<section class="card"><h2>Manage member access</h2><p class="muted">Role changes apply to this company only. Removing access preserves historical records.</p>
@foreach($members as $member)
@php($memberRoles=json_decode($member->pivot->roles,true))
@if($member->id!==auth()->id()&&!in_array('superadmin',$memberRoles,true)&&(!in_array('admin',$memberRoles,true)||auth()->user()->inGroup('superadmin')))
<div class="card"><strong>{{ $member->name }}</strong><form method="post" action="{{ route('company.member.update',[app('currentTenant')->slug,$member->id]) }}">@csrf<label for="member-role-{{ $member->id }}">Role</label>
<select id="member-role-{{ $member->id }}" name="role">
    @foreach(config('saas.invitable_roles') as $role)
        @if($role!=='admin'||auth()->user()->inGroup('superadmin'))
            <option value="{{ $role }}" @selected(in_array($role,$memberRoles,true))>{{ ucfirst($role) }}</option>
        @endif
    @endforeach
</select><div class="actions"><button>Update role</button></div></form>
<form method="post" action="{{ route('company.member.remove',[app('currentTenant')->slug,$member->id]) }}" onsubmit="return confirm('Remove this person’s access to this company?')">@csrf<div class="actions"><button class="danger">Remove company access</button></div></form></div>
@endif
@endforeach</section>
@endsection
