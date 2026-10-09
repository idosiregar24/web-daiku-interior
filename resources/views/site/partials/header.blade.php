{{-- Header: brand, centred menu (the current page outlined), dark WhatsApp pill. "Layanan" opens the service pages. --}}
@php
    $home = route('site.home');
    $onHome = request()->routeIs('site.home');
    $anchor = fn (string $id) => $onHome ? "#{$id}" : "{$home}#{$id}";
    $services = $profile->services();
    $item = fn (bool $active) => 'rounded-full px-4 py-1.5 text-sm text-daiku-dark ring-1 transition '.($active ? 'ring-daiku-border' : 'ring-transparent hover:ring-daiku-border');
@endphp
<header class="sticky top-0 z-40 border-b border-transparent bg-background/90 backdrop-blur-md transition-colors duration-300 data-scrolled:border-daiku-border" data-site-header>
    <div class="container-site flex h-20 items-center justify-between gap-6">
        @include('site.partials.brand')

        <nav class="hidden items-center gap-1 lg:flex" aria-label="Menu utama">
            <div class="relative" data-dropdown>
                <a
                    href="{{ $anchor('layanan') }}"
                    class="{{ $item(request()->routeIs('site.services.*')) }} inline-flex items-center gap-1"
                    aria-haspopup="true"
                    aria-expanded="false"
                    aria-controls="menu-layanan"
                    data-dropdown-trigger
                >
                    Layanan
                    <x-site.icon name="chevron-down" class="size-3.5 transition-transform" data-dropdown-chevron />
                </a>
                <div id="menu-layanan" class="absolute top-full left-1/2 mt-3 hidden w-72 -translate-x-1/2 rounded-2xl border border-daiku-border bg-background p-2 shadow-xl shadow-daiku-dark/5" data-dropdown-panel>
                    <ul>
                        @foreach ($services as $service)
                            <li>
                                <a href="{{ $service['url'] }}" class="flex items-center justify-between rounded-xl px-3 py-2.5 text-sm text-daiku-dark hover:bg-daiku-gray">
                                    {{ $service['name'] }}
                                    <x-site.icon name="arrow-up-right" class="size-3.5 text-daiku-muted" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
            <a href="{{ route('site.portfolio.index') }}" class="{{ $item(request()->routeIs('site.portfolio.*')) }}">Portofolio</a>
            <a href="{{ $anchor('cara-kerja') }}" class="{{ $item(false) }}">Cara Kerja</a>
            <a href="{{ $anchor('tentang') }}" class="{{ $item(false) }}">Tentang</a>
            <a href="{{ $anchor('kontak') }}" class="{{ $item(false) }}">Kontak</a>
        </nav>

        <div class="flex items-center gap-2">
            <div class="hidden sm:block">
                <x-site.button :href="$profile->whatsappUrl()" external arrow size="sm">Konsultasi</x-site.button>
            </div>
            <button
                type="button"
                class="inline-flex size-11 items-center justify-center rounded-full text-daiku-dark ring-1 ring-daiku-border lg:hidden"
                aria-controls="menu-hp"
                aria-expanded="false"
                aria-label="Buka menu"
                data-menu-open
            >
                <x-site.icon name="menu" class="size-5" />
            </button>
        </div>
    </div>
</header>

{{-- Phone menu: a panel from the right; Esc, the X or the backdrop close it (site.ts). --}}
<div id="menu-hp" class="fixed inset-0 z-50 hidden lg:hidden" role="dialog" aria-modal="true" aria-label="Menu" data-menu>
    <div class="absolute inset-0 bg-daiku-dark/40 backdrop-blur-sm" data-menu-close></div>
    <div class="absolute inset-y-2 right-2 flex w-[min(22rem,calc(100vw-1rem))] flex-col overflow-y-auto rounded-3xl bg-background shadow-xl" data-menu-panel>
        <div class="flex h-16 items-center justify-between px-5">
            <span class="text-sm font-medium text-daiku-dark">Menu</span>
            <button type="button" class="inline-flex size-11 items-center justify-center rounded-full ring-1 ring-daiku-border" aria-label="Tutup menu" data-menu-close>
                <x-site.icon name="x" class="size-5" />
            </button>
        </div>
        <nav class="flex-1 px-3 pb-4" aria-label="Menu utama">
            <ul class="space-y-1 border-b border-daiku-border pb-4">
                <li><a href="{{ route('site.portfolio.index') }}" class="block rounded-2xl px-3 py-3 text-lg text-daiku-dark hover:bg-daiku-gray">Portofolio</a></li>
                <li><a href="{{ $anchor('cara-kerja') }}" class="block rounded-2xl px-3 py-3 text-lg text-daiku-dark hover:bg-daiku-gray" data-menu-close>Cara Kerja</a></li>
                <li><a href="{{ $anchor('tentang') }}" class="block rounded-2xl px-3 py-3 text-lg text-daiku-dark hover:bg-daiku-gray" data-menu-close>Tentang</a></li>
                <li><a href="{{ $anchor('kontak') }}" class="block rounded-2xl px-3 py-3 text-lg text-daiku-dark hover:bg-daiku-gray" data-menu-close>Kontak</a></li>
            </ul>
            <p class="px-3 pt-4 pb-2 text-xs text-daiku-muted">Layanan</p>
            <ul>
                @foreach ($services as $service)
                    <li><a href="{{ $service['url'] }}" class="block rounded-2xl px-3 py-2.5 text-[15px] text-daiku-dark hover:bg-daiku-gray">{{ $service['name'] }}</a></li>
                @endforeach
            </ul>
        </nav>
        <div class="p-4">
            <x-site.button :href="$profile->whatsappUrl()" external icon="whatsapp" size="lg" variant="yellow" class="w-full">Konsultasi Gratis</x-site.button>
        </div>
    </div>
</div>
