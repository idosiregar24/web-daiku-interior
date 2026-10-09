{{-- Footer: identity and address (NAP — must match the Google Business Profile), service pages, page links. --}}
<footer class="mt-24 border-t border-daiku-border">
    <div class="container-site grid gap-12 py-16 md:grid-cols-12">
        <div class="md:col-span-5">
            @include('site.partials.brand')
            @if ($profile->tagline())
                <p class="mt-4 max-w-sm text-sm text-daiku-muted">{{ $profile->tagline() }}</p>
            @endif
            <address class="mt-8 space-y-2.5 text-sm text-daiku-dark not-italic">
                @if ($profile->address())
                    <p class="flex gap-2.5"><x-site.icon name="map-pin" class="mt-0.5 size-4 shrink-0 text-daiku-muted" />{{ $profile->address() }}</p>
                @endif
                @if ($profile->phone())
                    <p class="flex gap-2.5"><x-site.icon name="whatsapp" class="mt-0.5 size-4 shrink-0 text-daiku-muted" /><a href="{{ $profile->whatsappUrl() }}" target="_blank" rel="noopener" class="hover:underline">{{ $profile->phone() }}</a></p>
                @endif
                @if ($profile->email())
                    <p class="flex gap-2.5"><x-site.icon name="mail" class="mt-0.5 size-4 shrink-0 text-daiku-muted" /><a href="mailto:{{ $profile->email() }}" class="hover:underline">{{ $profile->email() }}</a></p>
                @endif
                @if ($profile->instagramUrl())
                    <p class="flex gap-2.5"><x-site.icon name="instagram" class="mt-0.5 size-4 shrink-0 text-daiku-muted" /><a href="{{ $profile->instagramUrl() }}" target="_blank" rel="noopener" class="hover:underline">{{ '@'.$profile->instagramHandle() }}</a></p>
                @endif
            </address>
        </div>

        <nav class="md:col-span-4" aria-label="Layanan">
            <p class="text-xs text-daiku-muted">Layanan di {{ $profile->city() }}</p>
            <ul class="mt-4 grid gap-2.5 text-sm">
                @foreach ($profile->services() as $service)
                    <li><a href="{{ $service['url'] }}" class="text-daiku-dark hover:underline">{{ $service['name'] }}</a></li>
                @endforeach
            </ul>
        </nav>

        <nav class="md:col-span-3" aria-label="Halaman">
            <p class="text-xs text-daiku-muted">Halaman</p>
            <ul class="mt-4 grid gap-2.5 text-sm">
                <li><a href="{{ route('site.portfolio.index') }}" class="text-daiku-dark hover:underline">Portofolio</a></li>
                <li><a href="{{ route('site.home') }}#cara-kerja" class="text-daiku-dark hover:underline">Cara Kerja</a></li>
                <li><a href="{{ route('site.home') }}#tentang" class="text-daiku-dark hover:underline">Tentang Kami</a></li>
                <li><a href="{{ route('site.home') }}#kontak" class="text-daiku-dark hover:underline">Kontak</a></li>
            </ul>
        </nav>
    </div>
    <div class="container-site flex flex-col gap-2 border-t border-daiku-border pt-6 pb-24 text-xs text-daiku-muted sm:flex-row sm:items-center sm:justify-between sm:pb-8">
        <p>© {{ now('Asia/Jakarta')->year }} {{ $profile->legalName() ?? $profile->name() }}</p>
        <p>{{ implode(' · ', $profile->pillars()) }}</p>
    </div>
</footer>
