{{--
    Brand in the header and footer. The logo uploaded in Pengaturan Situs
    replaces the default mark (yellow "D" tile + name) on its own — a logo
    usually carries the name already; `alt` keeps it for screen readers.
--}}
<a href="{{ route('site.home') }}" class="flex shrink-0 items-center gap-2.5" aria-label="{{ $profile->name() }} — beranda">
    @if ($profile->logoUrl())
        <img src="{{ $profile->logoUrl() }}" alt="{{ $profile->name() }}" class="h-10 w-auto max-w-48 object-contain">
    @else
        <span class="flex size-9 items-center justify-center rounded-xl bg-daiku-yellow text-base font-semibold text-daiku-dark" aria-hidden="true">{{ mb_substr($profile->name(), 0, 1) }}</span>
        <span class="text-lg font-medium tracking-tight text-daiku-dark">{{ $profile->name() }}</span>
    @endif
</a>
