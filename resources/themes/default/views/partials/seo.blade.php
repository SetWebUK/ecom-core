{{--
    <head> SEO tags. Views pass a $seo array (title, description, canonical, image, type, robots, noindex,
    modified_time, published_time) or sections: title, meta_description, canonical, og_image, og_type, robots.
    Site-wide "discourage search engines" (Settings › SEO) adds noindex everywhere.
--}}
@php
    $seo = $seo ?? [];
    $siteName = setting('seo.site_name', setting('store.name', config('app.name')));
    $section = fn (string $name) => trim(html_entity_decode(strip_tags($__env->yieldContent($name)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    $seoTitle = $section('title') ?: ($seo['title'] ?? $siteName);
    $seoDescription = $section('meta_description') ?: ($seo['description'] ?? setting('seo.default_description', ''));
    $seoDescription = trim(preg_replace('/\s+/', ' ', (string) $seoDescription));
    // full text like WordPress/Rank Math; a theme may cap it with config seo.description_limit (characters, 0 = no limit)
    if (($seoDescriptionLimit = (int) theme_config('seo.description_limit', 0)) > 0) {
        $seoDescription = \Illuminate\Support\Str::limit($seoDescription, $seoDescriptionLimit, '');
    }
    $seoCanonical = $section('canonical') ?: ($seo['canonical'] ?? url()->current().(request()->path() === '/' ? '/' : ''));
    $seoImage = $section('og_image') ?: ($seo['image'] ?? null);
    if (! $seoImage && filled($defaultImage = setting('seo.default_image'))) {
        $seoImage = media_url($defaultImage);
    }
    $seoType = $section('og_type') ?: ($seo['type'] ?? 'website');
    if (\Pine\Commerce\Http\Controllers\SitemapController::discouraged()) {
        $seoRobots = 'noindex, nofollow';
    } else {
        $seoRobots = $section('robots') ?: ($seo['robots'] ?? (! empty($seo['noindex']) ? 'noindex, follow' : 'index, follow, max-snippet:-1, max-image-preview:large'));
    }
@endphp
<title>{{ $seoTitle }}</title>
@if ($seoDescription !== '')
    <meta name="description" content="{{ $seoDescription }}">
@endif
<meta name="robots" content="{{ $seoRobots }}">
<link rel="canonical" href="{{ $seoCanonical }}">
<meta property="og:locale" content="{{ config('commerce.store.locale', 'en_GB') }}">
<meta property="og:type" content="{{ $seoType }}">
<meta property="og:title" content="{{ $seoTitle }}">
@if ($seoDescription !== '')
    <meta property="og:description" content="{{ $seoDescription }}">
@endif
<meta property="og:url" content="{{ $seoCanonical }}">
<meta property="og:site_name" content="{{ $siteName }}">
@if ($seoImage)
    <meta property="og:image" content="{{ $seoImage }}">
@endif
@if (! empty($seo['modified_time']))
    <meta property="article:modified_time" content="{{ $seo['modified_time'] }}">
@endif
@if (! empty($seo['published_time']))
    <meta property="article:published_time" content="{{ $seo['published_time'] }}">
@endif
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $seoTitle }}">
@if ($seoDescription !== '')
    <meta name="twitter:description" content="{{ $seoDescription }}">
@endif
<script type="application/ld+json">{!! json_encode([
    '@@context' => 'https://schema.org',
    '@graph' => [
        ['@type' => 'Organization', '@id' => url('/').'/#organization', 'name' => $siteName, 'url' => url('/').'/'],
        ['@type' => 'WebSite', '@id' => url('/').'/#website', 'url' => url('/').'/', 'name' => $siteName, 'publisher' => ['@id' => url('/').'/#organization'],
            'potentialAction' => ['@type' => 'SearchAction', 'target' => url('/').'/?s={search_term_string}', 'query-input' => 'required name=search_term_string']],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
