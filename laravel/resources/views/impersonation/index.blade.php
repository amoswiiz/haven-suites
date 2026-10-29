@extends('saas.layout')
@section('title', 'Log in as another user')
@section('content')
<section class="card">
    <h1>Log in as another user</h1>
    <form method="get" action="{{ url('/impersonation') }}">
        <label for="company">Company</label>
        <select id="company" name="company">
            @foreach($companies as $company)
                <option value="{{ $company->id }}" {{ (int) $company->id === (int) $selected->id ? 'selected' : '' }}>{{ $company->name }}</option>
            @endforeach
        </select>
        <button type="submit">Show users</button>
    </form>
    <form method="post" action="{{ url('/impersonation') }}">
        @csrf
        <input type="hidden" name="company" value="{{ $selected->id }}">
        <label for="user">User</label>
        <select id="user" name="user" required>
            <option value="">Select user</option>
            @foreach($members as $member)
                @if(auth()->user()->is_platform_admin || !in_array('superadmin', json_decode($member->pivot->roles, true) ?: [], true))
                    <option value="{{ $member->id }}" {{ (string) old('user', request()->query('user', '')) === (string) $member->id ? 'selected' : '' }}>{{ $member->name }} — {{ $member->email }}</option>
                @endif
            @endforeach
        </select>
        <label for="reason">Reason</label>
        <input id="reason" name="reason" maxlength="300" value="{{ old('reason') }}" required>
        <p>You will view the company using this user's permissions. Saving changes is currently disabled. Use Return to my account to exit.</p>
        <button type="submit">Log in as selected user</button>
    </form>
</section>
@endsection
