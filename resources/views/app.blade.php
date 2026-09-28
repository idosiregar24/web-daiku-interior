<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Branding from Pengaturan Situs (shared by HandleInertiaRequests as `site`). --}}
        @php($site = $page['props']['site'] ?? null)

        <title inertia>{{ $site['name'] ?? config('app.name', 'Laravel') }}</title>
        <link rel="icon" href="{{ $site['faviconUrl'] ?? $site['logoUrl'] ?? asset('favicon.ico') }}">

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
