@extends('saas.layout')
@section('title','Reset your password')
@section('content')<div class="card narrow"><h1>Forgot your password?</h1><p>Enter your account email to request a reset link.</p><form method="post" action="{{ route('password.email') }}">@csrf<label for="email">Email address</label><input type="email" name="email" id="email" required value="{{ old('email') }}"><div class="actions"><button>Send reset link</button><a href="{{ url('/login') }}">Back to sign in</a></div></form></div>@endsection
