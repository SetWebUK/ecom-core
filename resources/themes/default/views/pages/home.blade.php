{{--
    Home page (HomeController@index). $b = home blocks (Admin › Pages › Home, merged over the defaults), $bestSellers,
    $page, $seo. Every block is optional: a block without a title/items is skipped.
--}}
@extends('layouts.app')

@php
    $S = \Pine\Commerce\Theme\Storefront::class;
    $img = fn ($path) => $path ? (preg_match('#^(https?:)?//#', (string) $path) || str_starts_with((string) $path, '/') ? $path : media_url($path)) : null;
    $href = fn ($url) => \Pine\Commerce\View\Components\MenuComponent::href($url) ?? url('shop');
    $lines = fn ($text) => nl2br(e((string) $text));
    $hero = $b['hero'] ?? [];
    $features = array_values(array_filter($b['features'] ?? [], fn ($f) => ! empty($f['title'])));
    $usps = array_values(array_filter($b['usp_bar'] ?? [], fn ($u) => ! empty($u['text'])));
    $tiles = array_values(array_filter($b['categories']['tiles'] ?? [], fn ($t) => ! empty($t['title'])));
    if (! $tiles) {
        $tiles = \Pine\Commerce\Models\Category::query()->whereNull('parent_id')->where('is_visible', true)
            ->orderBy('sort_order')->orderBy('name')->limit(8)->get()
            ->map(fn ($c) => ['title' => $c->name, 'url' => $c->url, 'image' => $c->image])->all();
    }
    $faqs = array_values(array_filter($b['faq']['items'] ?? [], fn ($f) => ! empty($f['question'])));
    $brands = array_values(array_filter($b['brands']['items'] ?? [], fn ($i) => ! empty($i['image'])));
    $benefits = array_values(array_filter($b['benefits']['items'] ?? [], fn ($i) => ! empty($i['title'])));
    $why = $b['why'] ?? [];
    $cta = $b['cta'] ?? [];
@endphp

@section('body_class', 'page-home')

