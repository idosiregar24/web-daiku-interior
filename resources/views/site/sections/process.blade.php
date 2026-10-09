{{-- Cara Kerja: the real flow of a Daiku project, on a light panel. Shared by the home page and every service page. --}}
<section id="cara-kerja" class="container-site pt-24 sm:pt-32">
    <div class="rounded-[2rem] bg-daiku-gray px-5 py-12 sm:px-10 sm:py-16">
        <div class="grid gap-6 lg:grid-cols-12" data-reveal>
            <div class="lg:col-span-4">
                <x-site.pill tone="white">Cara Kerja</x-site.pill>
            </div>
            <div class="lg:col-span-8">
                <h2 class="text-2xl leading-snug font-light tracking-tight text-daiku-dark sm:text-[2rem]">
                    Tujuh tahap yang sama untuk setiap proyek.
                    <span class="text-daiku-muted">Anda tahu biayanya sebelum produksi dimulai, dan setiap tahap diperiksa sebelum lanjut.</span>
                </h2>
            </div>
        </div>
        <ol class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-reveal-group>
            @foreach ($profile->process() as $index => $step)
                <li class="flex flex-col rounded-[1.5rem] bg-background p-6">
                    <span class="flex size-9 items-center justify-center rounded-full bg-daiku-yellow text-sm font-medium text-daiku-dark tabular-nums">{{ $index + 1 }}</span>
                    <h3 class="mt-5 text-lg font-normal text-daiku-dark sm:mt-8">{{ $step['title'] }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-daiku-muted">{{ $step['text'] }}</p>
                </li>
            @endforeach
            <li class="flex flex-col justify-between rounded-[1.5rem] bg-daiku-dark p-6">
                <p class="text-xl leading-snug font-light text-daiku-cream">Mulai dari tahap pertama — gratis.</p>
                <x-site.button :href="$profile->whatsappUrl($whatsappContext ?? null)" external icon="whatsapp" variant="yellow" class="mt-8 self-start">Chat WhatsApp</x-site.button>
            </li>
        </ol>
    </div>
</section>
