@extends('saas.layout')
@section('title','Create your company')
@section('content')
<div class="card narrow"><span class="pill">Step 1 of 2</span><h1>Create your company</h1><p class="muted">Your company's properties, guests, and bookings will be kept separate from other businesses.</p>
<form method="post" action="{{ route('onboarding.provision') }}">@csrf
<label for="name">Company or hosting business name</label><input id="name" name="name" value="{{ old('name') }}" required maxlength="150">
<label for="slug">Workspace address</label><input id="slug" name="slug" value="{{ old('slug') }}" pattern="[a-z0-9]+(-[a-z0-9]+)*" minlength="3" maxlength="50" placeholder="your-company" required><small class="muted">Lowercase letters, numbers, and hyphens. Your workspace will use /t/your-company.</small>
<div class="actions"><button>Create workspace</button></div></form><p class="muted">If you were invited to an existing company, open your invitation link instead.</p></div>
@endsection
