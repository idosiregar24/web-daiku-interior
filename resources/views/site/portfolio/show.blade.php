@extends('site.layouts.main')

@php
    $place = $item->placeLabel();
    $context = 'proyek seperti "'.$item->title.'"';
    $cover = $photos[0] ?? null;
    $gallery = array_slice($photos, 1);
    $facts = array_values(array_filter([
        ['label' => 'Jenis', 'value' => $item->project_type->label()],
        $place ? ['label' => 'Lokasi', 'value' => $place] : null,
        $item->year ? ['label' => 'Tahun', 'value' => (string) $item->year] : null,
    ]));
    $creativeWork = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'CreativeWork',
        'name' => $item->title,
        'description' => $item->summary,
        'url' => route('site.portfolio.show', $item->slug),
        'dateCreated' => $item->year ? (string) $item->year : null,
        'locationCreated' => $place ? ['@type' => 'Place', 'name' => $place] : null,
        'creator' => ['@id' => route('site.home').'#bisnis'],
        'image' => collect($photos)->map(fn ($photo) => array_filter([
            '@type' => 'ImageObject',
            'contentUrl' => $photo['full'],
            'width' => $photo['width'],
            'height' => $photo['height'],
            'caption' => $photo['caption'] ?: $photo['alt'],
        ]))->all(),
    ]);
@endphp

@section('title', $item->title.($place ? ' — '.$place : '').' | '.$profile->name())
@section('description', \Illuminate\Support\Str::limit($item->summary ?: $item->project_type->label().' oleh '.$profile->name().($place ? ' di '.$place : '').'.', 158))
@section('canonical', route('site.portfolio.show', $item->slug))
@section('og_type', 'article')
@if ($cover)
    @section('og_image', $cover['full'])
@endif
@section('whatsapp_context', $context)

