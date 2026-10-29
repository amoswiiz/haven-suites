@extends('saas.layout')
@section('title','Company settings')
@section('content')
<div class="card narrow"><h1>Company settings</h1><p class="muted">{{ app('currentTenant')->slug }} · KES · Africa/Nairobi</p>
<form enctype="multipart/form-data" method="post" action="{{ route('company.settings.update',app('currentTenant')->slug) }}">@csrf
<label for="name">Company name</label><input id="name" name="name" value="{{ old('name',app('currentTenant')->name) }}" maxlength="150" required>
<p class="muted">Business email and phone are shown to investors so they can contact your management team.</p><label for="contact_email">Business email</label><input id="contact_email" name="contact_email" type="email" value="{{ old('contact_email',app('currentTenant')->contact_email) }}">
<label for="contact_phone">Business phone</label><input id="contact_phone" name="contact_phone" value="{{ old('contact_phone',app('currentTenant')->contact_phone) }}" maxlength="40">
<h2>Investor PDF branding</h2><label for="report_logo">Company logo (PNG or JPEG, up to 2 MB)</label><input id="report_logo" name="report_logo" type="file" accept="image/png,image/jpeg"><p class="muted">Leave empty to keep your existing logo. Without a logo, reports display your company name.</p><label for="report_primary_color">Primary colour</label><input type="color" id="report_primary_color" name="report_primary_color" value="{{ old('report_primary_color',app('currentTenant')->report_primary_color ?: '#1d4484') }}"><label for="report_accent_color">Accent colour</label><input type="color" id="report_accent_color" name="report_accent_color" value="{{ old('report_accent_color',app('currentTenant')->report_accent_color ?: '#ec914d') }}"><div class="actions"><button>Save company settings</button></div></form></div>
@endsection
