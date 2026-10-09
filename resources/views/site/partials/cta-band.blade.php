{{-- Closing call to action: one dark rounded card. $heading / $context (WhatsApp message) are optional. --}}
<section class="container-site mt-24">
    <div data-reveal class="relative overflow-hidden rounded-[2rem] bg-daiku-dark px-6 py-12 sm:px-12 sm:py-16">
        <div class="relative flex flex-col gap-8 lg:flex-row lg:items-end lg:justify-between">
            <div class="max-w-2xl">
                <h2 class="text-3xl leading-tight font-light tracking-tight text-daiku-cream sm:text-5xl">{{ $heading ?? 'Punya ruang yang ingin ditata ulang?' }}</h2>
                <p class="mt-4 text-base text-daiku-cream/65">Konsultasi awal gratis. Kirim foto ruangan dan ukurannya, kami bantu hitung.</p>
            </div>
            <x-site.button :href="$profile->whatsappUrl($context ?? null)" external icon="whatsapp" variant="yellow" size="lg">Chat WhatsApp</x-site.button>
        </div>
    </div>
</section>
