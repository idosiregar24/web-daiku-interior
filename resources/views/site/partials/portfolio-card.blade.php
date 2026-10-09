{{-- One portfolio card ($card from ProfileContent): photo with a glass type tag, title over the photo. Placeholders have no page, so no link. --}}
@php($tag = $card['url'] ? 'a' : 'div')
<{{ $tag }} @if ($card['url']) href="{{ $card['url'] }}" @endif class="group relative block overflow-hidden rounded-[1.75rem] bg-daiku-gray">
    @if ($card['image'])
        <img
            src="{{ $card['image']['src'] }}"
            @if ($card['image']['srcset']) srcset="{{ $card['image']['srcset'] }}" sizes="(min-width: 1024px) 368px, (min-width: 640px) 50vw, 100vw" @endif
            width="{{ $card['image']['width'] }}"
            height="{{ $card['image']['height'] }}"
            alt="{{ $card['image']['alt'] }}"
            loading="lazy"
            decoding="async"
            class="aspect-[4/3] w-full object-cover transition duration-700 sm:aspect-square {{ $card['url'] ? 'group-hover:scale-[1.04]' : '' }}"
        >
    @else
        <div class="aspect-[4/3] w-full sm:aspect-square"></div>
    @endif
    <div class="absolute inset-0 bg-linear-to-t from-daiku-dark/75 via-daiku-dark/5 to-transparent"></div>
    <div class="absolute top-4 left-4">
        <x-site.pill tone="glass">{{ $card['type'] }}</x-site.pill>
    </div>
    <div class="absolute inset-x-5 bottom-5">
        <h3 class="text-xl leading-snug font-normal text-daiku-cream">{{ $card['title'] }}</h3>
        <p class="mt-1 text-xs text-daiku-cream/75">{{ collect([$card['place'], $card['year']])->filter()->implode(' · ') }}</p>
    </div>
</{{ $tag }}>
