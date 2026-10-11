{{-- Pixels publicitaires de la boutique (Meta, TikTok, Google Analytics) et jmTrack() : un appel, les trois outils --}}
@php
    $metaId = $store->marketingId('meta_pixel_id');
    $tiktokId = $store->marketingId('tiktok_pixel_id');
    $gaId = $store->marketingId('ga4_id');
@endphp
<script>
(function () {
  var META = { view_item: 'ViewContent', add_to_cart: 'AddToCart', begin_checkout: 'InitiateCheckout', purchase: 'Purchase' };
  var TIKTOK = { view_item: 'ViewContent', add_to_cart: 'AddToCart', begin_checkout: 'InitiateCheckout', purchase: 'CompletePayment' };
  // eventId : même identifiant que l'envoi par le serveur (API Conversions), pour que Meta ne compte l'achat qu'une fois
  window.jmTrack = function (event, data, eventId) {
    data = data || {};
    var items = data.items || [];
    var common = { value: data.value, currency: data.currency, content_type: 'product',
      content_ids: items.map(function (i) { return String(i.id) }),
      contents: items.map(function (i) { return { id: String(i.id), quantity: i.quantity, item_price: i.price } }) };
    try { if (window.fbq) fbq('track', META[event], common, eventId ? { eventID: eventId } : undefined) } catch (e) {}
    try {
      if (window.ttq) ttq.track(TIKTOK[event], { value: data.value, currency: data.currency, content_type: 'product',
        contents: items.map(function (i) { return { content_id: String(i.id), content_name: i.name, quantity: i.quantity, price: i.price } }) }, eventId ? { event_id: eventId } : undefined)
    } catch (e) {}
    try {
      if (window.gtag) gtag('event', event, { currency: data.currency, value: data.value, transaction_id: event === 'purchase' ? eventId : undefined,
        items: items.map(function (i) { return { item_id: String(i.id), item_name: i.name, price: i.price, quantity: i.quantity } }) })
    } catch (e) {}
  };
})();
</script>
@if ($metaId)
<script>
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/fr_FR/fbevents.js');
fbq('init', @json($metaId)); fbq('track', 'PageView');
</script>
@endif
@if ($tiktokId)
<script>
!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie"],ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e},ttq.load=function(e,n){var i="https://analytics.tiktok.com/i18n/pixel/events.js";ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=i,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};var o=d.createElement("script");o.type="text/javascript",o.async=!0,o.src=i+"?sdkid="+e+"&lib="+t;var a=d.getElementsByTagName("script")[0];a.parentNode.insertBefore(o,a)};
ttq.load(@json($tiktokId)); ttq.page();}(window,document,'ttq');
</script>
@endif
@if ($gaId)
<script async src="https://www.googletagmanager.com/gtag/js?id={{ urlencode($gaId) }}"></script>
<script>window.dataLayer = window.dataLayer || []; function gtag(){dataLayer.push(arguments)} gtag('js', new Date()); gtag('config', @json($gaId));</script>
@endif
