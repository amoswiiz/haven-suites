@php
    $publicBrowseUrl = route('public-stays.index', $tenant->slug);
    $browseUrl = !empty($preview) ? route('public-listings.index', $tenant->slug) : $publicBrowseUrl;
    $navigationItems = !empty($websiteMenu) ? $websiteMenu : [
        ['label' => 'Home', 'url' => $publicBrowseUrl],
        ['label' => 'Find stays', 'url' => $publicBrowseUrl.'#find-stay'],
        ['label' => 'Contact', 'url' => $publicBrowseUrl.'#stay-contact'],
    ];
    $contactLinks = $websiteContactLinks ?? ['public_url' => $publicBrowseUrl, 'enquiry_url' => $publicBrowseUrl.'#find-stay', 'whatsapp_url' => null];
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle ?? 'Stays at '.$tenant->name }}</title>
    <meta name="description" content="{{ $pageDescription ?? $site->tagline ?? 'Find your next stay with '.$tenant->name }}">
    <meta name="robots" content="{{ $robotsContent ?? (!empty($preview) ? 'noindex, nofollow' : 'index, follow, max-image-preview:large') }}">
    @if(!empty($canonicalUrl))<link rel="canonical" href="{{ $canonicalUrl }}">@endif
    <meta property="og:title" content="{{ $pageTitle ?? $tenant->name }}">
    <meta property="og:description" content="{{ $pageDescription ?? $site->tagline ?? '' }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $tenant->name }}">
    @if(!empty($canonicalUrl))<meta property="og:url" content="{{ $canonicalUrl }}">@endif
    <meta name="twitter:card" content="{{ !empty($socialImage['url']) ? 'summary_large_image' : 'summary' }}">
    <meta name="twitter:title" content="{{ $pageTitle ?? $tenant->name }}">
    <meta name="twitter:description" content="{{ $pageDescription ?? $site->tagline ?? '' }}">
    @if(empty($preview) && !empty($socialImage['url']))
        <meta property="og:image" content="{{ $socialImage['url'] }}">
        <meta property="og:image:alt" content="{{ $socialImage['caption'] ?? $pageTitle }}">
        <meta name="twitter:image" content="{{ $socialImage['url'] }}">
        <meta name="twitter:image:alt" content="{{ $socialImage['caption'] ?? $pageTitle }}">
    @endif
    <link rel="stylesheet" href="{{ asset('assets/css/public-stays.css') }}?v=5">
    @if(!empty($structuredData) && empty($preview))<script type="application/ld+json">@json($structuredData)</script>@endif
</head>
<body class="public-stays">
    <a class="stay-skip" href="#stay-content">Skip to content</a>
    <div class="stay-header-wrap">
        <header class="stay-header" data-public-header>
            <a class="stay-brand" href="{{ $browseUrl }}">{{ $tenant->name }}<span>Make yourself at home</span></a>
            <button class="stay-menu-toggle" type="button" aria-expanded="false" aria-controls="stay-public-menu" data-menu-toggle hidden><span class="stay-menu-icon" aria-hidden="true"><span></span><span></span><span></span></span>Menu</button>
            <div class="stay-header-menu" id="stay-public-menu" data-public-menu>
                <nav aria-label="Main navigation">@foreach($navigationItems as $menuItem)<a href="{{ $menuItem['url'] }}">{{ $menuItem['label'] }}</a>@endforeach</nav>
                <div class="stay-header-actions">
                    @if(!empty($contactLinks['whatsapp_url']))<a class="stay-whatsapp-link" href="{{ $contactLinks['whatsapp_url'] }}" target="_blank" rel="noopener noreferrer">WhatsApp <span aria-hidden="true">&nearr;</span></a>@endif
                    <a class="stay-button" href="{{ $contactLinks['enquiry_url'] ?? $publicBrowseUrl.'#find-stay' }}">Enquire <span aria-hidden="true">&rarr;</span></a>
                </div>
            </div>
        </header>
    </div>
    <main id="stay-content" tabindex="-1">
        @if($errors->any())<div class="stay-container"><div class="stay-notice error" role="alert"><strong>Please check these details</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>@endif
        @yield('content')
    </main>
    <footer class="stay-footer" id="stay-contact">
        <div class="stay-container stay-footer-grid">
            <div class="stay-footer-intro"><span class="stay-eyebrow">Make yourself at home</span><strong>{{ $tenant->name }}</strong><p>Comfortable spaces for your next chapter, whether you're here for a short break or a longer stay.</p><a href="{{ $contactLinks['enquiry_url'] ?? $publicBrowseUrl.'#find-stay' }}">Find a stay and enquire &rarr;</a></div>
            <div><strong>Explore</strong><nav class="stay-footer-nav" aria-label="Footer navigation">@foreach($navigationItems as $menuItem)<a href="{{ $menuItem['url'] }}">{{ $menuItem['label'] }}</a>@endforeach</nav></div>
            <div><strong>Let's plan your stay</strong>@if(!empty($site->contact_email))<p><a href="mailto:{{ $site->contact_email }}">{{ $site->contact_email }}</a></p>@endif @if(!empty($site->contact_phone))<p><a href="tel:{{ preg_replace('/[^+0-9]/', '', $site->contact_phone) }}">{{ $site->contact_phone }}</a></p>@endif @if(!empty($contactLinks['whatsapp_url']))<p><a class="stay-footer-whatsapp" href="{{ $contactLinks['whatsapp_url'] }}" target="_blank" rel="noopener noreferrer">Chat with us on WhatsApp &nearr;</a></p>@endif<p><a href="{{ route('public-stays.track', $tenant->slug) }}">Track an enquiry &rarr;</a></p><p class="stay-contact-note">We use your contact details to respond to your enquiry and help arrange your stay.</p></div>
        </div>
        <div class="stay-container stay-footer-bottom"><span>&copy; {{ date('Y') }} {{ $tenant->name }}</span><span>BNBs &amp; STR homes</span><a href="{{ route('login') }}">Staff sign in</a></div>
    </footer>
    <script src="{{ asset('assets/js/public-stays.js') }}?v=5" defer></script>
</body>
</html>
