{{-- Author: ramanpal singh | URL: https://kwebby.com --}}
<link rel="stylesheet" href="/assets/chat-widget.css">
<aside class="cw" aria-label="Contact the office">
<button class="cw-toggle" aria-expanded="false" type="button">Message our office</button>
<section class="cw-panel" hidden aria-label="Website chat">
<h2>How can we help?</h2><p>Our team will reply when available. For an immediate safety emergency, contact local emergency services.</p>
<div class="cw-messages" role="log" aria-live="polite"></div>
<form class="cw-form">
<label class="cw-start">Your name<input name="name" autocomplete="name" maxlength="120" required></label>
<label class="cw-start">Email<input name="email" type="email" autocomplete="email" required></label>
<label>Message<textarea name="body" maxlength="3000" required></textarea></label>
<p class="cw-error" role="alert"></p><button type="submit">Send message</button>
</form></section></aside>
<script src="/assets/chat-widget.js" defer></script>
