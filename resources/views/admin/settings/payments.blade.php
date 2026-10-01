{{-- Payment methods (administrators only). Secret keys are write-only: stored encrypted and never shown again. --}}
@extends('commerce::admin.layouts.app')

@section('title', 'Payments · Settings')

@section('content')
    <x-admin.page-header title="Payments" subtitle="How customers can pay at checkout. Only administrators can see this page." :back="route('admin.settings.index')" back-label="All settings" />

    <div class="settings-layout">
        @include('commerce::admin.settings._nav', ['active' => 'payments'])
        <div>
            <x-admin.form id="payments-form" :action="route('admin.payments.update')" method="PUT" dirty save-label="Save payment settings" autocomplete="off">
                <div class="stack">
                    @foreach ($methods as $code => $method)
                        @php $state = $status[$code]; @endphp
                        <x-admin.card>
                            <x-slot:header>
                                <div class="row row--nowrap flex-1" style="align-items:flex-start">
                                    <span class="settings-tile__icon"><x-admin.icon :name="$method['icon']" /></span>
                                    <div class="flex-1">
                                        <h2 class="card__title">{{ $method['label'] }}</h2>
                                        <p class="card__subtitle">{{ $method['description'] }}</p>
                                    </div>
                                </div>
                            </x-slot:header>
                            <x-slot:actions>
                                @if ($state['enabled'] && $state['configured'])
                                    <x-admin.badge color="success" dot>On at checkout</x-admin.badge>
                                @elseif ($state['enabled'])
                                    <x-admin.badge color="warning" dot>Missing keys</x-admin.badge>
                                @else
                                    <x-admin.badge color="gray">Off</x-admin.badge>
                                @endif
                            </x-slot:actions>

                            <div class="form-grid">
                                @foreach ($method['fields'] as $key => $field)
                                    @php
                                        $name = "payments[{$code}][{$key}]";
                                        $value = $values[$code][$key];
                                        $wide = in_array($field['type'], ['textarea', 'bool'], true) || ! empty($field['wide']);
                                    @endphp
                                    <div @if ($wide) style="grid-column: 1 / -1" @endif>
                                        @if ($field['type'] === 'bool')
                                            <x-admin.toggle :name="$name" :label="$field['label']" :help="$field['help'] ?? null" :checked="$value" />
                                        @elseif ($field['type'] === 'secret')
                                            @php $fieldId = Pine\Commerce\View\Components\Admin\Ui::id($name); $errorKey = "payments.{$code}.{$key}"; @endphp
                                            <x-admin.field :label="$field['label']" :for="$fieldId" :error="$errorKey" :help="$field['help'] ?? null">
                                                <div x-data="{ replace: {{ $value && ! $errors->has($errorKey) ? 'false' : 'true' }}, clear: false }">
                                                    <div class="secret-state" x-show="!replace">
                                                        <x-admin.badge color="success" icon="lock-closed">•••••••• Saved</x-admin.badge>
                                                        <button type="button" class="btn btn--sm" @click="replace = true; $nextTick(() => $refs.input.focus())"><span>Replace</span></button>
                                                        <label class="check" style="align-items:center"><input type="checkbox" class="checkbox" name="payments[{{ $code }}][{{ $key }}_clear]" value="1" x-model="clear"><span class="check__text"><span class="check__label text-sm">Remove it</span></span></label>
                                                    </div>
                                                    <div x-show="replace" @if ($value) x-cloak @endif>
                                                        <input type="password" name="{{ $name }}" id="{{ $fieldId }}" x-ref="input" class="input input--mono @error($errorKey) is-invalid @enderror"
                                                               placeholder="{{ $field['placeholder'] ?? '' }}" autocomplete="new-password" spellcheck="false" maxlength="255"
                                                               @error($errorKey) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @enderror>
                                                        @if ($value)
                                                            <button type="button" class="btn btn--plain text-sm mt-1" @click="replace = false; $refs.input.value = ''; Admin.markDirty($refs.input)">Keep the saved one</button>
                                                        @endif
                                                    </div>
                                                </div>
                                            </x-admin.field>
                                        @elseif ($field['type'] === 'textarea')
                                            <x-admin.textarea :name="$name" :label="$field['label']" :value="$value" rows="3" :help="$field['help'] ?? null" :placeholder="$field['default'] ?? null" :optional="true" />
                                        @else
                                            <x-admin.input :name="$name" :label="$field['label']" :value="$value" :help="$field['help'] ?? (isset($field['default']) ? 'Blank = “'.$field['default'].'”' : null)"
                                                           :placeholder="$field['placeholder'] ?? ($field['default'] ?? null)" :class="! empty($field['mono']) ? 'input--mono' : null" :optional="! empty($field['optional'])"
                                                           autocomplete="off" spellcheck="false" maxlength="255" />
                                        @endif
                                    </div>
                                @endforeach
                            </div>

                            @if (isset($webhooks[$code]) && ! empty($method['webhook']))
                                <div class="divider"></div>
                                <div class="field">
                                    <div class="field__label"><span>Webhook address</span><span class="field__label-extra">paste this into {{ $method['webhook']['where'] ?? $method['label'] }}</span></div>
                                    <div class="copy-field" x-data>
                                        <span>{{ $webhooks[$code] }}</span>
                                        <button type="button" class="btn btn--sm" @click="navigator.clipboard.writeText(@js($webhooks[$code])).then(() => Admin.toast('Copied'))"><x-admin.icon name="clipboard-document" /><span>Copy</span></button>
                                    </div>
                                    <p class="field__help">Lets {{ $method['webhook']['provider'] ?? $method['label'] }} tell the shop about payments that finish after the customer has left the page.</p>
                                </div>
                            @endif
                        </x-admin.card>
                    @endforeach
                </div>
            </x-admin.form>
            <div class="form-actions">
                <span class="flex-1"></span>
                <x-admin.button :href="route('admin.settings.index')">Cancel</x-admin.button>
                <x-admin.button type="submit" form="payments-form" variant="primary">Save payment settings</x-admin.button>
            </div>
        </div>
    </div>
@endsection
