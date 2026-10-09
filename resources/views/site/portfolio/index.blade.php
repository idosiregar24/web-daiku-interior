@extends('site.layouts.main')

@php
    $heading = $activeEntry ? 'Portofolio '.$activeEntry->name : 'Portofolio';
    $hasReal = $items->total() > 0;
    // Until something is published, show the placeholder grid but keep the page out of Google.
    $cards = $hasReal ? $cards : $profile->portfolio(12, $activeEntry?->types);
    $chip = fn (bool $active) => 'inline-block rounded-full px-4 py-2 text-sm transition '.($active ? 'bg-daiku-dark text-daiku-cream' : 'text-daiku-dark ring-1 ring-daiku-border hover:bg-daiku-gray');
@endphp

@section('title', $heading.' '.$profile->city().' — '.$profile->name())
@section('description', 'Hasil pekerjaan '.$profile->name().' di '.$profile->city().' dan sekitarnya: '.collect($profile->services())->pluck('name')->take(5)->implode(', ').'.')
@section('canonical', $activeEntry ? route('site.portfolio.index', ['jenis' => strtolower($activeEntry->type->value)]) : route('site.portfolio.index'))
@if (! $hasReal || $items->currentPage() > 1)
    @section('robots', $hasReal ? 'noindex, follow' : 'noindex, nofollow')
@endif

@section('content')
    <section class="container-site pt-6">
        @include('site.partials.breadcrumb', ['items' => $activeEntry ? [['Portofolio', route('site.portfolio.index')], [$activeEntry->name, null]] : [['Portofolio', null]]])

        <div class="mt-10 grid gap-6 lg:grid-cols-12" data-reveal>
            <div class="lg:col-span-4">
                <x-site.pill>{{ $profile->city() }} dan sekitarnya</x-site.pill>
            </div>
            <div class="lg:col-span-8">
                <h1 class="text-4xl font-light tracking-tight text-daiku-dark sm:text-6xl">{{ $heading }}</h1>
                <p class="mt-5 max-w-2xl text-lg leading-relaxed text-daiku-muted">
                    Pekerjaan yang sudah kami serahterimakan. Lokasi ditulis per kawasan, bukan alamat rumah klien.
                </p>
            </div>
        </div>

        @if ($filters->count() > 1)
            <nav class="mt-10 -mx-4 overflow-x-auto px-4" aria-label="Saring jenis proyek">
                <ul class="flex w-max gap-2">
                    <li><a href="{{ route('site.portfolio.index') }}" class="{{ $chip(! $activeEntry) }}" @if (! $activeEntry) aria-current="page" @endif>Semua</a></li>
                    @foreach ($filters as $filter)
                        <li><a href="{{ route('site.portfolio.index', ['jenis' => strtolower($filter->type->value)]) }}" class="{{ $chip($activeEntry?->slug === $filter->slug) }}" @if ($activeEntry?->slug === $filter->slug) aria-current="page" @endif>{{ $filter->name }}</a></li>
                    @endforeach
                </ul>
            </nav>
        @endif
    </section>

    <section class="container-site pt-10">
        @if ($cards === [])
            <div class="rounded-[2rem] bg-daiku-gray px-6 py-16 text-center">
                <p class="text-2xl font-light text-daiku-dark">Belum ada portofolio {{ $activeEntry ? mb_strtolower($activeEntry->name).' ' : '' }}yang kami tampilkan.</p>
                <p class="mx-auto mt-3 max-w-md text-daiku-muted">Minta contoh pekerjaan sejenis lewat WhatsApp. Kami kirimkan foto proyek yang kliennya sudah setuju untuk dibagikan.</p>
                <x-site.button :href="$profile->whatsappUrl($activeEntry ? 'contoh pekerjaan '.$activeEntry->name : 'contoh pekerjaan')" external icon="whatsapp" variant="yellow" size="lg" class="mt-8">Minta contoh lewat WhatsApp</x-site.button>
            </div>
        @else
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3" data-reveal-group>
                @foreach ($cards as $card)
                    @include('site.partials.portfolio-card', ['card' => $card])
                @endforeach
            </div>

            @if ($items->hasPages())
                <nav class="mt-12 flex items-center justify-between gap-4 text-sm" aria-label="Halaman portofolio">
                    @if ($items->previousPageUrl())
                        <x-site.button :href="$items->previousPageUrl()" variant="outline" rel="prev"><x-site.icon name="arrow-left" class="size-4" />Sebelumnya</x-site.button>
                    @else
                        <span></span>
                    @endif
                    <span class="text-daiku-muted">Halaman {{ $items->currentPage() }} dari {{ $items->lastPage() }}</span>
                    @if ($items->nextPageUrl())
                        <x-site.button :href="$items->nextPageUrl()" variant="outline" rel="next">Berikutnya<x-site.icon name="arrow-right" class="size-4" /></x-site.button>
                    @else
                        <span></span>
                    @endif
                </nav>
            @endif
        @endif
    </section>

    @include('site.partials.cta-band', ['heading' => 'Ingin hasil seperti ini di ruang Anda?', 'context' => $activeEntry?->name])
@endsection
