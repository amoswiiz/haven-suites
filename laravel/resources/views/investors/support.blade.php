@php
    $supportCompany = app('currentTenant');
    $supportEmail = filter_var($supportCompany->contact_email, FILTER_VALIDATE_EMAIL) ? $supportCompany->contact_email : null;
    $supportPhone = preg_replace('/[\s().-]+/', '', $supportCompany->contact_phone ?? '');
    $supportPhone = preg_match('/^\+?[0-9]{7,15}$/', $supportPhone) ? $supportPhone : null;
@endphp
<section class="card" aria-labelledby="owner-support-heading">
<h2 id="owner-support-heading">Contact your management team</h2>
<p class="muted">Questions about a booking, statement, payment or your property? Contact {{ $supportCompany->name }}.</p>
<div class="actions">
@if($supportEmail)<a class="button secondary" href="mailto:{{ $supportEmail }}">Email {{ $supportEmail }}</a>@endif
@if($supportPhone)<a class="button secondary" href="tel:{{ $supportPhone }}">Call {{ $supportCompany->contact_phone }}</a>@endif
</div>
@if(!$supportEmail && !$supportPhone)<p>Your management team has not added contact details yet. Use your existing management contact.</p>@endif
</section>
