@extends('saas.layout')
@section('title','Preview guest SMS')
@section('content')
@include('communications.tabs')
<h1>Preview guest SMS</h1>
<section class="card">
<p><strong>To: {{ $phone }}</strong>@if($booking)<br>Booking #{{ $booking->id }} — {{ $booking->guest_name }}@endif</p>
@if($template)<p>Template: {{ $template->name }}</p>@endif
<form method="post" action="{{ route('communications.sms.send',app('currentTenant')->slug) }}" onsubmit="return confirm('Send this SMS now to the displayed recipient?');">
@csrf<input type="hidden" name="send_token" value="{{ $sendToken }}"><input type="hidden" name="phone" value="{{ $phone }}">
<label for="body">Final message (you can edit it)</label><textarea id="body" name="body" rows="8" maxlength="1600" required>{{ $body }}</textarea>
<p>Check the recipient, dates and apartment conditions. Long messages may use multiple SMS credits. Nothing has been sent yet.</p>
@if(!$ready)<p>SMS sending is currently disabled or the provider is not configured.</p>@endif
<button @disabled(!$ready)>Send SMS now</button>
<a href="{{ route('communications.guests',app('currentTenant')->slug) }}">Back to compose</a>
</form></section>
@endsection
