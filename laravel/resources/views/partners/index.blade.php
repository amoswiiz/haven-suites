@extends('saas.layout')
@section('title', 'Partner apartments')
@push('scripts')<script src="{{ \App\Support\AssetVersion::url('assets/js/partner-directory.js') }}" defer></script>@endpush
@section('content')
@php
    $tenantSlug = app('currentTenant')->slug;
    $hasDates = !empty($filters['check_in']) && !empty($filters['check_out']);
    $bedroomOptions = ['' => 'Any size', '0' => 'Studio', '1' => '1 bedroom', '2' => '2 bedrooms', '3' => '3 bedrooms', '4' => '4 bedrooms', '4+' => '4 or more'];
@endphp
<div class="split"><div><h1>Partner apartments</h1><p class="muted">Find partner units that match a client's request and ask the owner about availability on WhatsApp.</p></div>
@if($canEdit)<a class="button" href="{{ route('partners.create', $tenantSlug) }}">Add partner apartment</a>@endif</div>

<form method="get" action="{{ route('partners.index', $tenantSlug) }}" class="card" aria-labelledby="partner-search-heading">
    <h2 id="partner-search-heading">Client request</h2>
    <div class="grid">
        <div><label for="partner-area">Area</label><input id="partner-area" name="area" list="partner-areas" value="{{ $filters['area'] ?? '' }}" placeholder="e.g. Diani" autocomplete="off"><datalist id="partner-areas">@foreach($areas as $area)<option value="{{ $area }}">@endforeach</datalist></div>
        <div><label for="partner-bedrooms">Bedrooms</label><select id="partner-bedrooms" name="bedrooms">@foreach($bedroomOptions as $key => $label)<option value="{{ $key }}" @selected((string) ($filters['bedrooms'] ?? '') === (string) $key)>{{ $label }}</option>@endforeach</select></div>
        <div><label for="partner-check-in">Check-in</label><input id="partner-check-in" name="check_in" type="date" value="{{ $filters['check_in'] ?? '' }}"></div>
        <div><label for="partner-check-out">Check-out</label><input id="partner-check-out" name="check_out" type="date" value="{{ $filters['check_out'] ?? '' }}"></div>
        <div><label for="partner-guests">Guests</label><input id="partner-guests" name="guests" type="number" min="1" max="100" value="{{ $filters['guests'] ?? '' }}"></div>
        <div><label for="partner-q">Owner, apartment or building</label><input id="partner-q" name="q" type="search" maxlength="100" value="{{ $filters['q'] ?? '' }}"></div>
        <div><label for="partner-status">Status</label><select id="partner-status" name="status">@foreach(['active' => 'Active partners', 'inactive' => 'Inactive partners', 'all' => 'All partners'] as $key => $label)<option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>@endforeach</select></div>
    </div>
    @error('partner')<p class="alert error" role="alert">{{ $message }}</p>@enderror
    <div class="actions"><button type="submit">Find partners</button><a href="{{ route('partners.index', $tenantSlug) }}">Clear</a></div>
    <p class="muted">Dates and guests are added to each WhatsApp message. With dates, apartments you have already booked for those nights are flagged.</p>
</form>

