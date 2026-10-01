{{-- Admin › Settings › Theme (ThemeSettingsController): active theme (administrators), theme settings, staff preview. --}}
@extends('commerce::admin.layouts.app')

@section('title', 'Theme · Settings')

@php
    use Pine\Commerce\Services\Admin\StoreSettings;
    $groups = collect($fields)->groupBy(fn ($f) => $f['group'] ?? 'Theme');
    $previewUrl = fn (string $slug) => url('/').'/?'.$previewParam.'='.urlencode($slug);
@endphp

@once('admin-content-js')
    @push('vendor')
        <script defer src="{{ commerce_admin_asset('js/content.js') }}"></script>
    @endpush
@endonce

@section('content')
    <x-admin.page-header :title="$config['label']" :subtitle="$config['description']" :back="route('admin.settings.index')" back-label="All settings" />

    <div class="settings-layout">
        @include('commerce::admin.settings._nav', ['active' => 'theme'])
        <div>
            <x-admin.form id="theme-form" :action="route('admin.settings.theme.update')" method="PUT" dirty save-label="Save theme settings">
                <input type="hidden" name="theme" value="{{ $editing->slug }}">
                <div class="stack">
                    <x-admin.card title="Storefront theme" subtitle="The design customers see. Every theme uses the same products, pages, orders and settings.">
                        @if ($canSwitch)
                            <x-admin.callout type="warning" title="Switching theme changes the live website for every customer">
                                Preview a theme first (only you can see a preview), then switch here. The server’s theme is
                                <strong>{{ $themes[$serverTheme]->name ?? $serverTheme }}</strong> (COMMERCE_THEME); choosing it again removes the override.
                            </x-admin.callout>
                            <x-admin.radio-cards name="active_theme" label="Active theme" :value="$active" columns="240" :options="collect($themes)->mapWithKeys(fn ($t) => [$t->slug => [
                                'label' => $t->name.($t->slug === $active ? ' (active)' : ''),
                                'help' => trim(\Illuminate\Support\Str::limit($t->description(), 110).' v'.$t->version.($t->parent() ? ' · based on '.$t->parent() : '')),
                                'icon' => 'swatch',
                            ]])->all()" />
                        @else
                            <p>The storefront uses <strong>{{ $themes[$active]->name ?? $active }}</strong>. Only administrators can switch themes.</p>
                        @endif

                        <div class="divider"></div>
                        <div class="stack stack--sm" id="preview">
                            <p class="text-sm fw-600">Preview a theme</p>
                            <p class="text-sm text-muted">Opens the shop in a new tab using that theme – visible only to you while you are signed in to the back office. Use “Exit preview” in the bar at the bottom of the shop to return.</p>
                            <div class="row" style="flex-wrap:wrap;gap:8px">
                                @foreach ($themes as $theme)
                                    <x-admin.button :href="$previewUrl($theme->slug)" target="_blank" rel="noopener" size="sm" icon="eye">Preview {{ $theme->name }}</x-admin.button>
                                    <x-admin.button :href="route('admin.settings.theme', ['theme' => $theme->slug])" size="sm" variant="ghost" icon="adjustments-horizontal">Settings</x-admin.button>
                                @endforeach
                            </div>
                        </div>
                    </x-admin.card>

                    @if ($fields === [])
                        <x-admin.card :title="$editing->name.' settings'">
                            <p class="text-muted">This theme has no settings of its own – its design is set in the theme files (themes/{{ $editing->slug }}).</p>
                        </x-admin.card>
                    @endif

                    @foreach ($groups as $groupName => $groupFields)
                        <x-admin.card :title="$groupName" :subtitle="$loop->first ? 'Settings of the “'.$editing->name.'” theme'.($editing->slug !== $active ? ' (not active – preview it to see the changes)' : '').'.' : null">
                            <div class="form-grid">
                                @foreach ($groupFields as $field)
                                    @if ($field['type'] === 'color')
                                        @php $name = StoreSettings::inputName($field['key']); $value = (string) (old($name, StoreSettings::value($field)) ?: ($field['default'] ?? '')); @endphp
                                        <div class="stack stack--xs" x-data="{ c: @js($value) }">
                                            <div class="field">
                                                <label class="field__label" for="f-{{ $name }}"><span>{{ $field['label'] }}</span></label>
                                                <div class="row row--nowrap" style="gap:8px;align-items:center">
                                                    <input type="color" x-model="c" aria-label="{{ $field['label'] }} picker" style="width:44px;height:38px;padding:2px;border:1px solid var(--border, #d1d5db);border-radius:8px;background:none">
                                                    <input type="text" id="f-{{ $name }}" name="{{ $name }}" x-model="c" class="input" maxlength="7" pattern="#[0-9a-fA-F]{3,6}" style="max-width:130px" placeholder="{{ $field['default'] ?? '#000000' }}">
                                                </div>
                                                @if (! empty($field['help']))<p class="field__help">{{ $field['help'] }}</p>@endif
                                                @error($name)<p class="field__error"><x-admin.icon name="exclamation-circle" variant="mini" /><span>{{ $message }}</span></p>@enderror
                                            </div>
                                        </div>
                                    @else
                                        @include('commerce::admin.settings._field', ['field' => $field + ['drives' => $field['drives'] ?? '']])
                                    @endif
                                @endforeach
                            </div>
                        </x-admin.card>
                    @endforeach
                </div>
            </x-admin.form>
            <div class="form-actions">
                <span class="flex-1"></span>
                <x-admin.button :href="route('admin.settings.index')">Cancel</x-admin.button>
                <x-admin.button type="submit" form="theme-form" variant="primary">Save theme settings</x-admin.button>
            </div>
        </div>
    </div>
@endsection
