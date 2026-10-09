{{-- Author: ramanpal singh | URL: https://kwebby.com --}}
<section class="contact-section" id="request-service" aria-labelledby="contact-heading">
    <div class="contact-intro">
        @if(!empty($contactSection['eyebrow']))<span class="eyebrow">{{ $contactSection['eyebrow'] }}</span>@endif
        <h2 id="contact-heading">{{ $contactSection['heading'] ?? 'What can we help you with?' }}</h2>
        @if(!empty($contactSection['body_html']))<div class="contact-copy">{!! $contactSection['body_html'] !!}</div>@else<p>Share a few details. Our team will follow up to discuss your needs and arrange the right next step.</p>@endif
        @if (!empty($business['phone']))<a class="phone-link" href="tel:{{ preg_replace('/[^+0-9]/', '', $business['phone']) }}">{{ $business['phone'] }} ↗</a>@endif
        @if(!empty($contactSection['buttons']))<div class="home-actions">@foreach($contactSection['buttons'] as $button)<a class="home-button home-button-{{ $button['style'] }}" href="{{ $button['href'] }}">{{ $button['label'] }}</a>@endforeach</div>@endif
        @if(!empty($contactSection['image']))<img class="contact-image home-focal-x-{{ $contactSection['image']['position_x'] ?? 50 }} home-focal-y-{{ $contactSection['image']['position_y'] ?? 50 }}" src="{{ $contactSection['image']['url'] ?? '' }}" alt="{{ $contactSection['image']['alt'] ?? '' }}" loading="lazy" width="600" height="400">@endif
        <p class="small">A request is not a confirmed appointment. For an immediate safety emergency, contact your local emergency services.</p>
    </div>
    <form class="service-form" data-lead-form method="post" action="/api/v1/public/leads">
        @csrf
        <div class="form-grid"><label>Your name<input name="name" autocomplete="name" required maxlength="120" placeholder="Full name"></label><label>Email address<input name="email" type="email" autocomplete="email" required maxlength="254" placeholder="you@example.com"></label></div>
        <div class="form-grid"><label>Phone number<input name="phone" type="tel" autocomplete="tel" maxlength="40" placeholder="Phone number"></label><label>Service needed<input name="service" required maxlength="100" placeholder="How can we help?"></label></div>
        <label>Tell us a little more<textarea name="notes" rows="4" required maxlength="5000" placeholder="What needs attention, and when would you like help?"></textarea></label>
        <div class="honeypot" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>
        <label class="consent"><input name="marketing_consent" type="checkbox" value="1"> <span>I'd also like occasional service tips and offers by email. Optional.</span></label>
        <button class="button button-dark" type="submit">Request service <span aria-hidden="true">↗</span></button>
        <p class="form-result" role="status" aria-live="polite"></p>
        <p class="small">We’ll use your details to respond to this request.</p>
    </form>
</section>
