@extends('saas.layout')
@section('title','Create your account')
@section('content')
<div class="card narrow"><h1>Your properties. Your workspace.</h1><p class="muted">Create an account to manage bookings, guests, and your team. Start with a {{ config('saas.trial_days') }}-day trial. No payment is collected during signup.</p>
<form method="post" action="{{ route('register') }}">@csrf
<label for="name">Your full name</label><input id="name" name="name" value="{{ old('name') }}" autocomplete="name" required maxlength="150">
<label for="email">Email address</label><input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required>
<label for="password">Password</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required><small class="muted">At least 12 characters, including upper and lower case letters and a number.</small>
<label for="password_confirmation">Confirm password</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
<div class="actions"><button>Create account</button><a href="{{ url('/login') }}">Already registered? Sign in</a></div></form></div>
@endsection
