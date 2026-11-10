@extends('public_stays.layout')
@section('content')
@php
    $browseUrl = route('public-stays.index', $tenant->slug);
    $whatsappUrl = $websiteContactLinks['whatsapp_url'] ?? null;
@endphp
<section class="stay-about-hero">
    <div class="stay-container">
        <span class="stay-eyebrow">Questions and policies</span>
        <h1>Good to know before you book</h1>
        <p>How booking, payment, check-in and cancellations work with {{ $tenant->name }}. Can't find your answer? Ask our team.</p>
        <div class="stay-track-actions">
            <a class="stay-button" href="{{ $browseUrl }}#find-stay">Find a stay <span aria-hidden="true">&rarr;</span></a>
            @if($whatsappUrl)<a class="stay-button secondary" href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer">Ask on WhatsApp <span aria-hidden="true">&nearr;</span></a>@endif
        </div>
    </div>
</section>

<div class="stay-container">
    <section class="stay-about-section" aria-labelledby="faq-heading">
        <span class="stay-eyebrow">FAQ</span>
        <h2 id="faq-heading">Frequently asked questions</h2>
        <div class="stay-faq">
            @foreach($info['faq'] as $index => $item)
                <details @if($index === 0) open @endif><summary>{{ $item['question'] }}</summary><p>{{ $item['answer'] }}</p></details>
            @endforeach
        </div>
    </section>

    <section class="stay-about-section" aria-labelledby="policy-heading">
        <span class="stay-eyebrow">Policies</span>
        <h2 id="policy-heading">Cancellations, deposits and payment</h2>
        <div class="stay-about-locations">
            <div class="stay-about-location"><h3>Cancellations</h3>@foreach($info['cancellation'] as $paragraph)<p>{{ $paragraph }}</p>@endforeach</div>
            <div class="stay-about-location"><h3>Deposit and payment</h3>@foreach($info['payment'] as $paragraph)<p>{{ $paragraph }}</p>@endforeach</div>
        </div>
    </section>

    <section class="stay-about-section stay-about-contact" aria-labelledby="faq-contact">
        <h2 id="faq-contact">Still have a question?</h2>
        <p>{{ $info['host_intro'] }} {{ $info['reply_time'] }}.</p>
        <div class="stay-track-actions">
            @if($whatsappUrl)<a class="stay-button" href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer">Chat on WhatsApp <span aria-hidden="true">&nearr;</span></a>@endif
            <a class="stay-button secondary" href="{{ route('public-stays.about', $tenant->slug) }}">About us</a>
        </div>
    </section>
</div>
@endsection
