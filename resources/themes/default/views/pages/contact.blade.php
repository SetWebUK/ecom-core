{{-- Contact page template: content + contact details + form. Adds $hasForm (content already contains the form). --}}
@extends('layouts.app')

@php
    $S = \Pine\Commerce\Theme\Storefront::class;
    $store = $S::store();
@endphp

@section('body_class', 'page-content page-contact')

@section('content')
    <div class="page-head">
        <div class="container">
            @include('partials.breadcrumbs', ['crumbs' => [['label' => $page->title]]])
            <h1 class="page-title">{{ $page->title }}</h1>
        </div>
    </div>
    <div class="container page-body">
        <div class="contact-layout">
            <div>
                @if (trim($contentHtml) !== '')
                    <div class="{{ $isElementor ? 'legacy' : 'prose' }}">{!! $contentHtml !!}</div>
                @endif
                @if (empty($hasForm) && commerce_feature('contact_form'))
                    @include('partials.contact-form')
                @endif
            </div>
            <aside class="card contact-card">
                <h2 class="card__title">Get in touch</h2>
                <ul class="contact-list">
                    @if ($store['phone'] !== '')<li>{!! $S::icon('phone', 20) !!}<a href="{{ $S::tel($store['phone']) }}">{{ $store['phone'] }}</a></li>@endif
                    @if ($store['email'] !== '')<li>{!! $S::icon('mail', 20) !!}<a href="mailto:{{ $store['email'] }}">{{ $store['email'] }}</a></li>@endif
                    @if ($store['address'] !== '')<li>{!! $S::icon('map-pin', 20) !!}<span>{!! nl2br(e($store['address'])) !!}</span></li>@endif
                </ul>
            </aside>
        </div>
    </div>
@endsection
