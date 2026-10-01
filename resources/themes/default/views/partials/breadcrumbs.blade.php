{{-- Breadcrumb trail. $crumbs = list<array{label, url?}> (Home is added). --}}
@php $S = \Pine\Commerce\Theme\Storefront::class; @endphp
<nav class="breadcrumbs" aria-label="Breadcrumb">
    <ol>
        <li><a href="{{ url('/') }}/">Home</a></li>
        @foreach ($crumbs as $crumb)
            <li>{!! $S::icon('chevron-right', 14) !!}@if (! $loop->last && ! empty($crumb['url']))<a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a>@else<span aria-current="page">{{ $crumb['label'] }}</span>@endif</li>
        @endforeach
    </ol>
    <script type="application/ld+json">{!! json_encode(['@@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => collect([['label' => 'Home', 'url' => url('/').'/']])->merge($crumbs)->values()->map(fn ($c, $i) => array_filter(['@type' => 'ListItem', 'position' => $i + 1, 'name' => $c['label'], 'item' => $c['url'] ?? null]))->all()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
</nav>
