{{-- Testimoni: one large quote, the rest as cards. Clients' own words, text only (no star ratings). Hidden when there are none. --}}
@php
    $testimonials = $profile->testimonials();
    $featured = array_shift($testimonials);
@endphp
@if ($featured)
    @php
        [$lead, $rest] = \App\Support\CompanyProfile\ProfileContent::lead($featured['quote']);
    @endphp
    <section class="container-site pt-24 sm:pt-32" aria-labelledby="judul-testimoni">
        <h2 id="judul-testimoni" class="flex justify-center"><x-site.pill>Kata klien</x-site.pill></h2>
        <figure class="mt-10 lg:ml-auto lg:w-2/3" data-reveal>
            <blockquote class="text-2xl leading-snug font-light tracking-tight text-daiku-dark sm:text-[2rem] lg:text-right">
                <p>“{{ $lead }} @if ($rest)<span class="text-daiku-muted">{{ $rest }}</span>@endif”</p>
            </blockquote>
            <figcaption class="mt-5 text-sm text-daiku-muted lg:text-right">{{ $featured['client_label'] }}</figcaption>
        </figure>
        @if ($testimonials !== [])
            <div class="mt-12 grid gap-5 md:grid-cols-2" data-reveal-group>
                @foreach ($testimonials as $testimonial)
                    <figure class="flex flex-col rounded-[1.5rem] bg-daiku-gray p-7">
                        <blockquote class="flex-1 text-base leading-relaxed text-daiku-dark"><p>“{{ $testimonial['quote'] }}”</p></blockquote>
                        <figcaption class="mt-6 text-sm text-daiku-muted">{{ $testimonial['client_label'] }}</figcaption>
                    </figure>
                @endforeach
            </div>
        @endif
    </section>
@endif
