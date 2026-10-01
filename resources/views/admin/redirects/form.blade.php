{{-- Create/edit a redirect. --}}
@extends('commerce::admin.layouts.app', ['width' => 'narrow'])

@php
    use Pine\Commerce\Http\Requests\Admin\Content\RedirectRequest;
    $editing = $redirect->exists;
    $saveLabel = $editing ? 'Save' : 'Add redirect';
@endphp

@section('title', $editing ? '/'.$redirect->from_path.'/ · Redirects' : 'Add redirect')

@section('content')
    <x-admin.page-header :title="$editing ? 'Redirect' : 'Add redirect'" :subtitle="$editing ? '/'.$redirect->from_path.'/' : null" :back="route('admin.redirects.index')" back-label="Back to redirects">
        @if ($editing)
            <x-slot:badges>
                <x-admin.badge :color="$redirect->is_active ? 'success' : 'gray'" dot>{{ $redirect->is_active ? 'On' : 'Switched off' }}</x-admin.badge>
            </x-slot:badges>
            <x-slot:actions>
                <x-admin.button :href="url($redirect->from_path)" icon="arrow-top-right-on-square" target="_blank" rel="noopener">Test it</x-admin.button>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    <x-admin.form id="redirect-form" :action="$editing ? route('admin.redirects.update', $redirect) : route('admin.redirects.store')" :method="$editing ? 'PUT' : 'POST'" dirty :save-label="$saveLabel">
        <x-admin.card>
            <div class="stack-fields" x-data="{ gone: {{ (int) old('status_code', $redirect->status_code) === RedirectRequest::GONE ? 'true' : 'false' }} }"
                 x-on:change="if ($event.target.name === 'status_code') gone = $event.target.value === '{{ RedirectRequest::GONE }}'">
                <x-admin.input name="from_path" label="Old address" :value="$redirect->from_path !== null && $redirect->from_path !== '' ? '/'.$redirect->from_path.'/' : ''" required maxlength="255"
                               class="mono" placeholder="/old-page/" :autofocus="! $editing" help="The address people are visiting, e.g. /old-page/. You can paste a full link." />
                <div x-show="! gone">
                    <x-admin.link-input name="to_url" label="Send visitors to" :value="$redirect->to_url" help="A page on this site (use Browse) or a full web address. Not needed for “Gone (410)”." />
                </div>
                @if (! in_array((int) $redirect->status_code, RedirectRequest::FORM_TYPES, true) && $redirect->exists)
                    <input type="hidden" name="status_code" value="{{ $redirect->status_code }}">
                @endif
                <x-admin.radio-cards name="status_code" label="Type" :value="(string) $redirect->status_code" :options="collect(RedirectRequest::TYPES)->only(RedirectRequest::FORM_TYPES)->all()" />
                @if (! in_array((int) $redirect->status_code, RedirectRequest::FORM_TYPES, true) && $redirect->exists)
                    <p class="text-sm text-muted">This redirect uses type {{ $redirect->status_code }} – {{ RedirectRequest::TYPES[$redirect->status_code]['label'] ?? '' }}.</p>
                @endif
                <x-admin.toggle name="is_active" label="Redirect is on" help="Switch off to stop it without deleting it." :checked="$redirect->is_active ?? true" />
            </div>
        </x-admin.card>

        @if ($editing)
            <x-admin.card title="Activity" class="mt-4">
                <dl class="kv">
                    <dt>Visits</dt><dd>{{ number_format($redirect->hits) }}</dd>
                    <dt>Last visit</dt><dd><x-admin.time :value="$redirect->last_hit_at" format="datetime" empty="Never" /></dd>
                    <dt>Created</dt><dd><x-admin.time :value="$redirect->created_at" format="datetime" /></dd>
                </dl>
            </x-admin.card>
        @endif
    </x-admin.form>

    <div class="form-actions">
        @if ($editing)
            <x-admin.confirm :action="route('admin.redirects.destroy', $redirect)" variant="ghost-danger" icon="trash"
                             title="Delete this redirect?" confirm-label="Delete redirect"
                             :message="'Visitors to /'.($redirect->from_path).'/ will get “page not found” instead.'">Delete redirect</x-admin.confirm>
        @endif
        <span class="flex-1"></span>
        <x-admin.button :href="route('admin.redirects.index')">Cancel</x-admin.button>
        <x-admin.button type="submit" form="redirect-form" variant="primary">{{ $saveLabel }}</x-admin.button>
    </div>
@endsection
