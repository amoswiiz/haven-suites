@extends('saas.layout')
@section('title','Company settings')
@section('content')
<div class="card narrow"><h1>Company settings</h1><p class="muted">{{ app('currentTenant')->slug }} · KES · Africa/Nairobi</p>
<form enctype="multipart/form-data" method="post" action="{{ route('company.settings.update',app('currentTenant')->slug) }}">@csrf
<label for="name">Company name</label><input id="name" name="name" value="{{ old('name',app('currentTenant')->name) }}" maxlength="150" required>
<p class="muted">Business email and phone are shown to investors so they can contact your management team.</p><label for="contact_email">Business email</label><input id="contact_email" name="contact_email" type="email" value="{{ old('contact_email',app('currentTenant')->contact_email) }}">
<label for="contact_phone">Business phone</label><input id="contact_phone" name="contact_phone" value="{{ old('contact_phone',app('currentTenant')->contact_phone) }}" maxlength="40">
<h2>Investor PDF branding</h2><label for="report_logo">Company logo (PNG or JPEG, up to 2 MB)</label><input id="report_logo" name="report_logo" type="file" accept="image/png,image/jpeg"><p class="muted">Leave empty to keep your existing logo. Without a logo, reports display your company name.</p><label for="report_primary_color">Primary colour</label><input type="color" id="report_primary_color" name="report_primary_color" value="{{ old('report_primary_color',app('currentTenant')->report_primary_color ?: '#1d4484') }}"><label for="report_accent_color">Accent colour</label><input type="color" id="report_accent_color" name="report_accent_color" value="{{ old('report_accent_color',app('currentTenant')->report_accent_color ?: '#ec914d') }}"><div class="actions"><button>Save company settings</button></div></form></div>
@if(auth()->user()->inGroup('superadmin'))
<section class="card narrow" style="margin-top:22px"><h2>Email delivery</h2>
<p class="muted">Configure this company's investor emails. Use the SMTP settings supplied by your provider. Accounts that require OAuth sign-in are not supported by this password form.</p>
<form method="post" action="{{ route('company.settings.update',app('currentTenant')->slug) }}">
@csrf
<input type="hidden" name="email_action" value="save">
<label for="mail-provider">Provider</label><select id="mail-provider" name="provider">
@foreach(['cpanel'=>'cPanel','google'=>'Google Workspace','zoho'=>'Zoho','custom'=>'Other SMTP provider'] as $value=>$label)
<option value="{{ $value }}" @selected(old('provider',$emailSettings?->provider ?? 'cpanel')===$value)>{{ $label }}</option>
@endforeach
</select>
<label for="mail-host">SMTP hostname</label><input id="mail-host" name="host" value="{{ old('host',$emailSettings?->host) }}" maxlength="253" placeholder="mail.example.com" required>
<label for="mail-port">Connection encryption</label><select id="mail-port" name="port"><option value="465" @selected((int)old('port',$emailSettings?->port ?? 465)===465)>TLS — port 465</option><option value="587" @selected((int)old('port',$emailSettings?->port ?? 465)===587)>STARTTLS — port 587</option></select>
<label for="mail-username">SMTP username</label><input id="mail-username" name="username" value="{{ old('username',$emailSettings?->username) }}" maxlength="255" autocomplete="off" required>
<label for="mail-password">SMTP password or app password</label><input id="mail-password" name="password" type="password" maxlength="1024" autocomplete="new-password">
<p class="muted">{{ $emailSettings ? 'A password is stored securely. Leave blank to keep it. Enter a new password when changing provider, hostname or username.' : 'Enter the password supplied for this mailbox. It will not be displayed after saving.' }}</p>
<label for="mail-from">Sender email</label><input id="mail-from" type="email" name="from_address" value="{{ old('from_address',$emailSettings?->from_address) }}" maxlength="255" required>
<label for="mail-name">Sender name</label><input id="mail-name" name="from_name" value="{{ old('from_name',$emailSettings?->from_name ?? app('currentTenant')->name) }}" maxlength="150" required>
<label for="mail-enabled">Company email delivery</label><select id="mail-enabled" name="enabled"><option value="0" @selected(!old('enabled',$emailSettings?->enabled ?? false))>Disabled — save and test first</option><option value="1" @selected(old('enabled',$emailSettings?->enabled ?? false))>Enabled</option></select>
<p class="muted">Investor notification preferences and the server's live-delivery switch still apply. Saving settings does not resend previous notifications.</p>
@if(!config('investors.live_delivery'))<p class="muted">Automatic investor delivery is currently disabled on this server. Test emails can still be sent manually.</p>@endif
<div class="actions"><button>Save email settings</button></div></form>
@if($emailSettings)
<form method="post" action="{{ route('company.settings.update',app('currentTenant')->slug) }}">@csrf<input type="hidden" name="email_action" value="test"><p class="muted">Sends one real email using the saved settings to {{ auth()->user()->email }}, even when automatic delivery is disabled.</p><button class="secondary">Send test email</button></form>
@endif
</section>
@endif
@endsection
