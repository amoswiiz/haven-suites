@extends('saas.layout')
@section('title', 'Change password')
@section('content')
<section class="card narrow" aria-labelledby="password-heading">
    <h1 id="password-heading">Change your password</h1>
    <p class="muted">Choose a new password for your account. This applies to every company you access with this login.</p>
    <form method="post" action="{{ route('account.password.update') }}">
        @csrf
        @method('PUT')
        <label for="current_password">Current password</label>
        <input id="current_password" name="current_password" type="password" autocomplete="current-password" maxlength="128" required>
        <label for="password">New password</label>
        <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="128" aria-describedby="password-requirements" required>
        <p class="muted" id="password-requirements">Use at least 12 characters, including uppercase and lowercase letters and a number. Choose a different password from your current one.</p>
        <label for="password_confirmation">Confirm new password</label>
        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="128" required>
        <div class="actions"><button type="submit">Save new password</button><a href="{{ url('/') }}">Back to my workspace</a></div>
    </form>
</section>
@endsection
