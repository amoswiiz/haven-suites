@extends('saas.layout')
@section('title', 'Public listings')
@section('content')
<div class="split"><div><h1>Public listings</h1><p class="muted">Publish your existing managed and partner apartments. Bookings and availability stay connected to the same apartment.</p></div><div class="actions" style="margin-top:0"><a class="button" href="{{ route('public-listings.create', app('currentTenant')->slug) }}">Create listing</a>@if($site?->enabled)<a class="button secondary" href="{{ route('public-stays.index', ['company' => app('currentTenant')->slug]) }}" target="_blank" rel="noopener">Open public site</a>@endif</div></div>
<section class="card"><h2>Start from your apartment records</h2><p class="muted">{{ $apartments->where('status', 'active')->where('kind', 'managed')->count() }} active managed apartments and {{ $apartments->where('status', 'active')->where('kind', 'partner')->count() }} active partner apartments. Prepare missing private drafts, then add descriptions, guest capacity, minimum stays and accurate photos. Existing listings remain unchanged.</p><form method="post" action="{{ route('public-listings.prepare', app('currentTenant')->slug) }}">@csrf<button type="submit" class="secondary">Prepare listings from existing apartments</button></form></section>
<section class="card" aria-labelledby="site-settings-heading">
    <h2 id="site-settings-heading">Public site settings</h2>
    <p class="muted">The site opens only when you enable it. Draft and paused listings remain private.</p>
    <form method="post" action="{{ route('public-listings.site.update', app('currentTenant')->slug) }}">
        @csrf
        <input type="hidden" name="version" value="{{ old('version', $site->version ?? 0) }}">
        <input type="hidden" name="enabled" value="0">
        <div class="grid">
            <div><label for="headline">Welcome heading</label><input id="headline" name="headline" value="{{ old('headline', $site->headline ?? '') }}" maxlength="180" placeholder="Find your next stay with Haven Suites"></div>
            <div><label for="tagline">Short introduction</label><input id="tagline" name="tagline" value="{{ old('tagline', $site->tagline ?? '') }}" maxlength="500"></div>
            <div><label for="contact_email">Guest contact email</label><input id="contact_email" name="contact_email" type="email" value="{{ old('contact_email', $site->contact_email ?? '') }}" maxlength="180"></div>
            <div><label for="contact_phone">Guest contact phone</label><input id="contact_phone" name="contact_phone" type="tel" value="{{ old('contact_phone', $site->contact_phone ?? '') }}" maxlength="40"></div>
            <div><label for="enquiry_assignee_id">Assign website enquiries to</label><select id="enquiry_assignee_id" name="enquiry_assignee_id"><option value="">Choose salesperson</option>@foreach($members as $member)<option value="{{ $member->id }}" @selected((string) old('enquiry_assignee_id', $site->enquiry_assignee_id ?? '') === (string) $member->id)>{{ $member->name }}</option>@endforeach</select><small class="muted">Choose an active sales team member before enabling the site.</small></div>
            <div><label for="response_hours">Target response time (hours)</label><input id="response_hours" name="response_hours" type="number" min="1" max="168" required value="{{ old('response_hours', $site->response_hours ?? 24) }}"></div>
        </div>
        <label><input type="checkbox" name="enabled" value="1" style="width:auto" @checked(old('enabled', $site->enabled ?? false))> Enable the public listings site</label>
        <div class="actions"><button type="submit">Save site settings</button></div>
    </form>
</section>
<section class="card" aria-labelledby="listings-heading"><h2 id="listings-heading">Your listings</h2>
    <div class="table-wrap"><table><thead><tr><th>Listing</th><th>Linked apartment</th><th>Visibility</th><th>Actions</th></tr></thead><tbody>
    @forelse($listings as $listing)
        @php($unit = $apartments->get($listing->unit_id !== null ? 'unit:'.$listing->unit_id : 'partner:'.$listing->partner_apartment_id))
        <tr><td><strong>{{ $listing->title }}</strong><br><span class="muted">{{ $listing->city }}{{ $listing->neighbourhood ? ' · '.$listing->neighbourhood : '' }}</span></td><td>{{ $unit ? $unit->property_name.($unit->unit_number ? ' / '.$unit->unit_number : '') : 'Apartment no longer available' }}@if($unit)<br><small>{{ ucfirst($unit->kind) }} apartment</small>@endif @if($unit && $unit->status !== 'active')<br><span class="pill">Apartment inactive — hidden publicly</span>@endif</td><td><span class="pill">{{ ucfirst($listing->status) }}</span>@if($listing->max_guests === null || $listing->min_nights === null || trim($listing->description) === '' || trim($listing->city) === '')<br><small class="muted">Guest details needed</small>@endif @if(!$site?->enabled)<br><small class="muted">Public site disabled</small>@endif</td><td><a href="{{ route('public-listings.edit', ['tenant' => app('currentTenant')->slug, 'id' => $listing->id]) }}">Edit listing</a>
            @if(\Illuminate\Support\Facades\Route::has('public-listings.preview'))<br><a href="{{ route('public-listings.preview', ['tenant' => app('currentTenant')->slug, 'id' => $listing->id]) }}" target="_blank" rel="noopener">Preview</a>@endif
            @if($unit && $unit->kind === 'managed' && haven_can_access(site_url('units/edit/'.$unit->id), 'GET'))<br><a href="{{ site_url('units/edit/'.$unit->id) }}">Open unit</a>@endif
        </td></tr>
    @empty<tr><td colspan="4">Create a listing for an existing apartment, upload its photos, then review it before publishing.</td></tr>@endforelse
    </tbody></table></div>
</section>
@endsection
