{{--
    <x-admin.modal name="add-note" title="Add note">
        …content…
        <x-slot:footer>
            <x-admin.button x-on:click="hide()">Cancel</x-admin.button>
            <x-admin.button variant="primary" type="submit" form="note-form">Add note</x-admin.button>
        </x-slot:footer>
    </x-admin.modal>
    Open:  <x-admin.button x-data x-on:click="$dispatch('open-modal', 'add-note')">…</x-admin.button>   or Admin.openModal('add-note')
    Close: $dispatch('close-modal', 'add-note') · Admin.closeModal('add-note') · hide() inside the modal · Esc · backdrop click
    Props: name, title, size (sm|md|lg|xl), drawer (slides in from the right), open (start open, e.g. after validation errors), flush
--}}
@props(['name', 'title' => null, 'size' => 'md', 'drawer' => false, 'open' => false, 'flush' => false])
@php $titleId = 'modal-'.\Illuminate\Support\Str::slug($name).'-title'; @endphp
<div x-data="modal(@js($name), @js((bool) $open))" x-show="open" x-cloak
     x-on:open-modal.window="$event.detail === name && show()" x-on:close-modal.window="$event.detail === name && hide()"
     x-on:keydown.escape.window="open && hide()"
     {{ $attributes->class([$drawer ? 'drawer' : 'modal']) }} role="dialog" aria-modal="true" aria-labelledby="{{ $titleId }}">
    <div class="modal__backdrop" x-show="open" x-transition.opacity @click="hide()"></div>
    <div @class([$drawer ? 'drawer__panel' : 'modal__panel', ($drawer ? 'drawer__panel--' : 'modal__panel--').$size => $size !== 'md'])
         x-show="open" x-trap.noscroll="open"
         x-transition:enter="enter" x-transition:enter-start="{{ $drawer ? 'slide-from' : 'pop-from' }}" x-transition:enter-end="to-visible"
         x-transition:leave="leave" x-transition:leave-start="to-visible" x-transition:leave-end="{{ $drawer ? 'slide-from' : 'fade-from' }}">
        <div class="modal__header">
            <h2 class="modal__title" id="{{ $titleId }}">{{ $title }}</h2>
            <button type="button" class="btn btn--ghost btn--icon btn--sm" @click="hide()" aria-label="Close"><x-admin.icon name="x-mark" /></button>
        </div>
        <div @class(['modal__body', 'modal__body--flush' => $flush])>
            {{ $slot }}
        </div>
        @isset($footer)
            <div {{ $footer->attributes->class(['modal__footer']) }}>{{ $footer }}</div>
        @endisset
    </div>
</div>
