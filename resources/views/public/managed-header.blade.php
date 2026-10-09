{{-- Author: ramanpal singh | URL: https://kwebby.com --}}
<div class="utility-bar"><span>{{ !empty($business['service_areas']) ? 'Serving '.implode(', ', array_slice($business['service_areas'], 0, 3)) : ($business['trade'] ?? 'Local services') }}</span>@if($website['navigation']['show_client_portal'] ?? true)<a href="/login">Client portal</a>@endif</div>
<header class="site-header managed-header">
    <a class="brand" href="/">@if(!empty($website['appearance']['logo_media_id']))<img class="site-logo" src="/files/{{ $website['appearance']['logo_media_id'] }}" alt="" width="48" height="48">@else<span class="brand-mark" aria-hidden="true">⌂</span>@endif<span>{{ $business['name'] ?? 'Your local contractor' }}</span></a>
    <nav class="desktop-navigation" aria-label="Main navigation">@foreach($primaryLinks as $link)<a href="{{ $link['href'] }}">{{ $link['label'] }}</a>@endforeach</nav>
    @if($headerButton['href'] ?? '')<a class="button button-small home-button-{{ $headerButton['style'] ?? 'primary' }}" href="{{ $headerButton['href'] }}">{{ $headerButton['label'] }}</a>@endif
    @if(count($primaryLinks))<details class="mobile-navigation"><summary>Menu</summary><nav aria-label="Mobile navigation">@foreach($primaryLinks as $link)<a href="{{ $link['href'] }}">{{ $link['label'] }}</a>@endforeach</nav></details>@endif
</header>
