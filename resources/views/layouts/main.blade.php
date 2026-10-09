<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" {!! $themeState->attributes() !!}>
<head>
    @include('partials.head')

    @hasSection('title')
        <title>@yield('title') — ReUp</title>
    @else
        <title>ReUp — Buy airtime, data and pay bills instantly</title>
    @endif

    <meta name="description" content="@yield('meta_description', 'Buy airtime, data bundles, cable TV and electricity tokens instantly with ReUp. Fast, secure and stress-free — anytime, anywhere.')">
    <meta name="keywords" content="@yield('meta_keywords', 'airtime, data bundle, cable tv, electricity token, WAEC PIN, JAMB PIN, Nigeria, bill payment')">
    <meta name="robots" content="index, follow, max-image-preview:large">
    <link rel="canonical" href="{{ url()->current() }}">

    {{-- Open Graph --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="ReUp">
    <meta property="og:title" content="@yield('title', 'ReUp — Buy airtime, data and pay bills instantly')">
    <meta property="og:description" content="@yield('meta_description', 'Buy airtime, data bundles, cable TV and electricity tokens instantly with ReUp.')">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:locale" content="en_NG">
    <meta name="twitter:card" content="summary_large_image">

    @stack('styles')
</head>
<body class="min-h-screen bg-surface antialiased">
    @include('layouts.navbar')

    @yield('main')

    @include('layouts.footer')
    @include('partials.support-widget')

    {{-- Flash messages, delivered through the same toast channel as the
         dashboard layout. Without this the public pages — the contact form in
         particular, which redirects back with a success message — completed
         with no visible outcome at all. --}}
    @include('partials.flash')

    @stack('scripts')
</body>
</html>
