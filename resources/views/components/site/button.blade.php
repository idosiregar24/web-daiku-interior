{{-- Pill button/link of the company profile. `external` opens a new tab (WhatsApp, Instagram). --}}
@props(['href', 'variant' => 'dark', 'size' => 'md', 'external' => false, 'icon' => null, 'arrow' => false])
<a
    href="{{ $href }}"
    @if ($external) target="_blank" rel="noopener" @endif
    {{ $attributes->class([
        'group/button inline-flex shrink-0 items-center justify-center gap-2 rounded-full font-medium transition-colors',
        'px-4 py-2 text-sm' => $size === 'sm',
        'px-5 py-2.5 text-sm' => $size === 'md',
        'px-6 py-3.5 text-[15px]' => $size === 'lg',
        'bg-daiku-dark text-daiku-cream hover:bg-daiku-dark/85' => $variant === 'dark',
        'bg-daiku-yellow text-daiku-dark hover:bg-daiku-yellow-dark' => $variant === 'yellow',
        'text-daiku-dark ring-1 ring-daiku-border hover:bg-daiku-gray' => $variant === 'outline',
        'bg-daiku-dark/25 text-daiku-cream ring-1 ring-daiku-cream/35 backdrop-blur-md hover:bg-daiku-dark/40' => $variant === 'glass',
    ]) }}
>
    @if ($icon)
        <x-site.icon :name="$icon" class="size-4" />
    @endif
    {{ $slot }}
    @if ($arrow)
        <x-site.icon name="arrow-up-right" class="size-3.5 transition-transform duration-300 group-hover/button:translate-x-0.5 group-hover/button:-translate-y-0.5" />
    @endif
</a>
