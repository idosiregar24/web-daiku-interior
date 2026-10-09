@extends('site.layouts.main')

@section('title', $profile->name().' — Jasa Desain & Interior '.$profile->city().' | Kitchen Set, Cafe, Kantor')
@section('description', \Illuminate\Support\Str::limit($profile->heroHeadline().'. '.$profile->heroSubheadline(), 158))
@section('canonical', route('site.home'))

@push('head')
    @if ($hero = $profile->heroImage())
        {{-- Same candidates as the hero <img>, so a phone preloads the 960 px file it will use. --}}
        <link rel="preload" as="image" href="{{ $hero['src'] }}" @if ($hero['srcset']) imagesrcset="{{ $hero['srcset'] }}" imagesizes="(min-width: 1152px) 1088px, 100vw" @endif fetchpriority="high">
    @endif
    <script type="application/ld+json">{!! json_encode($profile->businessJsonLd(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@endpush

@section('content')
    @include('site.sections.hero')
    @include('site.sections.about')
    @include('site.sections.services')
    @include('site.sections.portfolio')
    @include('site.sections.process')
    @include('site.sections.testimonials')
    @include('site.sections.contact')
    @include('site.partials.cta-band')
@endsection
