{{-- Back-in-stock requests grouped by product: who is waiting, "Notify now", remove requests. --}}
@extends('commerce::admin.layouts.app', ['width' => 'wide'])

@php use Pine\Commerce\Services\Admin\CatalogueTools; @endphp

@section('title', 'Stock alerts')

@section('content')
    @include('commerce::admin.products.partials.assets')

    <x-admin.page-header title="Stock alerts" subtitle="Customers who asked to be emailed when an out-of-stock product is available again." />

    @if ($summary['ready'] > 0)
        <x-admin.callout type="success" class="mb-4" icon="bell-alert"
                         :title="($summary['readyProducts']).' '.(Str::plural('product', $summary['readyProducts'])).' '.($summary['readyProducts'] === 1 ? 'is' : 'are').' back in stock with '.($summary['ready']).' '.(Str::plural('customer', $summary['ready'])).' waiting'">
            <div class="row mt-2">
                <form method="POST" action="{{ route('admin.stock-alerts.notify') }}" data-confirm-title="Email everyone who’s waiting?"
                      data-confirm="Each customer gets one “It’s back in stock” email for the product they asked about." data-confirm-button="Send emails" data-confirm-danger="false">
                    @csrf
                    <input type="hidden" name="all" value="1">
                    <x-admin.button type="submit" variant="primary" size="sm" icon="paper-airplane">Notify them all now</x-admin.button>
                </form>
            </div>
        </x-admin.callout>
    @endif

    @if ($tabs['all']['count'] === 0)
        <div class="card">
            <x-admin.empty icon="bell" title="No stock alerts yet" description="When a customer asks to be told about an out-of-stock product, they appear here. They’re emailed automatically when you put the product back in stock." />
        </div>
    @else
        <x-admin.card flush>
            <x-admin.status-tabs :tabs="$tabs" :current="$status" default="waiting" />
            <x-admin.filters placeholder="Search by product, SKU or email" keep="status" />

            @if ($products->isEmpty())
                <x-admin.empty :icon="$q !== '' ? 'magnifying-glass' : 'check-circle'" :title="$q !== '' ? 'Nothing matches' : 'Nobody is waiting'"
                               :description="$q !== '' ? 'Try a different search.' : 'Every customer who asked has been emailed.'" size="sm">
                    <x-admin.button :href="route('admin.stock-alerts.index', ['status' => 'all'])">See all requests</x-admin.button>
                </x-admin.empty>
            @else
                <x-admin.table :ids="$products->pluck('id')" selectable :bulk-action="route('admin.stock-alerts.bulk')" stack>
                    <x-slot:bulk>
                        <x-admin.button type="submit" name="action" value="notify" size="sm" icon="paper-airplane"
                                        data-confirm-title="Email the waiting customers?" data-confirm="Only products that can be bought again are emailed about; the rest keep waiting." data-confirm-button="Send emails" data-confirm-danger="false">Notify now</x-admin.button>
                        <x-admin.button type="submit" name="action" value="delete" size="sm" variant="ghost-danger" icon="trash"
                                        data-confirm-title="Delete these requests?" data-confirm="The customers won’t be emailed about these products." data-confirm-button="Delete requests">Delete requests</x-admin.button>
                    </x-slot:bulk>
                    <x-slot:head>
                        <x-admin.th sort="name">Product</x-admin.th>
                        <x-admin.th>Stock</x-admin.th>
                        <x-admin.th sort="waiting" align="right" first="desc">Waiting</x-admin.th>
                        <x-admin.th sort="last_requested" first="desc">Last request</x-admin.th>
                        <x-admin.th><span class="sr-only">Actions</span></x-admin.th>
                    </x-slot:head>
                    @foreach ($products as $product)
                        @php
                            $stock = CatalogueTools::stock($product);
                            $rows = $subscriptions->get($product->id, collect());
                            $available = $product->status === 'published' && ! $product->trashed() && $product->isInStock();
                        @endphp
                        <tr x-data="{ open: false }">
                            <x-admin.row-check :id="$product->id" :label="'Select '.$product->name" />
                            <td class="stack-title">
                                <div class="product-cell">
                                    <x-admin.thumb :src="$product->images->first()?->path" />
                                    <div class="product-cell__text">
                                        @if ($product->trashed())
                                            <span class="product-cell__name">{{ $product->name }}</span>
                                        @else
                                            <a class="product-cell__name" href="{{ route('admin.products.edit', $product) }}">{{ $product->name }}</a>
                                        @endif
                                        <div class="product-cell__meta">
                                            @if ($product->sku)<span class="mono">{{ $product->sku }}</span>@endif
                                            <button type="button" class="btn btn--plain btn--sm" style="height:auto;padding:0" @click="open = !open" :aria-expanded="open.toString()">
                                                <span x-text="open ? 'Hide customers' : 'Show {{ $rows->count() }} {{ Str::plural('customer', $rows->count()) }}'">Show customers</span>
                                            </button>
                                        </div>
                                        <div x-show="open" x-collapse x-cloak>
                                            <ul class="alert-emails mt-2">
                                                @foreach ($rows as $alert)
                                                    <li>
                                                        <a href="mailto:{{ $alert->email }}" class="flex-1 truncate">{{ $alert->email }}</a>
                                                        @if ($alert->variation)<span class="text-xs text-muted">{{ $variantLabels[$alert->variation->id] ?? 'Variant' }}</span>@endif
                                                        <span class="text-xs text-muted nowrap">Asked <x-admin.time :value="$alert->created_at" format="date" /></span>
                                                        @if ($alert->notified_at)
                                                            <x-admin.badge size="sm" color="success">Emailed <x-admin.time :value="$alert->notified_at" format="date" /></x-admin.badge>
                                                        @else
                                                            <x-admin.badge size="sm" color="attention">Waiting</x-admin.badge>
                                                        @endif
                                                        <x-admin.confirm :action="route('admin.stock-alerts.destroy', $alert)" size="sm" variant="ghost-danger" icon="x-mark" :label="'Delete request from '.$alert->email"
                                                                         title="Delete this request?" :message="($alert->email).' won’t be emailed about this product.'" confirm-label="Delete" />
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td data-label="Stock" class="nowrap">
                                @if ($product->trashed())
                                    <x-admin.badge color="danger">Product deleted</x-admin.badge>
                                @elseif ($product->status !== 'published')
                                    <x-admin.status-badge type="product" :status="$product->status" />
                                @else
                                    <x-admin.badge :color="$stock['color']" dot>{{ $stock['label'] }}</x-admin.badge>
                                @endif
                            </td>
                            <td class="num" data-label="Waiting">
                                <strong>{{ (int) $product->waiting }}</strong><span class="text-subtle"> / {{ (int) $product->total }}</span>
                            </td>
                            <td class="nowrap" data-label="Last request"><x-admin.time :value="$product->last_requested" format="date" /></td>
                            <td class="table__actions">
                                @if ((int) $product->waiting > 0)
                                    @if ($available)
                                        <form method="POST" action="{{ route('admin.stock-alerts.notify') }}" style="display:contents"
                                              data-confirm-title="Email {{ (int) $product->waiting }} waiting {{ Str::plural('customer', (int) $product->waiting) }}?" data-confirm="They each get one “It’s back in stock” email." data-confirm-button="Send" data-confirm-danger="false">
                                            @csrf
                                            <input type="hidden" name="product_id" value="{{ $product->id }}">
                                            <x-admin.button type="submit" size="sm" variant="primary" icon="paper-airplane">Notify now</x-admin.button>
                                        </form>
                                    @elseif (! $product->trashed())
                                        <x-admin.button size="sm" :href="route('admin.products.edit', $product)" icon="pencil-square">Restock</x-admin.button>
                                    @endif
                                @else
                                    <span class="text-xs text-muted">All emailed</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-admin.table>
                <x-admin.pagination :paginator="$products" />
            @endif
        </x-admin.card>
        <p class="text-xs text-muted mt-3">Customers are emailed automatically when you put a product back in stock (in the product editor, the quick edit, the inventory page or a bulk action).</p>
    @endif
@endsection