@section('content')
    @if ($usps)
        <div class="usp-bar">
            <ul class="container usp-bar__list">
                @foreach ($usps as $usp)
                    <li>{!! $S::icon('check-circle', 18) !!}<span>{{ $usp['text'] }}</span></li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="hero">
        <div class="container hero__inner">
            <div class="hero__copy">
                @if (! empty($hero['eyebrow']))<p class="eyebrow">{{ $hero['eyebrow'] }}</p>@endif
                <h1 class="hero__title">{!! $lines($hero['title'] ?? setting('store.name', config('app.name'))) !!}</h1>
                @if (! empty($hero['text']))<p class="hero__text">{!! $lines($hero['text']) !!}</p>@endif
                <div class="hero__actions">
                    <a class="btn btn--primary btn--lg" href="{{ $href($hero['button_url'] ?? '/shop/') }}">{{ $hero['button_text'] ?? 'Shop now' }}{!! $S::icon('arrow-right', 18) !!}</a>
                    @if ($tiles)<a class="btn btn--ghost btn--lg" href="#categories">Browse categories</a>@endif
                </div>
                @if (! empty($hero['trust_line']))<p class="hero__trust">{!! $S::icon('shield', 18) !!}<span>{{ $hero['trust_line'] }}</span></p>@endif
            </div>
            @if ($heroImage = $img($hero['image'] ?? null))
                <div class="hero__media"><x-media-image :path="$hero['image']" size="large" sizes="(min-width: 900px) 50vw, 100vw" :priority="true" /></div>
            @endif
        </div>
    </section>

    @if ($features)
        <section class="features" aria-label="Why shop with us">
            <ul class="container features__list">
                @foreach (array_slice($features, 0, 4) as $feature)
                    <li class="feature">
                        <span class="feature__icon">@if ($icon = $img($feature['icon'] ?? null))<img src="{{ $icon }}" alt="" width="28" height="28" loading="lazy">@else{!! $S::icon(['truck', 'shield', 'refresh', 'chat'][$loop->index % 4], 24) !!}@endif</span>
                        <span><strong class="feature__title">{{ $feature['title'] }}</strong>@if (! empty($feature['text']))<span class="feature__text">{{ $feature['text'] }}</span>@endif</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($tiles)
        <section class="section container" id="categories" aria-labelledby="cat-title">
            <div class="section__head">
                <h2 class="section-title" id="cat-title">{{ $b['categories']['title'] ?? 'Shop by category' }}</h2>
                <a class="link-arrow" href="{{ url('shop') }}/">View all{!! $S::icon('arrow-right', 16) !!}</a>
            </div>
            <ul class="cat-grid">
                @foreach ($tiles as $tile)
                    <li>
                        <a class="cat-tile" href="{{ $href($tile['url'] ?? '#') }}">
                            <span class="cat-tile__media">@if (! empty($tile['image']))<x-media-image :path="$tile['image']" size="card" sizes="(min-width: 1100px) 260px, (min-width: 700px) 30vw, 46vw" />@else{!! $S::icon('grid', 36) !!}@endif</span>
                            <span class="cat-tile__title">{{ $tile['title'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($bestSellers->isNotEmpty())
        <section class="section container" aria-labelledby="featured-title">
            <div class="section__head">
                <div>
                    <h2 class="section-title" id="featured-title">{{ $b['best_sellers']['title'] ?? theme_setting('featured_title', 'Featured products') }}</h2>
                    @if (! empty($b['best_sellers']['text']))<p class="section__sub">{{ $b['best_sellers']['text'] }}</p>@endif
                </div>
                <a class="link-arrow" href="{{ url('shop') }}/">Shop all{!! $S::icon('arrow-right', 16) !!}</a>
            </div>
            <div class="product-grid product-grid--4">
                @foreach ($bestSellers as $product)
                    @include('partials.product-card', ['product' => $product])
                @endforeach
            </div>
        </section>
    @endif

    @if (! empty($why['title']))
        <section class="section container">
            <div class="split">
                @if ($whyImage = $img($why['image'] ?? null))
                    <div class="split__media"><x-media-image :path="$why['image']" size="medium" sizes="(min-width: 900px) 50vw, 100vw" /></div>
                @endif
                <div class="split__copy">
                    <h2 class="section-title">{!! $lines($why['title']) !!}</h2>
                    @if (! empty($why['text']))<div class="prose">{!! nl2br(e(strip_tags((string) $why['text']))) !!}</div>@endif
                    @if (! empty($why['button_text']))<a class="btn btn--primary" href="{{ $href($why['button_url'] ?? '/shop/') }}">{{ $why['button_text'] }}</a>@endif
                </div>
            </div>
        </section>
    @endif

    @if ($benefits)
        <section class="section section--surface" aria-labelledby="benefits-title">
            <div class="container">
                <div class="section__head section__head--center">
                    <h2 class="section-title" id="benefits-title">{{ $b['benefits']['title'] ?? 'Why choose us' }}</h2>
                    @if (! empty($b['benefits']['text']))<p class="section__sub">{{ $b['benefits']['text'] }}</p>@endif
                </div>
                <ul class="benefits">
                    @foreach ($benefits as $benefit)
                        <li class="benefit card">
                            <span class="benefit__icon">@if ($icon = $img($benefit['icon'] ?? null))<img src="{{ $icon }}" alt="" width="32" height="32" loading="lazy">@else{!! $S::icon('check-circle', 28) !!}@endif</span>
                            <h3 class="benefit__title">{{ $benefit['title'] }}</h3>
                            @if (! empty($benefit['text']))<p class="muted">{{ $benefit['text'] }}</p>@endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    @if ($brands)
        <section class="section container" aria-labelledby="brands-title">
            <div class="section__head section__head--center"><h2 class="section-title" id="brands-title">{{ $b['brands']['title'] ?? 'Top brands' }}</h2></div>
            <ul class="brand-strip">
                @foreach ($brands as $brand)
                    <li><a href="{{ $href($brand['url'] ?? '#') }}"><img src="{{ $img($brand['image']) }}" alt="{{ $brand['alt'] ?? '' }}" loading="lazy" decoding="async"></a></li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($faqs)
        <section class="section container container--narrow" aria-labelledby="faq-title">
            <div class="section__head section__head--center"><h2 class="section-title" id="faq-title">{{ $b['faq']['title'] ?? 'Frequently asked questions' }}</h2></div>
            <div class="faq">
                @foreach ($faqs as $faq)
                    <details class="faq__item">
                        <summary>{{ $faq['question'] }}{!! $S::icon('plus', 18) !!}</summary>
                        <div class="faq__answer prose">{!! $faq['answer'] ?? '' !!}</div>
                    </details>
                @endforeach
            </div>
            <script type="application/ld+json">{!! json_encode(['@@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn ($f) => ['@type' => 'Question', 'name' => $f['question'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => strip_tags((string) ($f['answer'] ?? ''))]], $faqs)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
        </section>
    @endif

    @if (! empty($cta['title']))
        <section class="container">
            <div class="cta">
                <div>
                    <h2 class="cta__title">{!! $lines($cta['title']) !!}</h2>
                    @if (! empty($cta['text']))<p>{{ $cta['text'] }}</p>@endif
                </div>
                @if (! empty($cta['button_text']))<a class="btn btn--light btn--lg" href="{{ $href($cta['button_url'] ?? '/shop/') }}">{{ $cta['button_text'] }}</a>@endif
            </div>
        </section>
    @endif
@endsection
