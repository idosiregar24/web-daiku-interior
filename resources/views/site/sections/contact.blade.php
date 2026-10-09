{{-- Kontak: photo card + info card (WhatsApp is the only way in, K1); NAP must match the Google Business Profile. --}}
@php($feature = $profile->featureImage())
<section id="kontak" class="container-site pt-24 sm:pt-32">
    <div class="grid gap-5 lg:grid-cols-12" data-reveal-group>
        @if ($feature)
            <div class="relative overflow-hidden rounded-[2rem] bg-daiku-yellow-light lg:col-span-5">
                <img src="{{ $feature['src'] }}" width="{{ $feature['width'] }}" height="{{ $feature['height'] }}" alt="{{ $feature['alt'] }}" loading="lazy" decoding="async" class="aspect-[4/3] size-full object-cover lg:absolute lg:inset-0 lg:aspect-auto">
            </div>
        @endif

        <div class="flex flex-col rounded-[2rem] bg-daiku-gray p-6 sm:p-10 {{ $feature ? 'lg:col-span-7' : 'lg:col-span-12' }}">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-daiku-dark">Desain & pengerjaan interior · {{ $profile->city() }}</p>
                <x-site.pill tone="accent"><x-site.icon name="zap" class="size-3.5" />Konsultasi gratis</x-site.pill>
            </div>
            <h2 class="mt-4 text-4xl font-light tracking-tight text-daiku-dark sm:text-5xl">Ceritakan ruang Anda</h2>

            <dl class="mt-10 grid gap-6 sm:grid-cols-3">
                @if ($profile->phone())
                    <div>
                        <dt class="text-xs text-daiku-muted">WhatsApp</dt>
                        <dd class="mt-1.5 text-lg text-daiku-dark">{{ $profile->phone() }}</dd>
                    </div>
                @endif
                @if ($profile->openingHours())
                    <div>
                        <dt class="text-xs text-daiku-muted">Jam kerja</dt>
                        <dd class="mt-1.5 text-lg text-daiku-dark">{{ $profile->openingHours() }}</dd>
                    </div>
                @endif
                @if ($profile->address())
                    <div>
                        <dt class="text-xs text-daiku-muted">Alamat</dt>
                        <dd class="mt-1.5 text-lg text-daiku-dark">{{ $profile->address() }}</dd>
                    </div>
                @endif
            </dl>

            @if ($profile->serviceArea())
                <p class="mt-8 max-w-xl text-sm leading-relaxed text-daiku-muted">{{ $profile->serviceArea() }}</p>
            @endif

            <div class="mt-auto flex flex-col gap-4 pt-10 sm:flex-row sm:items-center">
                <x-site.button :href="$profile->whatsappUrl()" external icon="whatsapp" size="lg">Chat WhatsApp</x-site.button>
                <p class="max-w-56 text-xs leading-relaxed text-daiku-muted">Kirim foto ruangan dan ukurannya. Tim kami membalas di jam kerja.</p>
            </div>
            @if ($profile->email() || $profile->instagramUrl())
                <div class="mt-6 flex flex-wrap gap-x-6 gap-y-2 border-t border-daiku-border pt-6 text-sm">
                    @if ($profile->email())
                        <a href="mailto:{{ $profile->email() }}" class="inline-flex items-center gap-2 text-daiku-dark hover:underline"><x-site.icon name="mail" class="size-4 text-daiku-muted" />{{ $profile->email() }}</a>
                    @endif
                    @if ($profile->instagramUrl())
                        <a href="{{ $profile->instagramUrl() }}" target="_blank" rel="noopener" class="inline-flex items-center gap-2 text-daiku-dark hover:underline"><x-site.icon name="instagram" class="size-4 text-daiku-muted" />{{ '@'.$profile->instagramHandle() }}</a>
                    @endif
                </div>
            @endif
        </div>
    </div>

    @if ($profile->mapsEmbedUrl())
        <iframe
            src="{{ $profile->mapsEmbedUrl() }}"
            title="Lokasi {{ $profile->name() }} di Google Maps"
            class="mt-5 aspect-[4/3] w-full rounded-[2rem] border-0 bg-daiku-gray sm:aspect-[21/9]"
            loading="lazy"
            referrerpolicy="no-referrer-when-downgrade"
            allowfullscreen
        ></iframe>
    @endif
</section>
