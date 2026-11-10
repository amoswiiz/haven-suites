@extends('public_stays.layout')
@section('title', 'Enquiry received')
@section('content')
<section class="stay-confirmation" style="max-width:680px;margin:48px auto;padding:32px;border:1px solid #dde5ec;border-radius:18px;background:white">
    <p style="color:#217454;font-weight:700">Enquiry received</p>
    <h1>Thank you for considering {{ $tenant->name }}!</h1>
    <p>Our team will review your preferred stay and get back to you to confirm availability and pricing.</p>
    <p>Your enquiry reference: <strong>{{ $reference }}</strong></p>
    @if(!empty($trackingUrl))<p><a class="stay-button" href="{{ $trackingUrl }}">Track your enquiry <span aria-hidden="true">&rarr;</span></a><br><span class="stay-help">Bookmark this link to check your enquiry's progress at any time.</span></p>@endif
    <p>Once your booking is confirmed, we will send you a confirmation. This enquiry does not reserve an apartment.</p>
    <a href="{{ route('public-stays.index', $tenant->slug) }}">Explore more stays</a>
</section>
@endsection
