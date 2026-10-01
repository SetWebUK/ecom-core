{{-- One panel of <x-admin.tabs>. <x-admin.tab-panel name="seo">…</x-admin.tab-panel> --}}
@props(['name'])
<div {{ $attributes }} role="tabpanel" data-tab-panel="{{ $name }}" x-show="tab === @js((string) $name)" x-cloak>
    {{ $slot }}
</div>
