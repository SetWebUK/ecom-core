{{-- Inventory CSV import: dry-run preview of every change before it's applied. --}}
@extends('commerce::admin.layouts.app', ['width' => 'wide'])

@php
    $labels = ['stock_quantity' => 'Quantity', 'regular_price' => 'Price', 'sale_price' => 'Sale price'];
    $display = fn ($field, $value) => $value === null ? ($field === 'stock_quantity' ? 'not tracked' : 'none') : ($field === 'stock_quantity' ? number_format($value) : money($value));
@endphp

@section('title', 'Import preview · Inventory')

@section('content')
    @include('commerce::admin.products.partials.assets')

    <x-admin.page-header title="Check the import" :back="route('admin.products.inventory')" back-label="Back to inventory" :subtitle="$filename.' – nothing has been saved yet.'" />

    <div class="stats mb-4">
        <x-admin.stat label="Will be updated" :value="number_format($summary['change'])" icon="arrow-path" />
        <x-admin.stat label="Already up to date" :value="number_format($summary['same'])" icon="check" />
        <x-admin.stat label="SKU not found" :value="number_format($summary['missing'])" icon="question-mark-circle" />
        <x-admin.stat label="Rows with problems" :value="number_format($summary['error'])" icon="exclamation-triangle" />
    </div>

    <x-admin.card flush>
        <x-admin.status-tabs param="show" default="change" :current="$show" :tabs="[
            'change' => ['label' => 'Changes', 'count' => $summary['change']],
            'problems' => ['label' => 'Problems', 'count' => $summary['missing'] + $summary['error']],
            'all' => ['label' => 'All rows', 'count' => array_sum($summary)],
        ]" />
        @if ($rows->isEmpty())
            <x-admin.empty :icon="$show === 'problems' ? 'check-circle' : 'information-circle'" :title="$show === 'problems' ? 'No problems found' : 'Nothing to change'"
                           :description="$show === 'problems' ? 'Every row matched a product or variant.' : 'The file matches what’s already in the shop.'" size="sm" />
        @else
            <x-admin.table stack compact>
                <x-slot:head>
                    <x-admin.th>Line</x-admin.th>
                    <x-admin.th>SKU</x-admin.th>
                    <x-admin.th>Product</x-admin.th>
                    <x-admin.th>Change</x-admin.th>
                </x-slot:head>
                @foreach ($rows as $row)
                    <tr>
                        <td class="text-muted" data-label="Line">{{ $row['line'] }}</td>
                        <td class="mono stack-title">{{ $row['sku'] ?: '—' }}</td>
                        <td data-label="Product">{{ $row['name'] ?? '—' }}</td>
                        <td data-label="Change">
                            @if ($row['status'] === 'change')
                                @foreach ($row['changes'] as $field => [$old, $new])
                                    <div class="nowrap"><span class="text-muted">{{ $labels[$field] }}:</span> <span class="diff-old">{{ $display($field, $old) }}</span> → <span class="diff-new">{{ $display($field, $new) }}</span></div>
                                @endforeach
                            @elseif ($row['status'] === 'same')
                                <span class="text-subtle">No change</span>
                            @elseif ($row['status'] === 'missing')
                                <x-admin.badge color="warning">{{ $row['message'] }}</x-admin.badge>
                            @else
                                <x-admin.badge color="danger">{{ $row['message'] }}</x-admin.badge>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
            @if ($hidden)<p class="text-xs text-muted" style="padding:12px 16px">…and {{ number_format($hidden) }} more rows.</p>@endif
        @endif
    </x-admin.card>

    <div class="form-actions">
        <span class="flex-1"></span>
        <x-admin.button :href="route('admin.products.inventory')">Cancel</x-admin.button>
        @if ($summary['change'] > 0)
            <form method="POST" action="{{ route('admin.products.inventory.apply', $token) }}"
                  data-confirm-title="Apply {{ $summary['change'] }} {{ Str::plural('change', $summary['change']) }}?" data-confirm="Stock and prices update in the shop straight away. Rows with problems are skipped." data-confirm-button="Apply changes" data-confirm-danger="false">
                @csrf
                <x-admin.button type="submit" variant="primary" icon="check">Apply {{ number_format($summary['change']) }} {{ Str::plural('change', $summary['change']) }}</x-admin.button>
            </form>
        @else
            <x-admin.button variant="primary" disabled>Nothing to apply</x-admin.button>
        @endif
    </div>
@endsection
