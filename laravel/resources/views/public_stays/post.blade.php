@extends('public_stays.layout')
@section('content')
@php
    $forOwners = ($post->audience ?? 'guests') === 'owners';
    $callNumber = !empty($site->contact_phone) ? preg_replace('/[^+0-9]/', '', $site->contact_phone) : null;
    $whatsappUrl = $websiteContactLinks['whatsapp_url'] ?? null;
@endphp
<article class="stay-post has-aside">
    <header class="stay-container stay-post-head">
        <nav class="stay-breadcrumb" aria-label="Breadcrumb"><a href="{{ route('public-stays.index', $tenant->slug) }}">Home</a> <span aria-hidden="true">›</span> @if($forOwners)<a href="{{ route('public-stays.blog', [$tenant->slug, 'for' => 'owners']) }}">Guides for property owners</a>@else<a href="{{ route('public-stays.blog', $tenant->slug) }}">Travel tips &amp; guides</a>@endif <span aria-hidden="true">›</span> <span aria-current="page">{{ \Illuminate\Support\Str::limit($post->title, 60) }}</span></nav>
        <h1>{{ $post->title }}</h1>
        <p class="stay-blog-meta"><time datetime="{{ \Carbon\CarbonImmutable::parse($post->published_at)->toDateString() }}">{{ \Carbon\CarbonImmutable::parse($post->published_at)->format('j F Y') }}</time> &middot; {{ $post->reading_minutes }} min read{{ $authorName ? ' · By '.$authorName : '' }}</p>
        @if($post->excerpt)<p class="stay-post-lead">{{ $post->excerpt }}</p>@endif
    </header>
    @if($post->cover_url)<figure class="stay-container stay-post-cover"><img src="{{ \App\Services\PublicBlog::sized($post->cover_url, 1200) }}" srcset="{{ \App\Services\PublicBlog::srcset($post->cover_url) }}" sizes="(max-width: 1240px) 100vw, 1200px" alt="{{ $post->cover_caption ?: $post->title }}" width="1200" height="640" fetchpriority="high" decoding="async">@if($post->cover_caption)<figcaption>{{ $post->cover_caption }}</figcaption>@endif</figure>@endif
    <div class="stay-container stay-post-layout">
        <div class="stay-post-main">
            @if($contents !== [])
                <nav class="stay-post-contents is-inline" aria-labelledby="post-contents-heading"><strong id="post-contents-heading">In this guide</strong><ol>@foreach($contents as $section)<li><a href="#{{ $section['id'] }}">{{ $section['title'] }}</a></li>@endforeach</ol></nav>
            @endif
            <div class="stay-post-body">{!! $html !!}</div>
            @if($forOwners)
            <aside class="stay-post-cta"><strong>Own a property in Kenya?</strong><p>We manage apartments and holiday homes end to end: listing, pricing, guests, cleaning and monthly reports. Tell us about your property and we'll call you.</p><div class="stay-track-actions"><a class="stay-button" href="{{ route('public-stays.owners', $tenant->slug) }}#owner-enquiry">Talk to us about your property &rarr;</a></div></aside>
            @else
            <aside class="stay-post-cta"><strong>Planning a stay?</strong><p>Browse our serviced apartments and send an enquiry. Our team confirms your dates and price.</p><div class="stay-track-actions"><a class="stay-button" href="{{ route('public-stays.index', $tenant->slug) }}#find-stay">Find a stay &rarr;</a>@if($whatsappUrl)<a class="stay-button secondary" href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer">WhatsApp us <span aria-hidden="true">&nearr;</span></a>@endif</div></aside>
            @endif
        </div>

        <aside class="stay-post-aside" aria-label="More from {{ $tenant->name }}">
            @if($forOwners && $ownerForm)
                <section class="stay-aside-card stay-aside-owner" id="owner-quick" aria-labelledby="owner-quick-heading">
                    <h2 id="owner-quick-heading">Get your property assessed</h2>
                    @if($ownerForm['sent'])
                        <p class="stay-aside-sent" role="status">Thank you. We have your details and will call you within a working day.</p>
                    @else
                        <p>Free and with no obligation. Leave your details and we'll call you about managing your property.</p>
                        @if($errors->any())<p class="stay-aside-error" role="alert">{{ $errors->first() }}</p>@endif
                        <form method="post" action="{{ route('public-stays.owners.enquire', $tenant->slug) }}">
                            <input type="hidden" name="form_issued_at" value="{{ $ownerForm['issued_at'] }}"><input type="hidden" name="form_token" value="{{ $ownerForm['token'] }}"><input type="hidden" name="article" value="{{ $post->slug }}">
                            <div class="stay-owner-honeypot" aria-hidden="true"><label for="owner-quick-website">Leave this empty</label><input id="owner-quick-website" name="website" tabindex="-1" autocomplete="off"></div>
                            <label>Your name<input name="full_name" required maxlength="150" autocomplete="name" value="{{ old('full_name') }}"></label>
                            <label>Phone or WhatsApp<input name="phone" type="tel" required maxlength="50" autocomplete="tel" placeholder="0712 345 678" value="{{ old('phone') }}"></label>
                            <label>Where is the property?<input name="location" required maxlength="150" placeholder="e.g. Kilimani, Nairobi" value="{{ old('location') }}"></label>
                            <label>Property type<select name="property_type" required>@foreach(\App\Services\OwnerEnquiries::PROPERTY_TYPES as $value => $label)<option value="{{ $value }}" @selected(old('property_type') === $value)>{{ $label }}</option>@endforeach</select></label>
                            <label class="stay-owner-consent"><input type="checkbox" name="consent" value="1" required @checked(old('consent'))> {{ $tenant->name }} may contact me about managing my property.</label>
                            <button class="stay-button" type="submit">Request a call</button>
                        </form>
                    @endif
                </section>
            @elseif(!empty($articleStays['stays']))
                <section class="stay-aside-card" aria-labelledby="article-stays-heading">
                    <h2 id="article-stays-heading">{{ $articleStays['matched'] ? 'Stay in '.implode(' & ', $articleStays['areas']) : 'Stays you might like' }}</h2>
                    <ul class="stay-aside-stays">
                        @foreach($articleStays['stays'] as $stay)
                            <li><a href="{{ route('public-stays.show', [$tenant->slug, $stay['slug']]) }}">
                                @if(!empty($stay['photos'][0]['url']))<img src="{{ $stay['photos'][0]['url'] }}?w=480" alt="" width="96" height="72" loading="lazy" decoding="async">@endif
                                <span><strong>{{ $stay['title'] }}</strong><small>{{ collect([$stay['neighbourhood'] ?? null, $stay['city'] ?? null])->filter()->unique()->implode(', ') }}</small>
                                @if((float) $stay['starting_price'] > 0)<em>From {{ $stay['currency'] }} {{ number_format((float) $stay['starting_price']) }} / night</em>@endif</span>
                            </a></li>
                        @endforeach
                    </ul>
                    <a class="stay-aside-more" href="{{ route('public-stays.index', $tenant->slug) }}#find-stay">See all stays &rarr;</a>
                </section>
            @endif

            @if($whatsappUrl || $callNumber || (!empty($site->google_rating)))
                <section class="stay-aside-card stay-aside-contact" aria-labelledby="aside-contact-heading">
                    <h2 id="aside-contact-heading">{{ $forOwners ? 'Talk to our team' : 'Questions about a stay?' }}</h2>
                    @include('public_stays.google_rating')
                    <div class="stay-aside-actions">
                        @if($whatsappUrl)<a class="stay-button" href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer">WhatsApp us <span aria-hidden="true">&nearr;</span></a>@endif
                        @if($callNumber)<a class="stay-button secondary" href="tel:{{ $callNumber }}">Call {{ $site->contact_phone }}</a>@endif
                    </div>
                </section>
            @endif

            @if($moreGuides->isNotEmpty())
                <section class="stay-aside-card" aria-labelledby="aside-guides-heading">
                    <h2 id="aside-guides-heading">{{ $forOwners ? 'More owner guides' : 'More travel guides' }}</h2>
                    <ul class="stay-aside-guides">@foreach($moreGuides as $guide)<li><a href="{{ route('public-stays.blog.show', [$tenant->slug, $guide->slug]) }}">{{ $guide->title }}</a></li>@endforeach</ul>
                </section>
            @endif

            @if($contents !== [])
                <nav class="stay-aside-card stay-aside-contents" aria-labelledby="aside-contents-heading" data-post-contents>
                    <h2 id="aside-contents-heading">In this guide</h2>
                    <ol>@foreach($contents as $section)<li><a href="#{{ $section['id'] }}">{{ $section['title'] }}</a></li>@endforeach</ol>
                </nav>
            @endif
        </aside>
    </div>
</article>
@if($related->isNotEmpty())
    <section class="stay-container stay-section" aria-labelledby="related-heading"><span class="stay-eyebrow">Keep reading</span><h2 id="related-heading">More guides</h2>
        <div class="stay-blog-grid">@foreach($related as $other)<article class="stay-blog-card"><div class="stay-blog-body"><p class="stay-blog-meta">{{ \Carbon\CarbonImmutable::parse($other->published_at)->format('j M Y') }}</p><h3><a href="{{ route('public-stays.blog.show', [$tenant->slug, $other->slug]) }}">{{ $other->title }}</a></h3><p>{{ $other->summary }}</p></div></article>@endforeach</div>
    </section>
@endif
@endsection