@push('head')
    <script type="application/ld+json">{!! json_encode($creativeWork, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@endpush

@section('content')
    <article>
        <section class="container-site pt-6" data-gallery>
            @include('site.partials.breadcrumb', ['items' => [['Portofolio', route('site.portfolio.index')], [$item->title, null]]])

            <div class="mt-6 grid gap-5 lg:grid-cols-12" data-reveal-group>
                @if ($cover)
                    <a href="{{ $cover['full'] }}" class="relative block overflow-hidden rounded-[2rem] bg-daiku-gray lg:col-span-7" data-lightbox="0" aria-label="Perbesar foto 1">
                        <img
                            src="{{ $cover['full'] }}"
                            srcset="{{ $cover['srcset'] }}"
                            sizes="(min-width: 1152px) 640px, 100vw"
                            width="{{ $cover['width'] }}"
                            height="{{ $cover['height'] }}"
                            alt="{{ $cover['alt'] }}"
                            fetchpriority="high"
                            decoding="async"
                            class="aspect-[4/3] size-full object-cover lg:absolute lg:inset-0 lg:aspect-auto"
                        >
                    </a>
                @endif

                <div class="flex flex-col rounded-[2rem] bg-daiku-gray p-6 sm:p-10 {{ $cover ? 'lg:col-span-5' : 'lg:col-span-12' }}">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="text-sm text-daiku-dark">Portofolio {{ $profile->name() }}</p>
                        @if ($item->year)
                            <x-site.pill tone="accent">{{ $item->year }}</x-site.pill>
                        @endif
                    </div>
                    <h1 class="mt-4 text-4xl leading-[1.1] font-light tracking-tight text-daiku-dark sm:text-5xl">{{ $item->title }}</h1>
                    @if ($item->summary)
                        <p class="mt-5 text-base leading-relaxed text-daiku-muted">{{ $item->summary }}</p>
                    @endif

                    <dl class="mt-8 grid grid-cols-2 gap-6 sm:grid-cols-3">
                        @foreach ($facts as $fact)
                            <div>
                                <dt class="text-xs text-daiku-muted">{{ $fact['label'] }}</dt>
                                <dd class="mt-1.5 text-lg text-daiku-dark">{{ $fact['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    <div class="mt-auto flex flex-col gap-4 pt-10 sm:flex-row sm:items-center">
                        <x-site.button :href="$profile->whatsappUrl($context)" external icon="whatsapp" size="lg">Mau yang seperti ini</x-site.button>
                        @if ($entry)
                            <a href="{{ $entry->url() }}" class="text-sm text-daiku-dark underline decoration-daiku-yellow decoration-2 underline-offset-4 hover:decoration-daiku-dark">Layanan {{ $entry->name }} {{ $profile->city() }}</a>
                        @endif
                    </div>
                </div>
            </div>

            @if ($gallery !== [])
                <div class="mt-5 grid grid-cols-2 gap-5 sm:grid-cols-3 lg:grid-cols-4" aria-label="Foto lainnya" data-reveal-group>
                    @foreach ($gallery as $photo)
                        <figure>
                            <a href="{{ $photo['full'] }}" class="block overflow-hidden rounded-[1.5rem] bg-daiku-gray" data-lightbox="{{ $loop->iteration }}" aria-label="Perbesar foto {{ $loop->iteration + 1 }}">
                                <img src="{{ $photo['src'] }}" srcset="{{ $photo['srcset'] }}" sizes="(min-width: 1024px) 270px, 50vw" width="{{ $photo['width'] }}" height="{{ $photo['height'] }}" alt="{{ $photo['alt'] }}" loading="lazy" decoding="async" class="aspect-square w-full object-cover transition duration-500 hover:scale-[1.04]">
                            </a>
                            @if ($photo['caption'])
                                <figcaption class="mt-2 text-xs text-daiku-muted">{{ $photo['caption'] }}</figcaption>
                            @endif
                        </figure>
                    @endforeach
                </div>
            @endif
        </section>

        @if ($item->description)
            @php
                $paragraphs = preg_split('/\R{2,}/', trim($item->description));
                [$lead, $rest] = \App\Support\CompanyProfile\ProfileContent::lead(array_shift($paragraphs));
            @endphp
            <section class="container-site pt-24 sm:pt-32">
                <div class="grid gap-6 lg:grid-cols-12">
                    <div class="lg:col-span-4">
                        <x-site.pill>Cerita proyek</x-site.pill>
                    </div>
                    <div class="lg:col-span-8" data-reveal>
                        <p class="text-2xl leading-snug font-light tracking-tight text-daiku-dark sm:text-[2rem]">{{ $lead }} @if ($rest)<span class="text-daiku-muted">{{ $rest }}</span>@endif</p>
                        @foreach ($paragraphs as $paragraph)
                            <p class="mt-6 text-lg leading-relaxed text-daiku-muted">{{ $paragraph }}</p>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif
    </article>

    @if ($related !== [])
        <section class="container-site pt-24 sm:pt-32" aria-labelledby="judul-terkait">
            <x-site.pill>Portofolio sejenis</x-site.pill>
            <h2 id="judul-terkait" class="sr-only">Portofolio sejenis</h2>
            <div class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3" data-reveal-group>
                @foreach ($related as $card)
                    @include('site.partials.portfolio-card', ['card' => $card])
                @endforeach
            </div>
        </section>
    @endif

    @include('site.partials.cta-band', ['heading' => 'Punya rencana serupa?', 'context' => $context])

    {{-- Lightbox (site.ts): one <dialog>, filled from the gallery links. --}}
    @if (count($photos) > 0)
        <dialog class="m-auto max-h-none max-w-none bg-transparent p-0 backdrop:bg-daiku-dark/90" data-lightbox-dialog aria-label="Foto proyek">
            <div class="on-dark flex h-svh w-screen items-center justify-center p-4">
                <img src="" alt="" class="max-h-[85svh] max-w-full rounded-2xl object-contain" data-lightbox-image>
                <button type="button" class="absolute top-4 right-4 inline-flex size-11 items-center justify-center rounded-full bg-background text-daiku-dark" aria-label="Tutup" data-lightbox-close>
                    <x-site.icon name="x" class="size-5" />
                </button>
                @if (count($photos) > 1)
                    <button type="button" class="absolute left-4 inline-flex size-11 items-center justify-center rounded-full bg-background text-daiku-dark" aria-label="Foto sebelumnya" data-lightbox-prev>
                        <x-site.icon name="arrow-left" class="size-5" />
                    </button>
                    <button type="button" class="absolute right-4 inline-flex size-11 items-center justify-center rounded-full bg-daiku-yellow text-daiku-dark" aria-label="Foto berikutnya" data-lightbox-next>
                        <x-site.icon name="arrow-right" class="size-5" />
                    </button>
                @endif
                <p class="absolute bottom-4 left-1/2 -translate-x-1/2 text-sm text-daiku-cream/80" data-lightbox-counter></p>
            </div>
        </dialog>
    @endif
@endsection
