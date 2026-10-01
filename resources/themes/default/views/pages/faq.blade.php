{{-- FAQ page template: $faqs = list<{question, answer(html)}> + the page content. --}}
@extends('layouts.app')

@php $S = \Pine\Commerce\Theme\Storefront::class; @endphp

@section('body_class', 'page-content page-faq')

@section('content')
    <div class="page-head">
        <div class="container container--narrow">
            @include('partials.breadcrumbs', ['crumbs' => [['label' => $page->title]]])
            <h1 class="page-title">{{ $page->title }}</h1>
        </div>
    </div>
    <div class="container container--narrow page-body">
        @if (! empty($faqs))
            <div class="faq">
                @foreach ($faqs as $faq)
                    <details class="faq__item" @if ($loop->first) open @endif>
                        <summary>{{ $faq['question'] }}{!! $S::icon('plus', 18) !!}</summary>
                        <div class="faq__answer prose">{!! $faq['answer'] !!}</div>
                    </details>
                @endforeach
            </div>
            <script type="application/ld+json">{!! json_encode(['@@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn ($f) => ['@type' => 'Question', 'name' => $f['question'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => strip_tags((string) $f['answer'])]], $faqs)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
        @elseif (trim($contentHtml) !== '')
            <div class="{{ $isElementor ? 'legacy' : 'prose' }}">{!! $contentHtml !!}</div>
        @endif
    </div>
@endsection
