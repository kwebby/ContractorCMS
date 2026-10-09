{{-- Author: ramanpal singh | URL: https://kwebby.com --}}
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><meta name="robots" content="noindex"><title>{{ $title }} — ContractorCMS</title><link rel="stylesheet" href="/auth.css"><script src="/auth-security.js" defer></script></head>
<body><main><a href="/app" class="brand">ContractorCMS</a><h1>{{ $title }}</h1>
@if(session('success'))<p class="success">{{ session('success') }}</p>@endif
@if($errors->any())<p role="alert" class="error">{{ $errors->first() }}</p>@endif
@if($mode==='security')
<p>Use an authenticator app to protect privileged business and financial actions.</p>
<form id="mfa-setup">@csrf<label>Current password<input type="password" name="password" required autocomplete="current-password"></label><button>Set up authenticator</button></form>
<div id="mfa-secret" hidden><p>Add this secret to your authenticator app:</p><code id="secret-value"></code><form id="mfa-confirm"><label>Six-digit code<input name="code" inputmode="numeric" pattern="[0-9]{6}" required autocomplete="one-time-code"></label><button>Confirm setup</button></form></div><p id="mfa-message" role="status"></p>
@else
<form method="post" action="{{ match($mode){'forgot'=>'/forgot-password','reset'=>'/reset-password','invite'=>'/invitations/accept',default=>'/mfa'} }}">@csrf
@if(isset($token))<input type="hidden" name="token" value="{{ $token }}">@endif
@if($mode==='forgot')<label>Email address<input type="email" name="email" required autocomplete="email"></label>@endif
@if($mode==='invite')<label>Your name<input name="name" required autocomplete="name"></label>@endif
@if(in_array($mode,['reset','invite']))<label>Password<input type="password" name="password" minlength="12" required autocomplete="new-password"></label><label>Confirm password<input type="password" name="password_confirmation" minlength="12" required autocomplete="new-password"></label>@endif
@if($mode==='mfa')<label>Authentication code<input name="code" inputmode="numeric" pattern="[0-9]{6}" required autocomplete="one-time-code"></label>@endif
<button>{{ match($mode){'forgot'=>'Send reset link','reset'=>'Save password','invite'=>'Create account',default=>'Verify code'} }}</button></form>
@endif
<a class="back" href="/login">Back to sign in</a></main></body></html>
