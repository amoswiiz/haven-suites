@php
    $gaId = (string) ($site->ga_measurement_id ?? '');
    $pixelId = (string) ($site->meta_pixel_id ?? '');
    // Staff visits and private previews would distort the numbers, so only guests are counted.
    $trackVisitors = empty($preview) && !auth()->check() && ($gaId !== '' || $pixelId !== '');
@endphp
@if($trackVisitors)
    @if($gaId !== '')
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ $gaId }}"></script>
        <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config',@json($gaId));</script>
    @endif
    @if($pixelId !== '')
        <script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init',@json($pixelId));fbq('track','PageView');</script>
    @endif
    <script>
    window.havenTrack=function(name,params){params=params||{};try{if(window.gtag)gtag('event',name,params);if(window.fbq){var meta={generate_lead:'Lead',whatsapp_click:'Contact',phone_click:'Contact',view_item:'ViewContent'}[name];if(meta)fbq('track',meta,params)}}catch(e){}};
    document.addEventListener('click',function(e){var a=e.target.closest&&e.target.closest('a[href]');if(!a)return;var href=a.getAttribute('href')||'';var listing=@json(is_array($listing ?? null) ? ($listing['title'] ?? null) : null)||undefined;
        if(/wa\.me|whatsapp\.com/i.test(href))havenTrack('whatsapp_click',{listing:listing,link_text:(a.textContent||'').trim().slice(0,60)});
        else if(href.indexOf('tel:')===0)havenTrack('phone_click',{listing:listing});},true);
    @if(is_array($listing ?? null) && !empty($listing['title']))havenTrack('view_item',{items:[{item_id:@json((string) ($listing['listing_code'] ?? $listing['id'] ?? '')),item_name:@json($listing['title']),price:{{ (float) ($listing['starting_price'] ?? 0) }}}],currency:@json($listing['currency'] ?? 'KES')});@endif
    @hasSection('conversion')havenTrack('generate_lead',{method:@json(trim($__env->yieldContent('conversion')))});@endif
    </script>
@endif
