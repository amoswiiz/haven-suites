@extends('saas.layout')
@section('title','Company settings')
@section('content')
<div class="card narrow"><h1>Company settings</h1><p class="muted">{{ app('currentTenant')->slug }} · KES · Africa/Nairobi</p>
<form method="post" action="{{ route('company.settings.update',app('currentTenant')->slug) }}">@csrf
<label for="name">Company name</label><input id="name" name="name" value="{{ old('name',app('currentTenant')->name) }}" maxlength="150" required>
<label for="contact_email">Business email</label><input id="contact_email" name="contact_email" type="email" value="{{ old('contact_email',app('currentTenant')->contact_email) }}">
<label for="contact_phone">Business phone</label><input id="contact_phone" name="contact_phone" value="{{ old('contact_phone',app('currentTenant')->contact_phone) }}" maxlength="40">
<div class="actions"><button>Save company settings</button></div></form></div>
@endsection
