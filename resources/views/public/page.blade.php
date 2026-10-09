{{-- Author: ramanpal singh | URL: https://kwebby.com --}}
<!doctype html>
<html lang="{{ $metadata['locale'] }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $metadata['title'] }}</title>
    @if ($metadata['description'])<meta name="description" content="{{ $metadata['description'] }}">@endif
    <meta name="robots" content="{{ $metadata['robots'] }}">
    <link rel="canonical" href="{{ $metadata['canonical'] }}">
    @foreach ($metadata['alternates'] as $locale => $url)<link rel="alternate" hreflang="{{ $locale }}" href="{{ $url }}">@endforeach
    <meta property="og:type" content="{{ $metadata['og_type'] }}">
    <meta property="og:title" content="{{ $metadata['og_title'] }}">
    <meta property="og:description" content="{{ $metadata['og_description'] }}">
    <meta property="og:url" content="{{ $metadata['canonical'] }}">
    <meta property="og:locale" content="{{ $metadata['og_locale'] }}">
    <meta property="og:site_name" content="{{ $business['name'] ?? 'Contractor' }}">
    @if ($metadata['og_image'])
    <meta property="og:image" content="{{ $metadata['og_image'] }}">
    <meta property="og:image:alt" content="{{ $metadata['og_image_alt'] }}">
    @endif
    <meta name="twitter:card" content="{{ $metadata['twitter_card'] }}">
    <meta name="twitter:title" content="{{ $metadata['og_title'] }}">
    <meta name="twitter:description" content="{{ $metadata['og_description'] }}">
    @if ($metadata['og_image'])<meta name="twitter:image" content="{{ $metadata['og_image'] }}"><meta name="twitter:image:alt" content="{{ $metadata['og_image_alt'] }}">@endif
    @if ($metadata['og_type'] === 'article' && $metadata['published_time'])<meta property="article:published_time" content="{{ $metadata['published_time'] }}">@endif
    @if ($metadata['og_type'] === 'article' && $metadata['modified_time'])<meta property="article:modified_time" content="{{ $metadata['modified_time'] }}">@endif
    @if ($metadata['verification'])<meta name="google-site-verification" content="{{ $metadata['verification'] }}">@endif
    @if ($metadata['schema'])<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" type="application/ld+json">{!! $metadata['schema'] !!}</script>@endif
    <link rel="stylesheet" href="/assets/public-site.css">
    <link rel="stylesheet" href="/assets/website-fonts.css">
    <link rel="stylesheet" href="/assets/home-sections.css">
    @if(!empty($website['appearance']['favicon_media_id']))<link rel="icon" href="/files/{{ $website['appearance']['favicon_media_id'] }}">@endif
    @foreach ($theme['styles'] ?? [] as $style)<link rel="stylesheet" href="{{ $style }}">@endforeach
    @if ($theme['settings_css'] ?? '')<style>{!! $theme['settings_css'] !!}</style>@endif
    @if ($layoutCss)<style>{!! $layoutCss !!}</style>@endif
    @if ($websiteCss ?? '')<style>{!! $websiteCss !!}</style>@endif
    <script src="/assets/public-site.js" defer></script>
</head>
<body class="{{ ($guided ?? false) ? 'guided-home' : '' }}">
<a class="skip-link" href="#main">Skip to content</a>
@if ($preview)<div class="preview-banner">Preview · This page is not indexed. Publish your changes when you are ready.</div>@endif
@if ($theme)
<main id="main" class="theme-content">{!! $theme['body'] !!}</main>
@else
    {!! $managedHeader !!}
    <main id="main">
    @if ($guided)
        {!! $content !!}
    @elseif ($page['type'] === 'home')
        <section class="hero">
            <div class="hero-copy"><span class="eyebrow">{{ strtoupper($business['trade'] ?? 'HOME SERVICES') }} · PERSONAL SERVICE</span><h1>{{ $page['title'] }}</h1><p>{{ $page['excerpt'] }}</p><div class="hero-actions"><a class="button button-dark" href="#request-service">Let’s talk about your project <span aria-hidden="true">↗</span></a>@if (!empty($business['phone']))<a class="text-link" href="tel:{{ preg_replace('/[^+0-9]/', '', $business['phone']) }}">Give us a call</a>@endif</div><div class="hero-note"><span aria-hidden="true">✓</span> Clear communication, from your first question to the final detail.</div></div>
            <div class="house-art" aria-hidden="true"><div class="art-sun"></div><div class="art-grid"></div><div class="house-roof"></div><div class="house-body"><div class="house-window"></div><div class="house-door"></div></div><div class="art-line"></div><div class="art-caption"><span>CARE STARTS HERE</span><span>01 / YOUR HOME</span></div></div>
        </section>
        @if ($content)<section class="article-content home-content">{!! $content !!}</section>@endif
        @php($services = array_values(array_filter($pages, fn ($item) => $item['type'] === 'service')))
        @if (count($services))
        <section class="services-section"><div class="section-heading"><div><span class="eyebrow">OUR SERVICES</span><h2>The right help.<br>For your next project.</h2></div><p>Explore what we do, then tell us what you have in mind.</p></div><div class="service-list">@foreach ($services as $index => $service)<a href="/p/{{ $service['slug'] }}" class="service-row"><span class="service-number">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span><h3>{{ $service['title'] }}</h3><p>{{ $service['excerpt'] ?? '' }}</p><span class="arrow" aria-hidden="true">↗</span></a>@endforeach</div></section>
        @endif
        <section class="process-section"><span class="eyebrow">A CLEAR NEXT STEP</span><h2>From “what now?”<br>to a plan that makes sense.</h2><div class="process-grid"><div><span>01</span><h3>Tell us what’s happening</h3><p>Share the details, your priorities, and a time that works for you.</p></div><div><span>02</span><h3>Understand the options</h3><p>We’ll discuss the scope, next steps, and estimate before work is approved.</p></div><div><span>03</span><h3>Stay in the loop</h3><p>Track your request, review documents, and message the team through your client portal.</p></div></div></section>
    @else
        <section class="page-heading"><a class="breadcrumb" href="/">Home</a><span class="eyebrow">{{ strtoupper(str_replace('-', ' ', $page['type'])) }}</span><h1>{{ $page['title'] }}</h1>@if (!empty($page['excerpt']))<p>{{ $page['excerpt'] }}</p>@endif</section>
        <article class="article-content">{!! $content !!}
        @if (!empty($page['author_name']) || !empty($page['reviewed_at']))<div class="article-byline">@if (!empty($page['author_name']))Written by {{ $page['author_name'] }}.@endif @if (!empty($page['reviewed_at']))Reviewed {{ substr($page['reviewed_at'], 0, 10) }}.@endif</div>@endif
        @foreach ($page['faq'] ?? [] as $faq)<details class="faq"><summary>{{ $faq['question'] }}</summary><p>{{ $faq['answer'] }}</p></details>@endforeach
        @if (!empty($page['sources']))<aside class="sources"><h2>Sources</h2><ul>@foreach ($page['sources'] as $source)<li><a href="{{ $source }}" rel="noopener noreferrer">{{ parse_url($source, PHP_URL_HOST) }}</a></li>@endforeach</ul></aside>@endif
        </article>
    @endif
    @if (!$guided && count($toolLinks))<section class="services-section"><span class="eyebrow">START WITH A PLAN</span><div class="section-heading"><h2>A clearer brief.<br>A better conversation.</h2><p>Use a planning tool to organize your questions before you speak with our team.</p></div><div class="service-list">@foreach ($toolLinks as $slug => $tool)<a class="service-row" href="/tools/{{ $slug }}"><span class="service-number">↗</span><h3>{{ $tool['name'] }}</h3><p>{{ $tool['description'] }}</p><span class="arrow" aria-hidden="true">↗</span></a>@endforeach</div></section>@endif
    @unless($guided) @include('public.contact') @endunless
    </main>
    {!! $managedFooter !!}
@endif
@includeIf('public.chat-widget')
</body>
</html>
