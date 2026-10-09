{{-- Hero: one wide rounded photo, the page's only H1 centred on it (no badge above it), WhatsApp CTA, a plain line of facts and social links in the corners. --}}
@php($hero = $profile->heroImage())
<section class="container-site pt-2">
    <div class="relative isolate overflow-hidden rounded-[2rem] bg-daiku-dark">
        @if ($hero)
            <img
                src="{{ $hero['src'] }}"
                @if ($hero['srcset']) srcset="{{ $hero['srcset'] }}" sizes="(min-width: 1152px) 1088px, 100vw" @endif
                width="{{ $hero['width'] }}"
                height="{{ $hero['height'] }}"
                alt="{{ $hero['alt'] }}"
                fetchpriority="high"
                decoding="async"
                class="hero-zoom absolute inset-0 -z-10 size-full object-cover"
            >
        @endif
        <div class="absolute inset-0 -z-10 bg-linear-to-b from-daiku-dark/60 via-daiku-dark/25 to-daiku-dark/65"></div>

        <div class="flex min-h-[36rem] flex-col items-center px-5 pt-20 pb-5 text-center sm:min-h-[38rem] sm:px-10 sm:pt-28 lg:min-h-[40rem]">
            <h1 style="--d: 150ms" class="hero-in max-w-4xl text-4xl leading-[1.08] font-light tracking-tight text-daiku-cream sm:text-6xl lg:text-7xl">
                {{ $profile->heroHeadline() }}
            </h1>
            <p style="--d: 300ms" class="hero-in mt-6 max-w-2xl text-base leading-relaxed text-daiku-cream/80 sm:text-lg">
                {{ $profile->heroSubheadline() }}
            </p>
            <div style="--d: 450ms" class="hero-in mt-9 flex flex-col items-center gap-3 sm:flex-row">
                <x-site.button :href="$profile->whatsappUrl()" external icon="whatsapp" variant="yellow" size="lg">Konsultasi Gratis</x-site.button>
                <x-site.button :href="route('site.portfolio.index')" variant="glass" size="lg">Lihat Portofolio</x-site.button>
            </div>

            <div style="--d: 650ms" class="hero-in mt-auto flex w-full flex-col items-center gap-4 pt-12 sm:flex-row sm:items-end sm:justify-between">
                <p class="text-sm text-daiku-cream/85">
                    @foreach (['Survei di lokasi', 'Desain 3D', 'RAB per item', 'QA tiap tahap'] as $point)
                        <span class="whitespace-nowrap">{{ $point }}</span>@unless ($loop->last) · @endunless
                    @endforeach
                </p>
                <div class="flex items-center gap-4 text-sm text-daiku-cream/85">
                    @if ($profile->instagramUrl())
                        <a href="{{ $profile->instagramUrl() }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 hover:text-daiku-cream">Instagram <x-site.icon name="arrow-up-right" class="size-3.5" /></a>
                    @endif
                    <a href="{{ $profile->whatsappUrl() }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 hover:text-daiku-cream">WhatsApp <x-site.icon name="arrow-up-right" class="size-3.5" /></a>
                </div>
            </div>
        </div>
    </div>
</section>
