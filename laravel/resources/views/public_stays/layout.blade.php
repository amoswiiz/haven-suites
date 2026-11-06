@php($browseUrl = !empty($preview) ? route('public-listings.index', $tenant->slug) : route('public-stays.index', $tenant->slug))
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle ?? 'Stays at '.$tenant->name }}</title>
    <meta name="description" content="{{ $pageDescription ?? $site->tagline ?? 'Find your next stay with '.$tenant->name }}">
    @if(!empty($preview))<meta name="robots" content="noindex, nofollow">@endif
    @if(!empty($canonicalUrl))<link rel="canonical" href="{{ $canonicalUrl }}">@endif
    <meta property="og:title" content="{{ $pageTitle ?? $tenant->name }}">
    <meta property="og:description" content="{{ $pageDescription ?? $site->tagline ?? '' }}">
    @if(!empty($listing['photos'][0]['url']))<meta property="og:image" content="{{ $listing['photos'][0]['url'] }}">@endif
    <link rel="stylesheet" href="{{ asset('assets/css/public-stays.css') }}?v=1">
    @if(!empty($structuredData) && empty($preview))<script type="application/ld+json">@json($structuredData)</script>@endif
</head>
<body class="public-stays">
    <a class="stay-skip" href="#stay-content">Skip to content</a>
    <header class="stay-header">
        <a class="stay-brand" href="{{ $browseUrl }}">{{ $tenant->name }}<span>Make yourself at home</span></a>
        <nav aria-label="Main navigation"><a href="{{ $browseUrl }}">{{ !empty($preview) ? 'Manage listings' : 'Find a stay' }}</a><a href="#stay-contact">Contact our team</a></nav>
    </header>
    <main id="stay-content" tabindex="-1">
        @if($errors->any())<div class="stay-container"><div class="stay-notice error" role="alert"><strong>Please check these details</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>@endif
        @yield('content')
    </main>
    <footer class="stay-footer" id="stay-contact"><div class="stay-container stay-footer-grid"><div><strong>{{ $tenant->name }}</strong><p>A comfortable place. A thoughtful welcome.<br>Find your next stay with us.</p></div><div><strong>Let's plan your stay</strong>@if(!empty($site->contact_email))<p><a href="mailto:{{ $site->contact_email }}">{{ $site->contact_email }}</a></p>@endif @if(!empty($site->contact_phone))<p><a href="tel:{{ preg_replace('/[^+0-9]/', '', $site->contact_phone) }}">{{ $site->contact_phone }}</a></p>@endif<p>Your contact details are used to respond to your enquiry and help arrange your stay.</p></div></div><div class="stay-container stay-footer-bottom"><span>&copy; {{ date('Y') }} {{ $tenant->name }}</span><a href="{{ route('login') }}">Staff sign in</a></div></footer>
    <script src="{{ asset('assets/js/public-stays.js') }}?v=2" defer></script>
</body>
</html>
