{{--
    In-page tabs (client-side). The first tab with a validation error opens automatically; the tab is kept in the URL (#tab-…).
    <x-admin.tabs :tabs="['general' => 'General', 'inventory' => 'Inventory', 'seo' => 'SEO']">
        <x-admin.tab-panel name="general">…</x-admin.tab-panel>
        <x-admin.tab-panel name="inventory">…</x-admin.tab-panel>
    </x-admin.tabs>
    Props: tabs ([key => label]), active (initial tab)
--}}
@props(['tabs' => [], 'active' => null])
<div {{ $attributes }} x-data="tabs(@js($active ?? array_key_first($tabs)))">
    <div class="tabs" role="tablist">
        @foreach ($tabs as $key => $tabLabel)
            <button type="button" class="tab" role="tab" data-tab="{{ $key }}" :class="{ 'is-active': tab === @js((string) $key) }"
                    :aria-selected="(tab === @js((string) $key)).toString()" @click="select(@js((string) $key))">{{ $tabLabel }}</button>
        @endforeach
    </div>
    {{ $slot }}
</div>
