{{--
    Pick categories (whole tree searched in the browser – no requests). Posts category_ids[] (multiple) or the single id.
    <x-admin.category-picker name="category_ids" label="Categories" :value="$coupon->category_ids" />
    Props: name, label, value, multiple (default true), help, placeholder, id, required, exclude (id whose subtree is hidden, e.g. the category being edited)
--}}
@props(['name', 'label' => null, 'value' => [], 'multiple' => true, 'help' => null, 'placeholder' => 'Search categories', 'id' => null, 'required' => false, 'exclude' => null])
@php
    $key = \Pine\Commerce\View\Components\Admin\Ui::key($name);
    $id ??= \Pine\Commerce\View\Components\Admin\Ui::id($name);
    $ids = collect(old($key, $value))->flatten()->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (int) $v)->unique()->values();
    $excluded = $exclude ? (\Pine\Commerce\Models\Category::find($exclude)?->descendantIds() ?? [$exclude]) : [];
    $all = \Pine\Commerce\Services\Admin\CategoryTree::flat()->reject(fn ($c) => in_array($c->id, $excluded, true));
    $options = $all->map(fn ($c) => ['id' => $c->id, 'label' => str_repeat('— ', (int) $c->depth).$c->name, 'sub' => '/'.$c->path.'/', 'image' => null])->values()->all();
    $byId = collect($options)->keyBy('id');
    $selected = $ids->map(fn ($i) => $byId->get($i))->filter()->map(fn ($o) => array_merge($o, ['label' => preg_replace('/^(— )+/u', '', $o['label'])]))->values()->all();
@endphp
@include('commerce::admin.partials.picker', [
    'name' => $name, 'key' => $key, 'id' => $id, 'label' => $label, 'help' => $help, 'placeholder' => $placeholder, 'required' => $required,
    'multiple' => $multiple, 'selected' => $selected, 'endpoint' => null, 'options' => $options, 'images' => false,
    'attributes' => $attributes,
])
