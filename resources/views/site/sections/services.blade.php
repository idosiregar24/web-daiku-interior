{{-- Layanan: label + statement, arrow buttons, then one photo card per service page in a scroll-snap row. --}}
<section id="layanan" class="mt-24 border-t border-daiku-border pt-24 sm:mt-32">
    <div class="container-site">
        <div class="grid gap-6 lg:grid-cols-12" data-reveal>
            <div class="lg:col-span-4">
                <x-site.pill>Layanan</x-site.pill>
            </div>
            <div class="lg:col-span-8">
                <h2 class="text-2xl leading-snug font-light tracking-tight text-daiku-dark sm:text-[2rem]">
                    Dari satu set kabinet dapur sampai satu lantai kantor.
                    <span class="text-daiku-muted">Semua dimulai dari ukuran ruang yang sebenarnya, digambar arsitek kami, lalu dihitung per item sebelum dikerjakan.</span>
                </h2>
            </div>
        </div>

        <div class="mt-10 flex items-center justify-between gap-4">
            <p class="text-sm text-daiku-muted">{{ count($profile->services()) }} layanan di {{ $profile->city() }}</p>
            <div class="flex gap-2">
                <button type="button" class="inline-flex size-11 items-center justify-center rounded-full text-daiku-dark ring-1 ring-daiku-border hover:bg-daiku-gray" aria-label="Layanan sebelumnya" aria-controls="daftar-layanan" data-carousel-prev>
                    <x-site.icon name="arrow-left" class="size-5" />
                </button>
                <button type="button" class="inline-flex size-11 items-center justify-center rounded-full bg-daiku-yellow text-daiku-dark hover:bg-daiku-yellow-dark" aria-label="Layanan berikutnya" aria-controls="daftar-layanan" data-carousel-next>
                    <x-site.icon name="arrow-right" class="size-5" />
                </button>
            </div>
        </div>
    </div>

    {{-- Full-bleed row that starts at the container edge; cards snap to it. --}}
    <ul id="daftar-layanan" class="mt-6 flex snap-x snap-mandatory gap-5 overflow-x-auto scroll-smooth px-4 pb-4 scroll-px-4 [scrollbar-width:none] sm:px-6 sm:scroll-px-6 lg:px-[max(2rem,calc((100%-72rem)/2+2rem))] lg:scroll-px-[max(2rem,calc((100%-72rem)/2+2rem))] [&::-webkit-scrollbar]:hidden" data-carousel data-reveal-group>
        @foreach ($profile->services() as $service)
            <li class="w-[80%] shrink-0 snap-start sm:w-[calc((100%-1.25rem)/2)] lg:w-[calc((100%-2.5rem)/3)]">
                @include('site.partials.service-card', ['service' => $service])
            </li>
        @endforeach
    </ul>
</section>
