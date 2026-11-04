@extends('layouts/main')
@section('content')
<div class="card p-4" style="max-width:800px">
    <h1 class="h3">Guest KYC invitation</h1>
    <p>Invite a guest to register before booking. Links expire {{ $hours }} hours after creation and can be submitted once.</p>
    <form method="post" action="{{ site_url('staff/kyc/invite') }}">
        @csrf
        <label for="guestName" class="form-label">Client name</label>
        <input id="guestName" name="full_name" class="form-control mb-3" minlength="3" maxlength="180" value="{{ old('full_name') }}" required>
        <label for="guestPhone" class="form-label">SMS phone number</label>
        <input id="guestPhone" name="phone" type="tel" class="form-control" placeholder="+254712345678" maxlength="16" value="{{ old('phone') }}" required>
        <p class="small text-muted">Use international format with + and no spaces. Generating a link does not send an SMS.</p>
        <button type="submit" class="btn btn-primary">Generate KYC Link</button>
    </form>
    @if($invitation)
        <hr>
        <h2 class="h5">Invitation for {{ $invitation['name'] }}</h2>
        <p>Recipient: <strong>{{ $invitation['phone'] }}</strong>. Expires {{ date('d M Y H:i', $invitation['expires']) }} ({{ config('app.timezone') }}).</p>
        <label for="guestKycLink" class="form-label">Private guest link</label>
        <input id="guestKycLink" class="form-control" readonly value="{{ $invitation['url'] }}" onclick="this.select()">
        <button type="button" class="btn btn-outline-secondary mt-2" onclick="const field = document.getElementById('guestKycLink'); field.select(); if (navigator.clipboard) { navigator.clipboard.writeText(field.value).then(() => { document.getElementById('copyKycStatus').textContent = 'Link copied.'; }).catch(() => { document.getElementById('copyKycStatus').textContent = 'Press Ctrl+C to copy the selected link.'; }); } else { document.getElementById('copyKycStatus').textContent = 'Press Ctrl+C to copy the selected link.'; }">Copy Link</button>
        <span id="copyKycStatus" role="status"></span>
        <p class="small text-muted mt-2">Send this link privately to this guest. It grants access to their registration form.</p>
        @if($smsReady)
            <form method="post" action="{{ site_url('staff/kyc/invite/sms') }}">
                @csrf
                <input type="hidden" name="send_token" value="{{ $invitation['send_token'] }}">
                <button class="btn btn-primary" type="submit">Send SMS to {{ $invitation['phone'] }}</button>
            </form>
        @else
            <p>SMS requires messaging permission and enabled SMS delivery. You can copy the link above.</p>
        @endif
    @endif
    <p class="mt-3 mb-0"><a href="{{ site_url('staff/kyc') }}">Review submitted guest registrations</a></p>
</div>
@endsection
