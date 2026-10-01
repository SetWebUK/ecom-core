{{--
    Search-and-pick products (JSON: admin.api.products). Posts product_ids[] (multiple) or product_id (single).
    <x-admin.product-picker name="product_ids" label="Products" :value="$coupon->product_ids" help="…" />
    <x-admin.product-picker name="product_id" label="Product" :value="$item->product_id" :multiple="false" />
    Props: name, label, value (id or ids), multiple (default true), help, placeholder, id, required
    Validate: 'product_ids' => ['array'], 'product_ids.*' => ['integer', Rule::exists('products', 'id')]
--}}
@props(['name', 'label' => null, 'value' => [], 'multiple' => true, 'help' => null, 'placeholder' => 'Search products by name or SKU', 'id' => null, 'required' => false])
@php
    $key = \Pine\Commerce\View\Components\Admin\Ui::key($name);
    $id ??= \Pine\Commerce\View\Components\Admin\Ui::id($name);
    $ids = collect(old($key, $value))->flatten()->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (int) $v)->unique()->values();
    $selected = $ids->isEmpty() ? [] : \Pine\Commerce\Models\Product::withTrashed()
        ->with(['images' => fn ($q) => $q->orderBy('sort_order')->limit(1)])
        ->whereIn('id', $ids)->get(['id', 'name', 'sku', 'status', 'price', 'type', 'stock_status'])
        ->sortBy(fn ($p) => $ids->search($p->id))
        ->map(fn ($p) => \Pine\Commerce\Http\Controllers\Admin\SearchController::productOption($p))->values()->all();
@endphp
@include('commerce::admin.partials.picker', [
    'name' => $name, 'key' => $key, 'id' => $id, 'label' => $label, 'help' => $help, 'placeholder' => $placeholder, 'required' => $required,
    'multiple' => $multiple, 'selected' => $selected, 'endpoint' => route('admin.api.products'), 'options' => null, 'images' => true,
    'attributes' => $attributes,
])
