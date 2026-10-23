@extends('saas.layout')
@section('title','Company invitation')
@section('content')
<section class="card narrow"><h1>Join {{ $invitation->tenant->name }}</h1><p>You have been invited as <strong>{{ ucfirst($invitation->role) }}</strong> using <strong>{{ $invitation->email }}</strong>.</p>
@if(strtolower(auth()->user()->email)!==strtolower($invitation->email))<p class="alert error">You are signed in as {{ auth()->user()->email }}. Sign out and use the invited email address.</p>@else
<form method="post" action="{{ route('invitations.accept',$token) }}">@csrf<button>Accept invitation</button></form>@endif
<p class="muted">Expires {{ $invitation->expires_at->format('j M Y') }}.</p></section>
@endsection
