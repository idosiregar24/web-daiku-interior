{{-- Portofolio: label + statement, the six newest published items (placeholders until there are real ones). --}}
@php($cards = $profile->portfolio(6))
@if ($cards !== [])
    <section id="portofolio" class="container-site pt-24 sm:pt-32">
        <div class="grid gap-6 lg:grid-cols-12" data-reveal>
            <div class="lg:col-span-4">
                <x-site.pill>Portofolio</x-site.pill>
            </div>
            <div class="flex flex-col gap-6 sm:flex-row sm:items-end sm:justify-between lg:col-span-8">
                <h2 class="max-w-xl text-2xl leading-snug font-light tracking-tight text-daiku-dark sm:text-[2rem]">
                    Pekerjaan terbaru.
                    <span class="text-daiku-muted">Lokasi kami tulis per kawasan, bukan alamat rumah klien.</span>
                </h2>
                <x-site.button :href="route('site.portfolio.index')" variant="outline" arrow>Semua portofolio</x-site.button>
            </div>
        </div>
        <div class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-3" data-reveal-group>
            @foreach ($cards as $card)
                @include('site.partials.portfolio-card', ['card' => $card])
            @endforeach
        </div>
    </section>
@endif
