{{-- Author: ramanpal singh | URL: https://kwebby.com --}}
<!doctype html>
<html lang="{{ $metadata['locale'] }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $metadata['title'] }}</title><meta name="description" content="{{ $metadata['description'] }}"><meta name="robots" content="{{ $metadata['robots'] }}"><link rel="canonical" href="{{ $metadata['canonical'] }}">
    <meta property="og:title" content="{{ $metadata['og_title'] }}"><meta property="og:description" content="{{ $metadata['og_description'] }}"><meta property="og:type" content="{{ $metadata['og_type'] }}"><meta property="og:url" content="{{ $metadata['canonical'] }}"><meta property="og:locale" content="{{ $metadata['og_locale'] }}"><meta property="og:site_name" content="{{ $business['name'] ?? 'Contractor' }}">
    @if ($metadata['og_image'])<meta property="og:image" content="{{ $metadata['og_image'] }}"><meta property="og:image:alt" content="{{ $metadata['og_image_alt'] }}">@endif
    <meta name="twitter:card" content="{{ $metadata['twitter_card'] }}"><meta name="twitter:title" content="{{ $metadata['og_title'] }}"><meta name="twitter:description" content="{{ $metadata['og_description'] }}">
    @if ($metadata['og_image'])<meta name="twitter:image" content="{{ $metadata['og_image'] }}"><meta name="twitter:image:alt" content="{{ $metadata['og_image_alt'] }}">@endif
    @if ($metadata['verification'])<meta name="google-site-verification" content="{{ $metadata['verification'] }}">@endif
    @if ($metadata['schema'])<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" type="application/ld+json">{!! $metadata['schema'] !!}</script>@endif
    <link rel="stylesheet" href="/assets/public-site.css"><link rel="stylesheet" href="/assets/website-fonts.css"><link rel="stylesheet" href="/assets/home-sections.css">
    @if(!empty($website['appearance']['favicon_media_id']))<link rel="icon" href="/files/{{ $website['appearance']['favicon_media_id'] }}">@endif
    @if ($websiteCss)<style>{!! $websiteCss !!}</style>@endif
    <script src="/assets/public-site.js" defer></script>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
{!! $managedHeader !!}
<main id="main" class="tool-page">
    <span class="eyebrow">A USEFUL FIRST STEP</span><h1>{{ $tool['name'] }}</h1><p class="tool-description">{{ $tool['description'] }}</p>
    @if ($available)
    <form class="service-form" data-tool-form data-tool="{{ $slug }}">
        <label>Your project details<textarea name="details" required minlength="15" maxlength="12000" rows="8" placeholder="{{ $tool['placeholder'] }}"></textarea></label>
        <p class="small">Your input is sent to OpenAI to prepare this brief. Please leave out payment details, passwords, and sensitive personal information.</p>
        <button type="submit" class="button button-dark">Prepare my brief <span aria-hidden="true">↗</span></button>
        <p role="status" aria-live="polite" class="form-result"></p>
    </form>
    <section data-tool-result hidden class="tool-result" aria-label="Your planning brief"><span class="eyebrow">YOUR PLANNING BRIEF</span><p data-summary></p><div data-sections></div><p class="small">An AI-assisted organizing draft for discussion with a qualified contractor. This is not an assessment, diagnosis, or quote.</p>
    <form class="service-form" data-report-form>
        <h2>Keep a copy</h2><p>We’ll send a confirmation link, then email your brief.</p>
        <div class="form-grid"><label>Your name<input name="name" autocomplete="name" required maxlength="120"></label><label>Email address<input name="email" type="email" autocomplete="email" required maxlength="254"></label></div>
        <label class="consent"><input type="checkbox" name="contact_requested"><span>I’d like the team to contact me about this project. Optional.</span></label>
        <label class="consent"><input type="checkbox" name="marketing_consent"><span>I’d also like occasional service tips and offers. Optional.</span></label>
        <button class="button button-dark" type="submit">Email my brief <span aria-hidden="true">↗</span></button><p role="status" aria-live="polite" class="form-result"></p>
    </form>
    </section>
    @else
    <div class="tool-result"><h2>This planning tool is currently unavailable.</h2><p>Tell our team what you have in mind and we’ll help you arrange the next step.</p><a class="button button-dark" href="/#request-service">Request service <span aria-hidden="true">↗</span></a></div>
    @endif
    <nav class="tool-navigation" aria-label="Planning tools">@foreach (\App\Automation\LeadToolService::TOOLS as $key => $item)<a href="/tools/{{ $key }}">{{ $item['name'] }} ↗</a>@endforeach</nav>
</main>
{!! $managedFooter !!}
@includeIf('public.chat-widget')
</body>
</html>