<section class="card" aria-labelledby="partner-results-heading">
    <p class="muted" data-dates-reminder @if($hasDates) hidden @endif><strong>Add the client's check-in and check-out dates above</strong> so each WhatsApp message gives the owner the dates and number of nights. Messages update as you type.</p>
    <h2 id="partner-results-heading">{{ $rows->count() }} {{ $rows->count() === 1 ? 'partner apartment' : 'partner apartments' }}</h2>
    @if($rows->isEmpty())
        <p class="muted">No partner apartments match. Try a nearby area, a different size, or ask your agents below.</p>
    @else
        <div class="table-wrap"><table>
            <thead><tr><th scope="col">Apartment</th><th scope="col">Owner</th><th scope="col">Size</th><th scope="col">Availability</th><th scope="col">Actions</th></tr></thead>
            <tbody>
            @foreach($rows as $row)
                <tr>
                    <td>@if($row['has_photo'])<img src="{{ route('partners.photo', [$tenantSlug, $row['partner']->id]) }}" alt="" width="64" height="48" loading="lazy" style="float:left;width:64px;height:48px;object-fit:cover;border-radius:6px;margin:0 10px 4px 0">@endif<strong>{{ $row['label'] }}</strong><br><span class="muted">{{ $row['partner']->location }}</span>@if(!$row['has_photo'] && blank($row['partner']->unit_number) && !$row['listing'])<br><span class="muted">No photo or unit number &mdash; @if($canEdit)<a href="{{ route('partners.edit', [$tenantSlug, $row['partner']->id]) }}">add one</a>@else ask a manager to add one @endif so the owner recognises the unit.</span>@endif @if($row['listing'])<br><span class="pill">Website listing: {{ $row['listing']->status }}</span>@endif @if($row['link'])<br><a href="{{ $row['link'] }}" target="_blank" rel="noopener noreferrer">View listing &nearr;</a>@endif</td>
                    <td>{{ $row['partner']->owner_name }}@if($row['partner']->owner_phone)<br>@if($row['tel'])<a href="tel:{{ $row['tel'] }}">{{ $row['partner']->owner_phone }}</a>@else{{ $row['partner']->owner_phone }}@endif @endif @if($row['partner']->owner_email)<br><a href="mailto:{{ $row['partner']->owner_email }}">{{ $row['partner']->owner_email }}</a>@endif</td>
                    <td>{{ (int) $row['partner']->bedrooms === 0 ? 'Studio' : $row['partner']->bedrooms.' bed' }}@if($row['partner']->bathrooms !== null) &middot; {{ $row['partner']->bathrooms }} bath @endif</td>
                    <td>@if($row['booked'])<span class="pill">Booked by us for these dates</span>@elseif($row['latest'] && $row['latest']->response === 'available')<strong>Owner says available</strong>@if($row['latest']->nightly_price)<br>{{ app('currentTenant')->currency }} {{ number_format((float) $row['latest']->nightly_price) }}/night @endif<br><span class="muted">Replied {{ \Illuminate\Support\Carbon::parse($row['latest']->responded_at)->timezone(app('currentTenant')->timezone ?: config('app.timezone'))->format('j M H:i') }}</span>@if($row['latest']->response_note)<br><span class="muted">&ldquo;{{ $row['latest']->response_note }}&rdquo;</span>@endif @elseif($row['latest'] && $row['latest']->response === 'unavailable')<strong>Owner says not available</strong><br><span class="muted">Replied {{ \Illuminate\Support\Carbon::parse($row['latest']->responded_at)->timezone(app('currentTenant')->timezone ?: config('app.timezone'))->format('j M H:i') }}</span>@if($row['latest']->response_note)<br><span class="muted">&ldquo;{{ $row['latest']->response_note }}&rdquo;</span>@endif @elseif($row['latest'])<span class="muted">Asked {{ \Illuminate\Support\Carbon::parse($row['latest']->created_at)->timezone(app('currentTenant')->timezone ?: config('app.timezone'))->format('j M H:i') }} &middot; awaiting reply</span>@elseif($hasDates)<span class="muted">No booking recorded &mdash; ask owner</span>@else{{ ucfirst($row['partner']->status) }}@endif</td>
                    <td><div class="actions" style="margin-top:0">
                        @if($row['whatsapp_number'] && !$row['booked'])<form method="post" action="{{ route('partners.ask', [$tenantSlug, $row['partner']->id]) }}" target="_blank" data-owner-request-form>@csrf<input type="hidden" name="check_in" value="{{ $filters['check_in'] ?? '' }}"><input type="hidden" name="check_out" value="{{ $filters['check_out'] ?? '' }}"><input type="hidden" name="guests" value="{{ $filters['guests'] ?? '' }}"><button type="submit">Ask on WhatsApp</button></form>@elseif(!$row['whatsapp_number'])<span class="muted">No WhatsApp number</span>@endif
                        @if($canEdit)<a href="{{ route('partners.edit', [$tenantSlug, $row['partner']->id]) }}">Edit</a>@endif
                    </div></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        @if($rows->count() >= 300)<p class="muted">Showing the first 300 matches. Narrow the search to see others.</p>@endif
    @endif
</section>

@if($agents->isNotEmpty())
<section class="card" aria-labelledby="agents-heading">
    <h2 id="agents-heading">Agents</h2>
    <p class="muted">Ask your agents when no partner apartment fits. The message describes the client's request from the search above.</p>
    <div class="table-wrap"><table><thead><tr><th scope="col">Agent</th><th scope="col">Contact</th><th scope="col">Actions</th></tr></thead><tbody>
    @foreach($agents as $row)
        <tr><td>{{ $row['agent']->name }}</td><td>@if($row['tel'])<a href="tel:{{ $row['tel'] }}">{{ $row['agent']->phone }}</a>@else{{ $row['agent']->phone }}@endif @if($row['agent']->email)<br><a href="mailto:{{ $row['agent']->email }}">{{ $row['agent']->email }}</a>@endif</td>
        <td>@if($row['whatsapp_url'])<a class="button secondary" href="{{ $row['whatsapp_url'] }}" target="_blank" rel="noopener noreferrer" data-owner-request data-wa-number="{{ $row['whatsapp_number'] }}" data-wa-greeting="{{ $row['greeting'] }}" data-wa-link="{{ $row['link'] ?? '' }}">Ask on WhatsApp</a>@else<span class="muted">No WhatsApp number</span>@endif</td></tr>
    @endforeach
    </tbody></table></div>
</section>
@endif
@endsection
