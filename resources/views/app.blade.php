<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Branding from Pengaturan Situs (shared by HandleInertiaRequests as `site`). --}}
        @php($site = $page['props']['site'] ?? null)

        <title inertia>{{ $site['name'] ?? config('app.name', 'Laravel') }}</title>
        <link rel="icon" href="{{ $site['faviconUrl'] ?? $site['logoUrl'] ?? asset('favicon.ico') }}">

        {{-- Sprint 13 H7 — installable on a phone's home screen (PwaController, public/sw.js). --}}
        <link rel="manifest" href="{{ route('pwa.manifest') }}">
        <meta name="theme-color" content="{{ \App\Http\Controllers\PwaController::THEME_COLOR }}">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="{{ $site['name'] ?? config('app.name') }}">
        <link rel="apple-touch-icon" href="{{ route('pwa.icon', ['size' => 180, 'purpose' => 'any']) }}">

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/Pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
