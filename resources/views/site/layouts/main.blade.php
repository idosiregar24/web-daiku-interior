{{--
    Sprint 20 — the company profile's page shell: SEO head, header, footer,
    floating WhatsApp button. Content comes from $profile (ProfileContent);
    pages set: title, description, optional canonical / robots / og_image /
    og_type, and push JSON-LD or preloads to the `head` stack.
--}}
@php
    // Inline @section values arrive HTML-escaped; decode once so {{ }} escapes exactly once.
    $section = fn (string $name) => html_entity_decode(trim($__env->yieldContent($name)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $pageTitle = $section('title') ?: $profile->name().' — Jasa Desain & Interior '.$profile->city();
    $pageDescription = $section('description') ?: $profile->heroSubheadline();
    $canonical = $section('canonical') ?: url()->current();
    $robots = $section('robots') ?: 'index, follow';
    $og = $profile->ogImage();
    $ogImage = $section('og_image') ?: $og['src'];
    $whatsappContext = $section('whatsapp_context') ?: null;
@endphp
<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        {{-- Motion's hidden starting states apply only once JS runs (site.css `.js [data-reveal]`). --}}
        <script>document.documentElement.classList.add('js')</script>
        <title>{{ $pageTitle }}</title>
        <meta name="description" content="{{ $pageDescription }}">
        <meta name="robots" content="{{ $robots }}">
        <link rel="canonical" href="{{ $canonical }}">

        <meta property="og:type" content="{{ $section('og_type') ?: 'website' }}">
        <meta property="og:site_name" content="{{ $profile->name() }}">
        <meta property="og:locale" content="id_ID">
        <meta property="og:title" content="{{ $pageTitle }}">
        <meta property="og:description" content="{{ $pageDescription }}">
        <meta property="og:url" content="{{ $canonical }}">
        <meta property="og:image" content="{{ $ogImage }}">
        @unless ($section('og_image'))
            <meta property="og:image:width" content="{{ $og['width'] }}">
            <meta property="og:image:height" content="{{ $og['height'] }}">
        @endunless
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $pageTitle }}">
        <meta name="twitter:description" content="{{ $pageDescription }}">
        <meta name="twitter:image" content="{{ $ogImage }}">

        @if ($profile->googleSiteVerification())
            <meta name="google-site-verification" content="{{ $profile->googleSiteVerification() }}">
        @endif

        <link rel="icon" href="{{ $profile->faviconUrl() ?? asset('favicon.ico') }}">
        <link rel="apple-touch-icon" href="{{ route('pwa.icon', ['size' => 180, 'purpose' => 'any']) }}">
        <meta name="theme-color" content="{{ \App\Http\Controllers\PwaController::THEME_COLOR }}">

        @stack('head')

        @vite(['resources/css/site.css', 'resources/js/site.ts'])
    </head>
    <body class="flex min-h-svh flex-col">
        <a href="#isi" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-md focus:bg-daiku-dark focus:px-4 focus:py-2 focus:text-daiku-cream">
            Langsung ke isi
        </a>

        @include('site.partials.header')

        <main id="isi" class="flex-1">
            @yield('content')
        </main>

        @include('site.partials.footer')

        {{-- K1 — WhatsApp is the only contact; always one tap away on a phone. --}}
        <a
            href="{{ $profile->whatsappUrl($whatsappContext) }}"
            target="_blank"
            rel="noopener"
            class="fixed right-4 bottom-4 z-30 inline-flex size-14 items-center justify-center rounded-full bg-daiku-yellow text-daiku-dark shadow-lg ring-1 ring-daiku-dark/10 transition hover:bg-daiku-yellow-dark sm:right-6 sm:bottom-6"
            aria-label="Chat WhatsApp {{ $profile->name() }}"
            data-floating-wa
        >
            <x-site.icon name="whatsapp" class="size-7" />
        </a>
    </body>
</html>
