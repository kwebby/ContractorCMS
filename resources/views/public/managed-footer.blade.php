{{-- Author: ramanpal singh | URL: https://kwebby.com --}}
<footer class="site-footer managed-footer">
    <div><a class="brand" href="/">{{ $business['name'] ?? 'Your local contractor' }}</a>
    @if(!empty($business['address']))<address>{{ $business['address'] }}<br>{{ implode(', ', array_filter([$business['city'] ?? '', $business['region'] ?? '', $business['postal_code'] ?? '', $business['country'] ?? ''])) }}</address>@endif
    @if(!empty($business['phone']))<p><a href="tel:{{ preg_replace('/[^+0-9]/', '', $business['phone']) }}">{{ $business['phone'] }}</a></p>@endif
    @if(!empty($business['email']))<p><a href="mailto:{{ $business['email'] }}">{{ $business['email'] }}</a></p>@endif
    <p>© {{ now()->year }} {{ $business['name'] ?? 'Your local contractor' }}</p></div>
    @if(count($footerLinks))<nav aria-label="Footer navigation">@foreach($footerLinks as $link)<a href="{{ $link['href'] }}">{{ $link['label'] }}</a>@endforeach</nav>@endif
    @if(!empty($business['hours']))<div class="public-hours"><h2>Hours</h2><dl>@foreach($business['hours'] as $hours)<div><dt>{{ ucfirst($hours['day']) }}</dt><dd>{{ ($hours['closed'] ?? false) ? 'Closed' : $hours['opens'].'–'.$hours['closes'] }}</dd></div>@endforeach</dl><p>{{ $business['timezone'] ?? '' }}</p>
    @foreach($business['special_hours'] ?? [] as $hours)<p>{{ $hours['date'] }}: {{ ($hours['closed'] ?? false) ? 'Closed' : $hours['opens'].'–'.$hours['closes'] }}</p>@endforeach</div>@endif
    @if(!empty($business['profile_urls']))<nav aria-label="Business profiles">@foreach($business['profile_urls'] as $profile)<a href="{{ $profile['url'] }}" rel="noopener noreferrer">{{ $profile['label'] }}</a>@endforeach</nav>@endif
</footer>
