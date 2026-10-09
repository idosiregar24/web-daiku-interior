{{-- Tentang: label + two-tone statement, three bento cards, then the row of numbers. --}}
@php
    $about = $profile->about();
    $statement = $about[0] ?? $profile->heroSubheadline();
    [$lead, $rest] = \App\Support\CompanyProfile\ProfileContent::lead($statement);
    $big = $profile->headlineStat();
    $facts = $profile->facts();
    $photoCard = $profile->bentoImage();
@endphp
<section id="tentang" class="container-site pt-24 sm:pt-32">
    <div class="grid gap-6 lg:grid-cols-12">
        <div class="lg:col-span-4">
            <x-site.pill>Tentang {{ $profile->name() }}</x-site.pill>
        </div>
        <div class="lg:col-span-8" data-reveal>
            <p class="text-2xl leading-snug font-light tracking-tight text-daiku-dark sm:text-[2rem]">
                {{ $lead }} @if ($rest)<span class="text-daiku-muted">{{ $rest }}</span>@endif
            </p>
            @foreach (array_slice($about, 1) as $paragraph)
                <p class="mt-6 max-w-2xl text-base leading-relaxed text-daiku-muted">{{ $paragraph }}</p>
            @endforeach
        </div>
    </div>

    <div class="mt-14 grid gap-5 md:grid-cols-3" data-reveal-group>
        {{-- What sets the work apart, from the real flow (RAB per item before production). --}}
        <div class="flex min-h-80 flex-col justify-between rounded-[1.75rem] bg-daiku-dark p-7">
            <x-site.icon name="ruler" class="size-7 text-daiku-yellow" />
            <p class="mt-10 text-2xl leading-snug font-light text-daiku-cream">
                RAB rinci per item
                <span class="text-daiku-cream/50">dengan material, ukuran dan harga —</span>
                Anda tahu biayanya sebelum produksi.
            </p>
            <a href="#cara-kerja" class="mt-8 inline-flex items-center gap-2 text-sm text-daiku-cream/80 hover:text-daiku-cream">
                Lihat cara kerja kami <x-site.icon name="arrow-right" class="size-4" />
            </a>
        </div>

        <div class="relative min-h-80 overflow-hidden rounded-[1.75rem] bg-daiku-gray">
            @if ($photoCard)
                <img src="{{ $photoCard['src'] }}" @if ($photoCard['srcset']) srcset="{{ $photoCard['srcset'] }}" sizes="(min-width: 768px) 33vw, 100vw" @endif width="{{ $photoCard['width'] }}" height="{{ $photoCard['height'] }}" alt="{{ $photoCard['alt'] }}" loading="lazy" decoding="async" class="absolute inset-0 size-full object-cover">
            @endif
            <div class="absolute inset-0 flex items-center justify-center p-6">
                <x-site.pill tone="glass" class="px-4 py-1.5 text-sm">Desain 3D sebelum produksi</x-site.pill>
            </div>
        </div>

        <div class="flex min-h-80 flex-col rounded-[1.75rem] bg-daiku-gray p-7">
            <p class="text-6xl font-light tracking-tight text-daiku-dark tabular-nums" data-count>{{ $big['value'] }}</p>
            <p class="mt-3 text-base text-daiku-dark">{{ $big['label'] }}</p>
            <p class="mt-2 text-sm leading-relaxed text-daiku-muted">Dari kitchen set dan kamar sampai cafe, toko dan kantor di {{ $profile->city() }} dan sekitarnya.</p>
            <ul class="mt-auto space-y-2.5 pt-8">
                @foreach ($profile->pillars() as $pillar)
                    <li class="flex items-center gap-3 text-sm text-daiku-dark">
                        <span class="flex size-5 items-center justify-center rounded-full bg-daiku-yellow"><x-site.icon name="check" class="size-3" /></span>
                        {{ $pillar }}
                    </li>
                @endforeach
            </ul>
        </div>
    </div>

    <div class="mt-24 text-center" data-reveal>
        <h2 class="text-xl font-light text-daiku-dark">{{ $profile->name() }} dalam angka</h2>
        <dl class="mt-10 grid grid-cols-2 gap-y-10 lg:grid-cols-4" data-reveal-group>
            @foreach ($facts as $fact)
                <div class="flex flex-col">
                    <dt class="order-last mt-2 text-sm text-daiku-muted">{{ $fact['label'] }}</dt>
                    <dd class="text-4xl font-light tracking-tight text-daiku-dark tabular-nums sm:text-5xl" @if ($fact['count']) data-count @endif>{{ $fact['value'] }}</dd>
                </div>
            @endforeach
        </dl>
    </div>
</section>
