<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- Título del HTML inicial: el SEO propio de la página o, en su defecto, la marca (no APP_NAME). --}}
    <title inertia>{{ $seo['title'] ?? 'F&C Consultores' }}</title>
   <link rel="icon" href="{{ asset('images/logo-fyc-web.png') }}" type="image/png">

    {{-- SEO en el HTML inicial (sin SSR): solo cuando el controlador envía $seo con withViewData. --}}
    @isset($seo)
    <meta name="description" content="{{ $seo['description'] }}">
    <link rel="canonical" href="{{ $seo['url'] }}">
    <meta property="og:type" content="{{ $seo['type'] }}">
    <meta property="og:site_name" content="{{ $seo['site_name'] }}">
    <meta property="og:locale" content="{{ $seo['locale'] }}">
    <meta property="og:title" content="{{ $seo['title'] }}">
    <meta property="og:description" content="{{ $seo['description'] }}">
    <meta property="og:url" content="{{ $seo['url'] }}">
    <meta property="og:image" content="{{ $seo['image'] }}">
    <meta property="og:image:alt" content="{{ $seo['image_alt'] }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $seo['title'] }}">
    <meta name="twitter:description" content="{{ $seo['description'] }}">
    <meta name="twitter:image" content="{{ $seo['image'] }}">
    @endisset

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Figtree:ital,wght@0,300..900;1,300..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded" />

    @routes
    @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
    
    @inertiaHead
</head>

<body class="bg-slate-50 antialiased">
    @inertia
</body>
</html>