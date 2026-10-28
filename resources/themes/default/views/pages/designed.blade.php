{{-- Page laid out in the visual builder (PageController@show, Content\PageDesign): $page, $contentHtml, $designCss (every rule scoped to .pb-page), $seo, $bodyClass. Full width: the design brings its own title and sections. --}}
@extends('layouts.app')

@section('body_class', 'page-content page-designed')

@push('head')
    <link rel="stylesheet" href="{{ theme_asset('css/page-design.css') }}">
    @if (trim($designCss) !== '')
        <style>{!! $designCss !!}</style>
    @endif
@endpush

@section('content')
    <div class="pb-page">{!! $contentHtml !!}</div>
@endsection
