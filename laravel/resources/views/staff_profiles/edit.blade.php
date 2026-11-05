@extends('saas.layout')
@section('title', 'My staff profile')
@section('content')
<h1>My staff profile</h1>
<p>Keep your biodata, contact details and identification document up to date for staff administration.</p>
<p class="muted">Your document is private. You and your company administrators can access it. Profile contact details do not change your login details.</p>
<form method="post" action="{{ route('staff-profile.update', app('currentTenant')->slug) }}" enctype="multipart/form-data" class="card">
    @csrf
    <input type="hidden" name="version" value="{{ old('version', $profile?->version ?? 0) }}">
    <h2>Biodata and contact details</h2>
    <div class="grid">
        <div><label for="legal-name">Full name as shown on your ID *</label><input id="legal-name" name="legal_name" maxlength="180" autocomplete="name" value="{{ old('legal_name', $profile?->legal_name ?? auth()->user()->name) }}" required></div>
        <div><label for="phone">Phone number *</label><input id="phone" name="phone" type="tel" maxlength="40" autocomplete="tel" value="{{ old('phone', $profile?->phone) }}" required></div>
        <div><label for="email">Contact email</label><input id="email" name="email" type="email" maxlength="180" autocomplete="email" value="{{ old('email', $profile?->email ?? auth()->user()->email) }}"></div>
        <div><label for="date-of-birth">Date of birth</label><input id="date-of-birth" name="date_of_birth" type="date" value="{{ old('date_of_birth', $profile?->date_of_birth) }}" max="{{ now()->subDay()->toDateString() }}"></div>
    </div>
    <label for="address">Residential address</label><textarea id="address" name="address" rows="3" maxlength="1000" autocomplete="street-address">{{ old('address', $profile?->address) }}</textarea>
    <h2 style="margin-top:28px">Emergency contact</h2>
    <div class="grid">
        <div><label for="emergency-name">Contact name</label><input id="emergency-name" name="emergency_contact_name" maxlength="180" value="{{ old('emergency_contact_name', $profile?->emergency_contact_name) }}"></div>
        <div><label for="emergency-phone">Contact phone</label><input id="emergency-phone" name="emergency_contact_phone" type="tel" maxlength="40" value="{{ old('emergency_contact_phone', $profile?->emergency_contact_phone) }}"></div>
    </div>
    <h2 style="margin-top:28px">Identification</h2>
    <label for="document-type">Identification document type *</label>
    <select id="document-type" name="document_type" required><option value="">Select your document type</option>
        @foreach($documentTypes as $value => $label)<option value="{{ $value }}" @selected(old('document_type', $profile?->document_type) === $value)>{{ $label }}</option>@endforeach
    </select>
    @if($profile)<p>Saved document: <a href="{{ route('staff-profile.document', app('currentTenant')->slug) }}">Download my identification document</a></p>@endif
    <label for="identity-document">{{ $profile ? 'Replace identification document' : 'Upload identification document *' }}</label>
    <input id="identity-document" name="identity_document" type="file" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" @required(!$profile)>
    <p class="muted">Upload a clear JPG, PNG or PDF, maximum 5 MB.@if($profile) Leave blank to keep your saved document. Upload a matching document if you change its type.@endif</p>
    <div class="actions"><button type="submit">Save my profile</button></div>
</form>
@endsection
