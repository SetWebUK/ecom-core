{{--
    Data table with optional row selection + bulk actions. Put it in <x-admin.card flush>.

    <x-admin.table :ids="$coupons->pluck('id')" selectable :bulk-action="route('admin.coupons.bulk')" stack>
        <x-slot:bulk>
            <x-admin.button type="submit" name="action" value="activate" size="sm">Activate</x-admin.button>
            <x-admin.button type="submit" name="action" value="delete" size="sm" variant="danger" data-confirm="Delete the selected discounts?">Delete</x-admin.button>
        </x-slot:bulk>
        <x-slot:head>
            <x-admin.th sort="code">Code</x-admin.th>
            <x-admin.th align="right" sort="usage_count">Used</x-admin.th>
        </x-slot:head>
        @foreach ($coupons as $coupon)
            <tr>
                <x-admin.row-check :id="$coupon->id" :label="'Select '.$coupon->code" />
                <td class="stack-title"><a class="row-link" href="…">{{ $coupon->code }}</a></td>
                <td class="num" data-label="Used">{{ $coupon->usage_count }}</td>
            </tr>
        @endforeach
    </x-admin.table>

    Bulk buttons post ids[] + action to bulk-action. stack: rows turn into cards on phones (give cells data-label="…",
    the main cell class="stack-title", hide minor cells with class="stack-hide"). wide: table scrolls sideways (no sticky header).
    Props: ids, selectable, bulk-action, stack, compact, wide. Slots: head, bulk, foot
--}}
@props(['ids' => [], 'selectable' => false, 'bulkAction' => null, 'stack' => false, 'compact' => false, 'wide' => false])
@php
    $ids = collect($ids)->map(fn ($id) => (string) $id)->values()->all();
@endphp
<div {{ $attributes->class(['table-block']) }} @if ($selectable) x-data="bulkTable(@js($ids))" :class="{ 'has-bulk': count > 0 }" @endif>
    @if ($selectable && isset($bulk))
        <form class="bulk-bar" method="POST" action="{{ $bulkAction }}" x-show="count > 0" x-cloak>
            @csrf
            <label class="bulk-bar__count">
                <input type="checkbox" class="checkbox" :checked="all" x-effect="$el.indeterminate = some" @change="toggleAll()" aria-label="Select all on this page">
                <span x-text="count + ' selected'"></span>
            </label>
            <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
            {{ $bulk }}
            <span class="bulk-bar__spacer"></span>
            <button type="button" class="btn btn--ghost btn--sm" @click="clear()"><span>Clear selection</span></button>
        </form>
    @endif
    <div @class(['table-wrap', 'table-wrap--sticky-off' => $wide])>
        <table @class(['table', 'table--stack' => $stack, 'table--compact' => $compact])>
            @isset($head)
                <thead>
                    <tr>
                        @if ($selectable)
                            <th class="table__check" scope="col">
                                <input type="checkbox" class="checkbox" :checked="all" x-effect="$el.indeterminate = some" @change="toggleAll()" aria-label="Select all on this page" @disabled(empty($ids))>
                            </th>
                        @endif
                        {{ $head }}
                    </tr>
                </thead>
            @endisset
            <tbody>
                {{ $slot }}
            </tbody>
            @isset($foot)
                <tfoot>{{ $foot }}</tfoot>
            @endisset
        </table>
    </div>
</div>
