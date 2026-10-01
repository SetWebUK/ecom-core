{{-- Living style guide: /admin/ui-kit. Every <x-admin.*> component with real data. Keep in sync with docs/ADMIN_UI.md. --}}
@extends('commerce::admin.layouts.app')

@section('title', 'UI kit')

@section('content')
    <x-admin.page-header title="UI kit" subtitle="Every back-office component, live. Source: resources/views/admin/dashboard/ui-kit.blade.php · Docs: docs/ADMIN_UI.md" :back="route('admin.dashboard')">
        <x-slot:badges><x-admin.badge color="info">Developers</x-admin.badge></x-slot:badges>
        <x-slot:actions>
            <x-admin.dropdown label="More actions">
                <x-admin.dropdown-item icon="printer" href="#">Print</x-admin.dropdown-item>
                <x-admin.dropdown-item icon="document-duplicate">Duplicate</x-admin.dropdown-item>
                <div class="dropdown__sep"></div>
                <x-admin.dropdown-item icon="trash" danger x-on:click="Admin.confirm({ title: 'Delete this demo?', message: 'Nothing will actually be deleted.' })">Delete</x-admin.dropdown-item>
            </x-admin.dropdown>
            <x-admin.button variant="primary" icon="plus">Primary action</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.form action="#" dirty id="ui-kit-form" x-on:submit.prevent="Admin.toast('Demo form – nothing was saved', 'info'); $dispatch('dirty-reset')">
        <div class="layout">
            <div class="layout__main">
                <x-admin.card title="Buttons" subtitle="variant: secondary (default) · primary · danger · dark · ghost · ghost-danger · plain — size: sm · md · lg">
                    <div class="row">
                        <x-admin.button>Secondary</x-admin.button>
                        <x-admin.button variant="primary" icon="check">Primary</x-admin.button>
                        <x-admin.button variant="danger" icon="trash">Danger</x-admin.button>
                        <x-admin.button variant="dark">Dark</x-admin.button>
                        <x-admin.button variant="ghost">Ghost</x-admin.button>
                        <x-admin.button variant="ghost-danger">Ghost danger</x-admin.button>
                        <x-admin.button variant="plain">Plain link</x-admin.button>
                    </div>
                    <div class="row mt-4">
                        <x-admin.button size="sm" icon="funnel">Small</x-admin.button>
                        <x-admin.button size="lg">Large</x-admin.button>
                        <x-admin.button icon="pencil-square" label="Edit" />
                        <x-admin.button icon="trash" label="Delete" variant="ghost-danger" />
                        <x-admin.button disabled>Disabled</x-admin.button>
                        <x-admin.button class="is-loading" variant="primary">Loading</x-admin.button>
                        <div class="btn-group"><x-admin.button>Day</x-admin.button><x-admin.button>Week</x-admin.button><x-admin.button>Month</x-admin.button></div>
                    </div>
                </x-admin.card>

                <x-admin.card title="Badges">
                    <div class="row">
                        <x-admin.badge>Gray</x-admin.badge>
                        <x-admin.badge color="success" dot>Success</x-admin.badge>
                        <x-admin.badge color="warning">Warning</x-admin.badge>
                        <x-admin.badge color="attention" dot>Attention</x-admin.badge>
                        <x-admin.badge color="danger" icon="exclamation-triangle">Danger</x-admin.badge>
                        <x-admin.badge color="info">Info</x-admin.badge>
                        <x-admin.badge color="dark">Dark</x-admin.badge>
                        <x-admin.badge color="outline">Outline</x-admin.badge>
                    </div>
                    <div class="row mt-4">
                        @foreach (Pine\Commerce\Models\Order::STATUSES as $status => $label)
                            <x-admin.status-badge :status="$status" />
                        @endforeach
                        <x-admin.status-badge type="stock" status="outofstock" />
                        <x-admin.status-badge type="product" status="draft" />
                    </div>
                </x-admin.card>

                <x-admin.card title="Form controls">
                    <div class="stack-fields">
                        <div class="form-grid">
                            <x-admin.input name="demo_name" id="f-demo-name" label="Product name" required help="Help text sits under the control." />
                            <x-admin.input name="demo_slug" label="URL handle" prefix="/shop/" x-data="slugField('#f-demo-name')" hint="Follows the name" />
                        </div>
                        <div class="form-grid">
                            <x-admin.money name="demo_price" label="Price" value="1299.5" />
                            <x-admin.input name="demo_weight" label="Weight" type="number" step="0.001" suffix="kg" optional />
                        </div>
                        <x-admin.input name="demo_title" label="Input with counter" counter="60" value="Classic Linen Shirt" />
                        <x-admin.textarea name="demo_note" label="Textarea" rows="3" counter="255" />
                        <div class="form-grid">
                            <x-admin.select name="demo_status" label="Select" :options="Pine\Commerce\Services\Admin\OrderStatus::PRODUCT_STATUSES" value="published" />
                            <x-admin.datetime name="demo_date" label="Date & time" :value="now()" />
                        </div>
                        <x-admin.radio-cards name="demo_type" label="Radio cards" value="simple" :options="[
                            'simple' => ['label' => 'Simple product', 'help' => 'One price, one stock level', 'icon' => 'cube'],
                            'variable' => ['label' => 'Variable product', 'help' => 'Options like memory or colour', 'icon' => 'squares-2x2'],
                        ]" />
                        <x-admin.checkbox name="demo_check" label="Checkbox" help="With help text" checked />
                        <x-admin.toggle name="demo_toggle" label="Toggle switch" help="Posts 1 / 0" checked />
                        <x-admin.field label="Custom control in a field" for="f-demo-custom" help="Wrap anything in <x-admin.field>.">
                            <div class="input-group"><span class="input-group__addon"><x-admin.icon name="magnifying-glass" size="sm" /></span><input id="f-demo-custom" class="input" placeholder="Search…"><button type="button" class="btn btn--sm">Go</button></div>
                        </x-admin.field>
                    </div>
                </x-admin.card>

                <x-admin.card title="Pickers">
                    <div class="stack-fields">
                        <x-admin.product-picker name="demo_products" label="Products (multiple)" :value="$products->pluck('id')->take(1)->all()" />
                        <x-admin.category-picker name="demo_categories" label="Categories" />
                        <x-admin.customer-picker name="demo_customer" label="Customer (single)" />
                    </div>
                </x-admin.card>

                <x-admin.card title="Images">
                    <div class="stack-fields">
                        <x-admin.image-picker name="demo_image" label="Single image" :value="$products->first()?->images->first()?->path" />
                        <x-admin.image-picker name="demo_gallery" label="Gallery (drag to reorder, first = main)" multiple :value="$products->map(fn ($p) => $p->images->first())->filter()->values()" />
                    </div>
                </x-admin.card>

                <x-admin.card title="Rich text">
                    <x-admin.rich-editor name="demo_html" label="Description" height="320"
                                         value='<h2>Imported HTML survives</h2><p class="lead" data-track="x" style="color:#1e88e5">Classes, <strong>inline styles</strong> and data-* attributes are kept.</p><div class="elementor-widget-container"><span class="fa fa-check"></span> Empty icon elements too.</div>' />
                </x-admin.card>

                <x-admin.card title="Search engine listing">
                    <x-admin.seo-fields title-name="demo_meta_title" description-name="demo_meta_description" fallback-title="Classic Linen Shirt"
                                        title-source="#f-demo-name" :url="url('clothing/demo-product')" />
                </x-admin.card>

                <x-admin.card title="Tabs" flush>
                    <x-admin.tabs :tabs="['general' => 'General', 'inventory' => 'Inventory', 'shipping' => 'Shipping']">
                        <x-admin.tab-panel name="general" class="card__body">General panel.</x-admin.tab-panel>
                        <x-admin.tab-panel name="inventory" class="card__body">Inventory panel.</x-admin.tab-panel>
                        <x-admin.tab-panel name="shipping" class="card__body">Shipping panel.</x-admin.tab-panel>
                    </x-admin.tabs>
                </x-admin.card>
            </div>

            <div class="layout__aside">
                <x-admin.stat label="Revenue" icon="banknotes" :value="money(12480)" :delta="12.5" hint="vs previous 30 days" />
                <x-admin.stat label="Refunds" icon="receipt-refund" :value="money(320)" :delta="8" invert hint="up is bad" />

                <x-admin.card title="Timeline">
                    <x-admin.timeline>
                        <x-admin.timeline-item icon="chat-bubble-left" author="Sam" :time="now()->subMinutes(5)" bubble>Called the customer, sending tomorrow.</x-admin.timeline-item>
                        <x-admin.timeline-item icon="envelope" color="primary" author="Sam" :time="now()->subHours(3)" customer>Note emailed to the customer.</x-admin.timeline-item>
                        <x-admin.timeline-item icon="check" color="success" :time="now()->subDay()">Payment of £299.00 received.</x-admin.timeline-item>
                    </x-admin.timeline>
                </x-admin.card>

                <x-admin.card title="Key/value">
                    <dl class="kv">
                        <dt>Order</dt><dd>#{{ $orders->first()?->number }}</dd>
                        <dt>Placed</dt><dd><x-admin.time :value="$orders->first()?->created_at" /></dd>
                        <dt>Progress</dt><dd><div class="progress" style="margin-top:7px"><div class="progress__bar" style="width:60%"></div></div></dd>
                    </dl>
                </x-admin.card>

                <x-admin.card title="Overlays">
                    <div class="row">
                        <x-admin.button x-data x-on:click="$dispatch('open-modal', 'demo-modal')">Modal</x-admin.button>
                        <x-admin.button x-data x-on:click="$dispatch('open-modal', 'demo-drawer')">Drawer</x-admin.button>
                        <x-admin.button x-data x-on:click="Admin.confirm({ title: 'Archive 3 orders?', message: 'You can unarchive them later.', confirmText: 'Archive', danger: false })">Confirm</x-admin.button>
                    </div>
                    <div class="row mt-2">
                        <x-admin.button size="sm" x-data x-on:click="Admin.toast('Product saved')">Toast</x-admin.button>
                        <x-admin.button size="sm" x-data x-on:click="Admin.toast('Could not reach PayPal', 'error')">Error toast</x-admin.button>
                        <x-admin.button size="sm" x-data x-on:click="Admin.toast('Stock is low', 'warning')">Warning</x-admin.button>
                    </div>
                </x-admin.card>

                <x-admin.card title="Sortable list" flush subtitle="Drag the handles">
                    <x-admin.sortable-list class="mt-2">
                        @foreach (['Home', 'Shop', 'Sale', 'Blog'] as $i => $item)
                            <li class="sortable-list__item" data-id="{{ $i }}">
                                <span class="drag-handle" aria-hidden="true"><x-admin.icon name="bars-2" size="sm" /></span>
                                <span class="flex-1">{{ $item }}</span>
                                <input type="hidden" name="demo_positions[{{ $i }}]" value="{{ $i }}" data-position>
                            </li>
                        @endforeach
                    </x-admin.sortable-list>
                </x-admin.card>

                <x-admin.callout title="Callout (info)">Neutral information for the page.</x-admin.callout>
                <x-admin.callout type="success">Saved successfully.</x-admin.callout>
                <x-admin.callout type="warning" title="Heads up">This product has no images.</x-admin.callout>
                <x-admin.callout type="danger">Payment failed.</x-admin.callout>

                <x-admin.card title="Loading & empty">
                    <div class="row"><span class="spinner"></span><span class="spinner spinner--sm"></span></div>
                    <div class="mt-4"><span class="skeleton skeleton--title"></span><span class="skeleton skeleton--text"></span><span class="skeleton skeleton--text" style="width:70%"></span></div>
                    <x-admin.empty icon="inbox" title="Nothing here yet" description="Empty states explain what will appear and offer the next step." size="sm">
                        <x-admin.button size="sm" variant="primary">Add something</x-admin.button>
                    </x-admin.empty>
                </x-admin.card>
            </div>
        </div>
    </x-admin.form>

    <div class="stack mt-6">
        <x-admin.card flush>
            <x-admin.status-tabs :tabs="['all' => ['label' => 'All', 'count' => 3], 'processing' => ['label' => 'Processing', 'count' => 1], 'completed' => 'Completed']" current="all" />
            <x-admin.filters placeholder="Search orders" :chips="request('status_demo') ? ['status_demo' => 'Demo chip'] : []">
                <x-admin.filter-select name="demo_status_filter" :options="Pine\Commerce\Models\Order::STATUSES" placeholder="Any status" />
            </x-admin.filters>
            <x-admin.table :ids="$orders->pluck('id')" selectable bulk-action="#" stack>
                <x-slot:bulk>
                    <x-admin.button type="button" size="sm" icon="printer">Print</x-admin.button>
                </x-slot:bulk>
                <x-slot:head>
                    <x-admin.th sort="number">Order</x-admin.th>
                    <x-admin.th>Customer</x-admin.th>
                    <x-admin.th>Status</x-admin.th>
                    <x-admin.th sort="total" align="right">Total</x-admin.th>
                    <x-admin.th sort="created_at">Date</x-admin.th>
                </x-slot:head>
                @foreach ($orders as $order)
                    <tr>
                        <x-admin.row-check :id="$order->id" :label="'Select order '.$order->number" />
                        <td class="stack-title"><span class="row-link">#{{ $order->number }}</span></td>
                        <td data-label="Customer">{{ $order->billing_name ?: $order->email }}</td>
                        <td data-label="Status"><x-admin.status-badge :status="$order->status" /></td>
                        <td class="num" data-label="Total">{{ money($order->total) }}</td>
                        <td class="nowrap" data-label="Date"><x-admin.time :value="$order->created_at" /></td>
                    </tr>
                @endforeach
            </x-admin.table>
        </x-admin.card>

        <x-admin.card title="Products list with thumbnails" flush>
            <ul class="list mt-2">
                @foreach ($products as $product)
                    <li class="list__item">
                        <x-admin.thumb :src="$product->images->first()?->path" :alt="$product->name" />
                        <span class="list__main"><span class="list__title" style="display:block">{{ $product->name }}</span><span class="list__sub" style="display:block">SKU {{ $product->sku }}</span></span>
                        <span class="list__meta">{{ money($product->price) }}</span>
                    </li>
                @endforeach
            </ul>
        </x-admin.card>
    </div>

    <x-admin.modal name="demo-modal" title="Add note">
        <x-admin.textarea name="modal_note" label="Note" rows="3" />
        <x-slot:footer>
            <x-admin.button x-on:click="hide()">Cancel</x-admin.button>
            <x-admin.button variant="primary" x-on:click="hide(); Admin.toast('Note added')">Add note</x-admin.button>
        </x-slot:footer>
    </x-admin.modal>

    <x-admin.modal name="demo-drawer" title="Filters" drawer>
        <div class="stack-fields">
            <x-admin.select name="drawer_status" label="Status" :options="Pine\Commerce\Models\Order::STATUSES" placeholder="Any" />
            <x-admin.datetime name="drawer_from" label="From" type="date" />
        </div>
        <x-slot:footer>
            <x-admin.button x-on:click="hide()">Close</x-admin.button>
        </x-slot:footer>
    </x-admin.modal>
@endsection
