@extends('saas.layout')
@section('title','Guest messages')
@section('content')
@include('communications.tabs')
<h1>Guest messages</h1>
<p>Prepare a check-in message, checkout thank-you, review request or upcoming-stay reminder. SMS is available now; email and WhatsApp are not connected here yet.</p>
<form class="card" method="post" action="{{ route('communications.preview',app('currentTenant')->slug) }}">
@csrf
<label for="reservation_id">Booking (optional)</label>
<input id="reservation_id" name="reservation_id" type="number" min="1" list="guest-bookings" value="{{ old('reservation_id',request('reservation_id')) }}" placeholder="Choose or enter booking number">
<datalist id="guest-bookings">@foreach($bookings as $booking)<option value="{{ $booking->id }}">{{ $booking->guest_name }} — {{ $units->get($booking->unit_id)?->unit_number }} — {{ $booking->check_in }} to {{ $booking->check_out }}</option>@endforeach</datalist>
<small>Recent bookings are listed. You can also enter an older booking number. Booking details and the guest's phone are filled when you preview.</small>
<label for="unit_id">Apartment (when no booking is selected)</label><select id="unit_id" name="unit_id"><option value="">Use booking or template apartment</option>@foreach($units as $unit)<option value="{{ $unit->id }}" @selected(old('unit_id')==$unit->id)>{{ $unit->property_name }} — {{ $unit->unit_number }}</option>@endforeach</select>
<label for="template_id">Message template</label><select id="template_id" name="template_id"><option value="">Write a custom message below</option>@foreach($templates as $template)<option value="{{ $template->id }}" @selected(old('template_id')==$template->id)>{{ $template->name }} — {{ $template->unit_id ? ($units->get($template->unit_id)?->unit_number ?? 'Apartment unavailable') : 'Company-wide' }}</option>@endforeach</select>
<label for="body">Custom SMS (used when no template is selected)</label><textarea id="body" name="body" rows="6" maxlength="1600">{{ old('body') }}</textarea>
<label for="phone">Recipient phone (optional override)</label><input id="phone" name="phone" type="tel" value="{{ old('phone') }}" placeholder="Leave blank to use the booking guest's number"><small>Use a country code, e.g. +254720602805. Kenyan 07/01 numbers are also accepted.</small>
<button>Preview SMS</button>
</form>
@endsection
