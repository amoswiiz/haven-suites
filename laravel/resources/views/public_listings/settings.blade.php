@extends('saas.layout')
@section('title', 'Website settings')
@section('content')
@php($tenantSlug = app('currentTenant')->slug)
<p><a href="{{ route('public-listings.index', $tenantSlug) }}">&larr; Public listings</a></p>
<div class="split"><div><h1>Website settings</h1><p class="muted">Manage your public website, search appearance and guest contact options.</p></div>@if($site?->enabled)<a class="button secondary" href="{{ $websiteLinks['public_url'] }}" target="_blank" rel="noopener">View website</a>@endif</div>
<form method="post" data-listing-save-form action="{{ route('public-listings.site.update', $tenantSlug) }}">
    @csrf
    <input type="hidden" name="version" value="{{ old('version', $site->version ?? 0) }}">
    <input type="hidden" name="settings_page" value="1">
    <input type="hidden" name="enabled" value="0">
    <section class="card" aria-labelledby="site-settings-heading"><h2 id="site-settings-heading">Public site settings</h2><p class="muted">Enable the site when your listings are ready. Draft and paused apartments remain private.</p><div class="grid">
        <div><label for="headline">Welcome heading</label><input id="headline" name="headline" value="{{ old('headline', $site->headline ?? '') }}" maxlength="180" placeholder="Find your next stay with Haven Suites"></div>
        <div><label for="tagline">Short introduction</label><textarea id="tagline" name="tagline" maxlength="500" rows="3">{{ old('tagline', $site->tagline ?? '') }}</textarea></div>
        <div><label for="contact_email">Guest contact email</label><input id="contact_email" name="contact_email" type="email" value="{{ old('contact_email', $site->contact_email ?? '') }}" maxlength="180"></div>
        <div><label for="contact_phone">Guest contact phone</label><input id="contact_phone" name="contact_phone" type="tel" inputmode="tel" autocomplete="off" value="{{ old('contact_phone', ($site->contact_phone ?? '') ?: \App\Support\PhoneNumber::DEFAULT_PREFIX.' ') }}" maxlength="40" placeholder="+254 712 345 678"><small class="muted">Shown to guests. Type the rest of the number after +254, or 0712 345 678.</small></div>
        <div><label for="enquiry_assignee_id">Assign website enquiries to</label><select id="enquiry_assignee_id" name="enquiry_assignee_id"><option value="">Choose salesperson</option>@foreach($members as $member)<option value="{{ $member->id }}" @selected((string) old('enquiry_assignee_id', $site->enquiry_assignee_id ?? '') === (string) $member->id)>{{ $member->name }}</option>@endforeach</select><small class="muted">Website enquiry form submissions enter your sales pipeline and are assigned to this active team member.</small></div>
        <div><label for="response_hours">Target response time (hours)</label><input id="response_hours" name="response_hours" type="number" min="1" max="168" required value="{{ old('response_hours', $site->response_hours ?? 24) }}"></div>
    </div><label><input type="checkbox" name="enabled" value="1" style="width:auto" @checked(old('enabled', $site->enabled ?? false))> Enable the public listings site</label></section>
    <section class="card" aria-labelledby="site-seo-heading"><h2 id="site-seo-heading">Search appearance</h2><p class="muted">Use a clear website title and introduction for search engines and shared links. Leave these blank to use your company name and website introduction. Individual apartments can have their own search details.</p><div class="grid">
        <div><label for="seo_title">Website search title</label><input id="seo_title" name="seo_title" maxlength="180" value="{{ old('seo_title', $site->seo_title ?? '') }}"><small class="muted">Aim for about 50–60 characters. Maximum 180.</small></div>
        <div><label for="seo_description">Website search description</label><textarea id="seo_description" name="seo_description" maxlength="320" rows="3">{{ old('seo_description', $site->seo_description ?? '') }}</textarea><small class="muted">Summarise what guests can discover in about 150–160 characters. Maximum 320.</small></div>
    </div></section>
    <section class="card" aria-labelledby="site-whatsapp-heading"><h2 id="site-whatsapp-heading">WhatsApp contact</h2><p class="muted">Offer guests a quick way to ask about an apartment. The link opens a prefilled WhatsApp chat; the guest chooses whether to send the message. Opening the chat does not create a pipeline enquiry.</p><div class="grid">
        <div><label for="whatsapp_number">Public WhatsApp number</label><input id="whatsapp_number" name="whatsapp_number" type="tel" inputmode="tel" autocomplete="off" maxlength="40" placeholder="+254 712 345 678" value="{{ old('whatsapp_number', ($site->whatsapp_number ?? '') ?: \App\Support\PhoneNumber::DEFAULT_PREFIX.' ') }}"><small class="muted">Type the rest of the number after +254 (9 digits), or 0712 345 678. Change +254 for another country. Leave only +254 to hide WhatsApp links.</small></div>
        <div><label for="whatsapp_message">Default prefilled message</label><textarea id="whatsapp_message" name="whatsapp_message" maxlength="1000" rows="4" placeholder="Hello! I am interested in {listing_title}: {listing_url}">{{ old('whatsapp_message', $site->whatsapp_message ?? '') }}</textarea><small class="muted">Use {listing_title}, {listing_url} or {listing_reference}. Every apartment message includes its title and public link.</small></div>
    </div></section>
    <section class="card" aria-labelledby="site-navigation-heading"><h2 id="site-navigation-heading">Website menu</h2><p class="muted">Choose the labels and order shown in the website header. Each destination can appear once. Leave an entire row blank to omit it.</p>
        @for($menuIndex = 0; $menuIndex < 3; $menuIndex++)
            <div class="grid" style="margin-bottom:20px"><div><label for="menu-label-{{ $menuIndex }}">Menu label {{ $menuIndex + 1 }}</label><input id="menu-label-{{ $menuIndex }}" name="navigation_links[{{ $menuIndex }}][label]" maxlength="60" value="{{ old('navigation_links.'.$menuIndex.'.label', $navigation[$menuIndex]['label'] ?? '') }}"></div><div><label for="menu-target-{{ $menuIndex }}">Destination {{ $menuIndex + 1 }}</label><select id="menu-target-{{ $menuIndex }}" name="navigation_links[{{ $menuIndex }}][target]"><option value="">Choose destination</option>@foreach(['home' => 'Home', 'stays' => 'Find a stay', 'contact' => 'Contact'] as $target => $label)<option value="{{ $target }}" @selected(old('navigation_links.'.$menuIndex.'.target', $navigation[$menuIndex]['target'] ?? '') === $target)>{{ $label }}</option>@endforeach</select></div></div>
        @endfor
    </section>
    @include('public_listings.save_progress')
    <div class="actions"><button type="submit">Save website settings</button><a href="{{ route('public-listings.index', $tenantSlug) }}">Back to listings</a></div>
</form>
@endsection
