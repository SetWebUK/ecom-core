{{-- Attributes list (Size, Colour, …) with value and product counts. --}}
@extends('commerce::admin.layouts.app', ['width' => 'wide'])

@section('title', 'Attributes')

@section('content')
    @include('commerce::admin.products.partials.assets')

    <x-admin.page-header title="Attributes" subtitle="Product details customers filter and compare by, such as Size or Colour.">
        <x-slot:actions>
            <x-admin.button variant="primary" icon="plus" :href="route('admin.attributes.create')">Add attribute</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    @if ($total === 0)
        <div class="card">
            <x-admin.empty icon="adjustments-horizontal" title="Add your first attribute" description="For example Memory with the values 8GB and 16GB – then pick values on each product.">
                <x-admin.button variant="primary" icon="plus" :href="route('admin.attributes.create')">Add attribute</x-admin.button>
            </x-admin.empty>
        </div>
    @else
        <x-admin.card flush>
            <x-admin.filters placeholder="Search attributes or values" />
            @if ($attributes->isEmpty())
                <x-admin.empty icon="magnifying-glass" title="No attributes match" description="Try a different search." size="sm">
                    <x-admin.button :href="route('admin.attributes.index')">Clear search</x-admin.button>
                </x-admin.empty>
            @else
                <x-admin.table stack>
                    <x-slot:head>
                        <x-admin.th sort="name">Attribute</x-admin.th>
                        <x-admin.th sort="values_count" first="desc">Values</x-admin.th>
                        <x-admin.th sort="products_count" align="right" first="desc">Products</x-admin.th>
                        <x-admin.th>Shop filter</x-admin.th>
                    </x-slot:head>
                    @foreach ($attributes as $attribute)
                        <tr>
                            <td class="stack-title">
                                <a class="row-link" href="{{ route('admin.attributes.edit', $attribute) }}">{{ $attribute->name }}</a>
                                <div class="cell-sub mono">{{ $attribute->slug }}</div>
                            </td>
                            <td data-label="Values">
                                <span class="count-pill">{{ number_format($attribute->values_count) }}</span>
                                <span class="text-muted text-sm hidden-mobile">{{ $attribute->values->take(8)->pluck('value')->implode(', ') }}{{ $attribute->values->count() > 8 ? ', …' : '' }}</span>
                            </td>
                            <td class="num" data-label="Products">{{ number_format((int) $attribute->products_count) }}</td>
                            <td data-label="Shop filter">
                                @if (in_array($attribute->slug, $locked, true) && $attribute->is_filterable)
                                    <x-admin.badge color="success" dot>In the filter sidebar</x-admin.badge>
                                @elseif ($attribute->is_filterable)
                                    <x-admin.badge color="info">Filterable</x-admin.badge>
                                @else
                                    <span class="text-subtle">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-admin.table>
                <x-admin.pagination :paginator="$attributes" />
            @endif
        </x-admin.card>
    @endif
@endsection
