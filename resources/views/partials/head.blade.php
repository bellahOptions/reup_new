{{--
    Shared <head> primitives.

    The favicon block was duplicated verbatim across four layouts; the
    Tailwind CDN <script> that used to live beside it is gone — styles are now
    compiled by Vite (see vite.config.js) and pulled in with @vite below.
--}}
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="#2AB70D">

<link rel="shortcut icon" href="{{ asset('images/reup-icon-06.jpg') }}" type="image/x-icon">
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/apple-icon-180x180.png') }}">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32x32.png') }}">
<link rel="icon" type="image/png" sizes="16x16" href="{{ asset('images/favicon-16x16.png') }}">
<link rel="manifest" href="{{ asset('images/manifest.json') }}">
<meta name="msapplication-TileColor" content="#2AB70D">
<meta name="msapplication-TileImage" content="{{ asset('images/ms-icon-144x144.png') }}">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link
    href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300..800;1,9..40,300..800&display=swap"
    rel="stylesheet">

@vite(['resources/css/app.css', 'resources/js/app.js'])

@if(config('services.analytics.ga_id'))
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ config('services.analytics.ga_id') }}"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag() { dataLayer.push(arguments); }
        gtag('js', new Date());
        gtag('config', @json(config('services.analytics.ga_id')));
    </script>
@endif

@stack('head')
