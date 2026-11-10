@extends('public_stays.layout')
@section('title', 'Track your enquiry')
@section('content')
<div class="stay-container">
<section class="stay-track" aria-labelledby="lookup-heading">
    <span class="stay-eyebrow">Track your enquiry</span>
    <h1 id="lookup-heading">Check the progress of your enquiry</h1>
    <p>Enter the reference from your SMS or confirmation page, and the last 4 digits of the phone number you gave us.</p>
    @if($errors->any())<div class="stay-track-status is-closed" role="alert"><p>{{ $errors->first() }}</p></div>@endif
    <form method="post" action="{{ route('public-stays.track.submit', $tenant->slug) }}" class="stay-track-lookup" novalidate>
        @csrf
        <div class="stay-field"><label for="track-reference">Enquiry reference</label><input id="track-reference" name="reference" required maxlength="30" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="{{ $example }}" value="{{ old('reference') }}"></div>
        <div class="stay-field"><label for="track-digits">Last 4 digits of your phone number</label><input id="track-digits" name="phone_last_digits" required inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="off" placeholder="5678"></div>
        <button type="submit" class="stay-button">Show my enquiry <span aria-hidden="true">&rarr;</span></button>
    </form>
    <p class="stay-help">Can't find your reference? @if(!empty($websiteContactLinks['whatsapp_url']))<a href="{{ $websiteContactLinks['whatsapp_url'] }}" target="_blank" rel="noopener noreferrer">Ask us on WhatsApp</a>@if(!empty($site->contact_phone)) or call {{ $site->contact_phone }}@endif.@elseif(!empty($site->contact_phone))Call us on {{ $site->contact_phone }}.@else Contact our team and we will help.@endif</p>
</section>
</div>
@endsection
