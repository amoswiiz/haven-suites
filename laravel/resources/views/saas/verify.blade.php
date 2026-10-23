@extends('saas.layout')
@section('title','Verify your email')
@section('content')
<div class="card narrow"><h1>Check your email</h1><p>We sent a verification link to <strong>{{ auth()->user()->email }}</strong>. Verify your address before creating or joining a company.</p>
@if(auth()->user()->hasVerifiedEmail())<a class="button" href="{{ url('/') }}">Continue</a>@else
<form method="post" action="{{ route('verification.send') }}">@csrf<button>Resend verification email</button></form>@endif</div>
@endsection
