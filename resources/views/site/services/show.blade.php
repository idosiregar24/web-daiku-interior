@extends('site.layouts.main')

@php
    $city = $profile->city();
    $faqs = array_values(array_filter($page->faqs ?? [], fn ($faq) => filled($faq['q'] ?? null) && filled($faq['a'] ?? null)));
    $highlights = array_values(array_filter($page->highlights ?? [], 'filled'));
    $image = $profile->serviceImage($entry);
    [$lead, $rest] = \App\Support\CompanyProfile\ProfileContent::lead($page->intro);
    $serviceLd = [
        '@context' => 'https://schema.org',
        '@type' => 'Service',
        'name' => $page->headline,
        'serviceType' => $entry->keyword,
        'description' => $page->meta_description ?: $page->intro,
        'url' => $entry->url(),
        'provider' => ['@id' => route('site.home').'#bisnis'],
        'areaServed' => ['@type' => 'City', 'name' => $city],
    ];
    $faqLd = $faqs === [] ? null : [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => array_map(fn ($faq) => [
            '@type' => 'Question',
            'name' => $faq['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq['a']],
        ], $faqs),
    ];
@endphp

@section('title', $page->headline.' | '.$profile->name())
@section('description', $page->meta_description ?: \Illuminate\Support\Str::limit($page->intro, 158))
@section('canonical', $entry->url())
{{-- K4 — a page still on placeholder text can be opened but stays out of Google. --}}
@unless ($page->is_published)
    @section('robots', 'noindex, follow')
@endunless
@if ($page->heroUrl())
    @section('og_image', $page->heroUrl())
@endif
@section('whatsapp_context', $entry->name)

@push('head')
    @if ($image)
        <link rel="preload" as="image" href="{{ $image['src'] }}" fetchpriority="high">
    @endif
    <script type="application/ld+json">{!! json_encode($serviceLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
    @if ($faqLd)
        <script type="application/ld+json">{!! json_encode($faqLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
    @endif
@endpush

@section('content')
    <section class="container-site pt-6">
        @include('site.partials.breadcrumb', ['items' => [['Layanan', route('site.home').'#layanan'], [$entry->name, null]]])

        <div class="mt-6 grid gap-5 lg:grid-cols-12" data-reveal-group>
            <div class="relative overflow-hidden rounded-[2rem] bg-daiku-yellow-light lg:col-span-5">
                @if ($image)
                    <img
                        src="{{ $image['src'] }}"
                        @if ($image['srcset']) srcset="{{ $image['srcset'] }}" sizes="(min-width: 1024px) 460px, 100vw" @endif
                        width="{{ $image['width'] }}"
                        height="{{ $image['height'] }}"
                        alt="{{ $image['alt'] }}"
                        fetchpriority="high"
                        decoding="async"
                        class="aspect-[4/3] size-full object-cover lg:absolute lg:inset-0 lg:aspect-auto"
                    >
                @endif
            </div>

            <div class="flex flex-col rounded-[2rem] bg-daiku-gray p-6 sm:p-10 lg:col-span-7">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm text-daiku-dark">Layanan di {{ $city }}</p>
                    <x-site.pill tone="accent"><x-site.icon name="zap" class="size-3.5" />Konsultasi gratis</x-site.pill>
                </div>
                <h1 class="mt-4 text-4xl leading-[1.1] font-light tracking-tight text-daiku-dark sm:text-5xl">{{ $page->headline }}</h1>
                <p class="mt-5 text-base leading-relaxed text-daiku-dark">{{ $lead }} @if ($rest)<span class="text-daiku-muted">{{ $rest }}</span>@endif</p>

                @if ($highlights !== [])
                    <ul class="mt-8 grid gap-3 sm:grid-cols-2">
                        @foreach ($highlights as $highlight)
                            <li class="flex items-start gap-3 text-sm text-daiku-dark">
                                <span class="mt-px flex size-5 shrink-0 items-center justify-center rounded-full bg-daiku-yellow"><x-site.icon name="check" class="size-3" /></span>
                                {{ $highlight }}
                            </li>
                        @endforeach
                    </ul>
                @endif

                <div class="mt-auto flex flex-col gap-4 pt-10 sm:flex-row sm:items-center">
                    <x-site.button :href="$profile->whatsappUrl($entry->name)" external icon="whatsapp" size="lg">Konsultasi {{ $entry->name }}</x-site.button>
                    <p class="max-w-56 text-xs leading-relaxed text-daiku-muted">Kirim foto ruangan dan ukurannya, kami bantu hitung biayanya.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="container-site pt-24 sm:pt-32">
        <div class="grid gap-6 lg:grid-cols-12">
            <div class="lg:col-span-4">
                <x-site.pill>Tentang layanan ini</x-site.pill>
            </div>
            <div class="lg:col-span-8" data-reveal>
                @foreach ($page->bodyBlocks() as $block)
                    @if ($block['type'] === 'heading')
                        <h2 class="mt-12 text-2xl font-light tracking-tight text-daiku-dark first:mt-0 sm:text-3xl">{{ $block['text'] }}</h2>
                    @else
                        <p class="mt-4 text-lg leading-relaxed text-daiku-muted">{{ $block['text'] }}</p>
                    @endif
                @endforeach
            </div>
        </div>
    </section>

    @if ($portfolio !== [])
        <section id="portofolio" class="container-site pt-24 sm:pt-32">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <x-site.pill>Portofolio</x-site.pill>
                    <h2 class="mt-5 text-3xl font-light tracking-tight text-daiku-dark sm:text-4xl">Hasil {{ mb_strtolower($entry->name) }} kami</h2>
                </div>
                @if ($hasRealPortfolio)
                    <x-site.button :href="route('site.portfolio.index', ['jenis' => strtolower($entry->type->value)])" variant="outline" arrow>Semua portofolio {{ mb_strtolower($entry->name) }}</x-site.button>
                @endif
            </div>
            <div class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3" data-reveal-group>
                @foreach ($portfolio as $card)
                    @include('site.partials.portfolio-card', ['card' => $card])
                @endforeach
            </div>
        </section>
    @endif

    @include('site.sections.process', ['whatsappContext' => $entry->name])

    @if ($faqs !== [])
        <section class="container-site pt-24 sm:pt-32" aria-labelledby="judul-faq">
            <div class="grid gap-6 lg:grid-cols-12">
                <div class="lg:col-span-4">
                    <x-site.pill>FAQ</x-site.pill>
                    <h2 id="judul-faq" class="mt-5 text-3xl font-light tracking-tight text-daiku-dark">Pertanyaan yang sering ditanyakan</h2>
                </div>
                <div class="space-y-3 lg:col-span-8" data-reveal-group>
                    @foreach ($faqs as $faq)
                        <details class="group rounded-[1.5rem] bg-daiku-gray px-6 py-5 open:bg-daiku-yellow-light">
                            <summary class="flex items-center justify-between gap-6 text-lg font-normal text-daiku-dark">
                                {{ $faq['q'] }}
                                <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-background"><x-site.icon name="chevron-down" class="size-4 transition-transform group-open:rotate-180" /></span>
                            </summary>
                            <p class="mt-3 pr-10 text-[15px] leading-relaxed text-daiku-dark/75">{{ $faq['a'] }}</p>
                        </details>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <section class="container-site pt-24" aria-labelledby="judul-layanan-lain">
        <h2 id="judul-layanan-lain" class="text-sm text-daiku-muted">Layanan lain di {{ $city }}</h2>
        <ul class="mt-4 flex flex-wrap gap-2">
            @foreach ($profile->services() as $service)
                @continue($service['entry']->slug === $entry->slug)
                <li><x-site.button :href="$service['url']" variant="outline" size="sm">{{ $service['name'] }}</x-site.button></li>
            @endforeach
        </ul>
    </section>

    @include('site.partials.cta-band', ['heading' => 'Rencanakan '.mb_strtolower($entry->name).' Anda', 'context' => $entry->name])
@endsection
