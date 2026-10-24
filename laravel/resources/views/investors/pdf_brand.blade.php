@php
$company=app('currentTenant');
$brandPath=public_path('assets/img/companies/'.$company->slug.'.png');
if(!is_file($brandPath) && $company->slug==='haven'){$brandPath=public_path('assets/img/haven-suites-logo.png');}
if($company->report_logo_path && str_starts_with($company->report_logo_path,'company-branding/'.$company->id.'/') && \Illuminate\Support\Facades\Storage::disk('local')->exists($company->report_logo_path)){$brandPath=\Illuminate\Support\Facades\Storage::disk('local')->path($company->report_logo_path);}
$brandLogo=is_file($brandPath) ? 'data:'.mime_content_type($brandPath).';base64,'.base64_encode(file_get_contents($brandPath)) : null;
@endphp
<style>@page{margin:22mm 12mm 22mm}body{font-family:DejaVu Sans,sans-serif;color:#25364b}.pdf-brand{border-bottom:3px solid {{ $company->report_accent_color ?: '#ec914d' }};padding-bottom:12px;margin-bottom:18px}.pdf-brand img{max-width:210px;max-height:65px}.pdf-wordmark{font-size:24px;font-weight:bold;color:{{ $company->report_primary_color ?: '#1d4484' }}}.pdf-footer{position:fixed;bottom:-13mm;left:0;right:0;font-size:8px;color:#52627a;border-top:1px solid #d9e3ee;padding-top:5px}.pdf-page:after{content:counter(page)}th{background:#edf2f9}h2,h3{color:{{ $company->report_primary_color ?: '#1d4484' }}}tr{page-break-inside:avoid}</style>
<div class="pdf-brand">@if($brandLogo)<img src="{{ $brandLogo }}" alt="{{ $company->name }}">@else<div class="pdf-wordmark">{{ $company->name }}</div>@endif<div>{{ $company->name }} &middot; Investor financial report</div></div>
<div class="pdf-footer">{{ $company->name }} @if($company->contact_email) &middot; {{ $company->contact_email }} @endif @if($company->contact_phone) &middot; {{ $company->contact_phone }} @endif<br>Confidential — investor report &middot; Generated {{ now($company->timezone)->format('d M Y H:i T') }} &middot; Page <span class="pdf-page"></span></div>
