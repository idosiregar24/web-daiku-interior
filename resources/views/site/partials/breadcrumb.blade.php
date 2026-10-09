{{-- Breadcrumb (back arrow + trail) + BreadcrumbList JSON-LD. $items: list of [label, url|null]; the last one is the current page. --}}
@php
    $crumbs = [['Beranda', route('site.home')], ...$items];
    $back = $crumbs[count($crumbs) - 2];
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => collect($crumbs)->values()->map(fn ($crumb, $i) => array_filter([
            '@type' => 'ListItem',
            'position' => $i + 1,
            'name' => $crumb[0],
            'item' => $crumb[1] ?? url()->current(),
        ]))->all(),
    ];
@endphp
<nav aria-label="Breadcrumb" class="flex flex-wrap items-center gap-3 text-xs text-daiku-muted">
    <a href="{{ $back[1] }}" class="inline-flex items-center gap-1.5 rounded-full border border-daiku-border px-3 py-1 text-daiku-dark hover:bg-daiku-gray">
        <x-site.icon name="arrow-left" class="size-3.5" />
        Kembali
    </a>
    <ol class="flex flex-wrap items-center gap-1.5">
        @foreach ($crumbs as [$label, $url])
            <li class="flex items-center gap-1.5">
                @if (! $loop->last && $url)
                    <a href="{{ $url }}" class="hover:text-daiku-dark">{{ $label }}</a>
                    <span aria-hidden="true">/</span>
                @else
                    <span aria-current="page" class="text-daiku-dark">{{ $label }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
@push('head')
    <script type="application/ld+json">{!! json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@endpush
