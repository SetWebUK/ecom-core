{{-- One settings group rendered from Pine\Commerce\Services\Admin\StoreSettings (general, checkout, emails, seo, abandoned_carts, automation, client groups). --}}
@extends('commerce::admin.layouts.app')

@section('title', $config['label'].' · Settings')

@once('admin-content-js')
    @push('vendor')
        <script defer src="{{ commerce_admin_asset('js/content.js') }}"></script>
    @endpush
@endonce
@once('admin-sortable')
    @push('vendor')
        <script defer src="{{ commerce_admin_asset('vendor/sortablejs/Sortable-1.15.7.min.js', false) }}"></script>
    @endpush
@endonce

@section('content')
    <x-admin.page-header :title="$config['label']" :subtitle="$config['description']" :back="route('admin.settings.index')" back-label="All settings" />

    <div class="settings-layout">
        @include('commerce::admin.settings._nav', ['active' => $group])
        <div>
            <x-admin.form id="settings-form" :action="route('admin.settings.update', $group)" method="PUT" dirty save-label="Save settings">
                <div class="stack">
                    @foreach ($sections as $section)
                        <x-admin.card :title="$section['title']" :subtitle="$section['description'] ?? null">
                            @if (! empty($section['view']))
                                {{-- read-only panel of a section (status, explanations): 'view' => 'commerce::…' --}}
                                <div @class(['mb-4' => ! empty($section['fields'])])>@include($section['view'], ['group' => $group])</div>
                            @endif
                            @if (empty($section['fields']))
                            @elseif (! empty($section['toggles']))
                                <div class="stack stack--md">
                                    @foreach ($section['fields'] as $field)
                                        <div class="row row--between row--nowrap" style="align-items:flex-start">
                                            <div class="flex-1">
                                                <x-admin.toggle :name="Pine\Commerce\Services\Admin\StoreSettings::inputName($field['key'])" :label="$field['label']" :help="$field['drives']" :checked="Pine\Commerce\Services\Admin\StoreSettings::value($field)" />
                                            </div>
                                            <x-admin.badge :color="$field['to'] === 'To the shop' ? 'info' : 'gray'" size="sm">{{ $field['to'] }}</x-admin.badge>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <div class="form-grid">
                                    @foreach ($section['fields'] as $field)
                                        @include('commerce::admin.settings._field', ['field' => $field])
                                    @endforeach
                                </div>
                            @endif
                            @if (! empty($section['feed']))
                                <div class="divider"></div>
                                <div class="stack stack--sm">
                                    <p class="text-sm fw-600">Addresses to give Google</p>
                                    @foreach (['Product feed (Merchant Center)' => route('feed.google'), 'Sitemap (Search Console)' => url('sitemap.xml'), 'robots.txt' => url('robots.txt')] as $label => $url)
                                        <div class="field">
                                            <div class="field__label"><span>{{ $label }}</span></div>
                                            <div class="copy-field" x-data>
                                                <span>{{ $url }}</span>
                                                <button type="button" class="btn btn--sm" @click="navigator.clipboard.writeText(@js($url)).then(() => Admin.toast('Copied'))"><x-admin.icon name="clipboard-document" /><span>Copy</span></button>
                                                <a href="{{ $url }}" class="btn btn--sm btn--icon" target="_blank" rel="noopener" aria-label="Open {{ $label }}"><x-admin.icon name="arrow-top-right-on-square" /></a>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </x-admin.card>
                    @endforeach
                </div>
            </x-admin.form>
            <div class="form-actions">
                <span class="flex-1"></span>
                <x-admin.button :href="route('admin.settings.index')">Cancel</x-admin.button>
                <x-admin.button type="submit" form="settings-form" variant="primary">Save settings</x-admin.button>
            </div>
            {{-- screens with more than settings fields (e.g. tax classes and rates) --}}
            @includeIf('commerce::admin.settings.extra.'.$group)
        </div>
    </div>
@endsection
