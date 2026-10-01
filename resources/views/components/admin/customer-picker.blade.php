{{--
    Search customers by name, email or phone (JSON: admin.api.customers). Single by default.
    <x-admin.customer-picker name="user_id" label="Customer" :value="$order->user_id" />
    Props: name, label, value, multiple (default false), help, placeholder, id, required
--}}
@props(['name', 'label' => null, 'value' => null, 'multiple' => false, 'help' => null, 'placeholder' => 'Search customers by name, email or phone', 'id' => null, 'required' => false])
@php
    $key = \Pine\Commerce\View\Components\Admin\Ui::key($name);
    $id ??= \Pine\Commerce\View\Components\Admin\Ui::id($name);
    $ids = collect(old($key, $value))->flatten()->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (int) $v)->unique()->values();
    $selected = $ids->isEmpty() ? [] : \Pine\Commerce\Models\User::withCount('orders')->whereIn('id', $ids)->get(['id', 'name', 'first_name', 'last_name', 'email', 'role'])
        ->map(fn ($u) => \Pine\Commerce\Http\Controllers\Admin\SearchController::customerOption($u))->values()->all();
@endphp
@include('commerce::admin.partials.picker', [
    'name' => $name, 'key' => $key, 'id' => $id, 'label' => $label, 'help' => $help, 'placeholder' => $placeholder, 'required' => $required,
    'multiple' => $multiple, 'selected' => $selected, 'endpoint' => route('admin.api.customers'), 'options' => null, 'images' => false,
    'attributes' => $attributes,
])
