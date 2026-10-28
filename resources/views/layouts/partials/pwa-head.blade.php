{{-- What lets a phone or tablet install this as an app (docs/pwa.md). Shared by the signed-in and the sign-in layouts. --}}
<meta name="theme-color" content="#1f252b">
<link rel="manifest" href="{{ route('pwa.manifest') }}">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('icons/favicon-32.png') }}">
<link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="pwa-worker" content="{{ asset('sw.js') }}">
