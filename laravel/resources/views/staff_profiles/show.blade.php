@extends('saas.layout')
@section('title', 'Staff profile')
@section('content')
<p><a href="{{ route('staff-profile.index', app('currentTenant')->slug) }}">&larr; Staff profiles</a></p>
<h1>{{ $profile->legal_name }}</h1>
<section class="card">
    <h2>Biodata and contact details</h2>
    <dl class="grid">
        <div><dt>Phone</dt><dd>{{ $profile->phone }}</dd></div>
        <div><dt>Contact email</dt><dd>{{ $profile->email ?: 'Not provided' }}</dd></div>
        <div><dt>Date of birth</dt><dd>{{ $profile->date_of_birth ?: 'Not provided' }}</dd></div>
        <div><dt>Residential address</dt><dd style="white-space:pre-wrap">{{ $profile->address ?: 'Not provided' }}</dd></div>
    </dl>
    <h2>Emergency contact</h2><p>{{ $profile->emergency_contact_name ?: 'Not provided' }}@if($profile->emergency_contact_phone)<br>{{ $profile->emergency_contact_phone }}@endif</p>
    <h2>Identification</h2>
    <p>{{ $documentTypes[$profile->document_type] ?? 'Identification document' }}</p>
    <a class="button" href="{{ route('staff-profile.review-document', [app('currentTenant')->slug, $profile->id]) }}">Download identification document</a>
    <p class="muted">Updated {{ date('d M Y, H:i', strtotime($profile->updated_at)) }}. Handle this information only for authorized staff administration.</p>
</section>
@endsection
