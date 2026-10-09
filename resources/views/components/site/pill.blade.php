{{--
    Rounded label. Section names ("Tentang", "Layanan"): a filled pill without
    a border — tone="light" on white, tone="white" on a grey panel. Small tags
    on photos: tone="glass"; chips: tone="accent".
--}}
@props(['tone' => 'light'])
<span {{ $attributes->class([
    'inline-flex items-center gap-1.5 rounded-full',
    'px-5 py-2 text-base leading-6 font-medium text-daiku-dark' => in_array($tone, ['light', 'white'], true),
    'bg-daiku-gray' => $tone === 'light',
    'bg-background' => $tone === 'white',
    'bg-daiku-dark/25 px-3 py-1 text-xs leading-5 text-daiku-cream ring-1 ring-daiku-cream/30 backdrop-blur-md' => $tone === 'glass',
    'bg-daiku-yellow px-3 py-1 text-xs leading-5 text-daiku-dark' => $tone === 'accent',
]) }}>{{ $slot }}</span>
