{{-- One service ($service from ProfileContent::services()) as a photo card linking to its page. --}}
<a href="{{ $service['url'] }}" class="group relative block overflow-hidden rounded-[1.75rem] bg-daiku-gray">
    @if ($service['image'])
        <img
            src="{{ $service['image']['src'] }}"
            @if ($service['image']['srcset']) srcset="{{ $service['image']['srcset'] }}" sizes="(min-width: 1024px) 368px, 80vw" @endif
            width="{{ $service['image']['width'] }}"
            height="{{ $service['image']['height'] }}"
            alt="{{ $service['image']['alt'] }}"
            loading="lazy"
            decoding="async"
            class="aspect-[4/5] w-full object-cover transition duration-700 group-hover:scale-[1.04] sm:aspect-square"
        >
    @else
        <div class="aspect-[4/5] w-full sm:aspect-square"></div>
    @endif
    <div class="absolute inset-0 bg-linear-to-t from-daiku-dark/80 via-daiku-dark/10 to-transparent"></div>
    <div class="absolute top-4 left-4">
        <x-site.pill tone="glass">{{ $service['name'] }}</x-site.pill>
    </div>
    <div class="absolute inset-x-5 bottom-5">
        <h3 class="text-xl leading-snug font-normal text-daiku-cream">{{ $service['entry']->keyword }} {{ $profile->city() }}</h3>
        <p class="mt-1.5 line-clamp-2 text-sm text-daiku-cream/75">{{ $service['blurb'] }}</p>
    </div>
    <span class="absolute top-4 right-4 inline-flex size-9 items-center justify-center rounded-full bg-background text-daiku-dark opacity-0 transition group-hover:opacity-100 group-focus-visible:opacity-100" aria-hidden="true">
        <x-site.icon name="arrow-up-right" class="size-4" />
    </span>
</a>
