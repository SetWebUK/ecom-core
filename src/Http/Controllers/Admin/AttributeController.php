<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Support\Sql;
use Pine\Commerce\Http\Controllers\Admin\Concerns\AdminIndex;
use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Http\Requests\Admin\Catalogue\AttributeRequest;
use Pine\Commerce\Http\Requests\Admin\Catalogue\AttributeValueRequest;
use Pine\Commerce\Models\Attribute;
use Pine\Commerce\Models\AttributeValue;
use Pine\Commerce\Models\ProductVariation;
use Pine\Commerce\Services\Admin\Catalogue\ProductSaver;
use Pine\Commerce\Services\Admin\CatalogueTools;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Product attributes (Size, Colour, …) and their values.
 *
 *   resource admin/attributes                                 admin.attributes.*  (no show; store also answers JSON)
 *   POST   admin/attributes/{attribute}/values                admin.attributes.values.store     form or JSON {id,value,slug}
 *   PATCH  admin/attributes/{attribute}/values/{value}        admin.attributes.values.update    rename (form or JSON)
 *   DELETE admin/attributes/{attribute}/values/{value}        admin.attributes.values.destroy   only when unused
 *   POST   admin/attributes/{attribute}/values/reorder        admin.attributes.values.reorder   JSON {ids: [...]}
 *   POST   admin/attributes/{attribute}/values/merge          admin.attributes.values.merge     ids[] + target_id
 */
class AttributeController extends Controller
{
    use AdminIndex;

    /** Attribute slugs the storefront's filter sidebar is built on (Facets::filters(), config commerce.catalog.filters). */
    public static function lockedSlugs(): array
    {
        return class_exists(\Pine\Commerce\Services\Catalog\Facets::class) ? array_keys(\Pine\Commerce\Services\Catalog\Facets::filters()) : [];
    }

    public function index(Request $request): View
    {
        $q = $this->searchTerm($request);
        [$sort, $direction] = $this->sorting($request, ['name', 'values_count', 'products_count'], 'name');

        $attributes = Attribute::query()
            ->withCount('values')
            ->selectSub(fn ($sub) => $sub->from('attribute_value_product')
                ->join('attribute_values', 'attribute_values.id', '=', 'attribute_value_product.attribute_value_id')
                ->whereColumn('attribute_values.attribute_id', 'attributes.id')
                ->selectRaw(Sql::qualify('COUNT(DISTINCT attribute_value_product.product_id)', ['attribute_value_product', 'products'])), 'products_count')
            ->with(['values' => fn ($v) => $v->select('id', 'attribute_id', 'value', 'sort_order')])
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('name', 'like', $this->like($q))->orWhere('slug', 'like', $this->like($q))
                ->orWhereHas('values', fn ($v) => $v->where('value', 'like', $this->like($q)))))
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate($this->perPage($request, 50))->withQueryString();

        return view('commerce::admin.attributes.index', [
            'attributes' => $attributes,
            'q' => $q,
            'locked' => static::lockedSlugs(),
            'total' => Attribute::count(),
        ]);
    }

    public function create(): View
    {
        return view('commerce::admin.attributes.form', $this->formData(new Attribute(['is_filterable' => false])));
    }

    public function store(AttributeRequest $request): RedirectResponse|JsonResponse
    {
        $attribute = Attribute::create($request->attributeData() + ['sort_order' => (int) Attribute::max('sort_order') + 1]);

        if ($request->expectsJson()) {
            return response()->json(['id' => $attribute->id, 'name' => $attribute->name, 'slug' => $attribute->slug, 'values' => [],
                'message' => "Attribute “{$attribute->name}” created."], 201);
        }

        return redirect()->route('admin.attributes.edit', $attribute)->with('success', "Attribute “{$attribute->name}” created. Now add its values.");
    }

    public function edit(Attribute $attribute): View
    {
        return view('commerce::admin.attributes.form', $this->formData($attribute));
    }

    public function update(AttributeRequest $request, Attribute $attribute): RedirectResponse
    {
        $data = $request->attributeData();
        if (in_array($attribute->slug, static::lockedSlugs(), true)) {
            $data['slug'] = $attribute->slug; // the shop's filters use this slug
        }
        $oldSlug = $attribute->slug;

        DB::transaction(function () use ($attribute, $data, $oldSlug) {
            $attribute->update($data);
            if ($oldSlug !== $attribute->slug) {
                // Variant options are stored as {"attribute-slug": "value-slug"}
                $this->rewriteVariationOptions(fn (array $options) => array_key_exists($oldSlug, $options)
                    ? collect($options)->mapWithKeys(fn ($v, $k) => [$k === $oldSlug ? $attribute->slug : $k => $v])->all()
                    : null);
            }
        });
        CatalogueTools::flushStorefrontCaches();

        return redirect()->route('admin.attributes.edit', $attribute)->with('success', 'Attribute saved.');
    }

    public function destroy(Attribute $attribute): RedirectResponse
    {
        if (in_array($attribute->slug, static::lockedSlugs(), true)) {
            return back()->with('error', "“{$attribute->name}” is used by the shop’s filters and can’t be deleted.");
        }
        $variants = $this->variationsUsing($attribute->slug);
        if ($variants) {
            return back()->with('error', "“{$attribute->name}” is used by {$variants} product ".Str::plural('variant', $variants).'. Remove those variants first.');
        }
        $name = $attribute->name;
        $attribute->delete(); // values, product links and product_attributes rows cascade
        CatalogueTools::flushStorefrontCaches();

        return redirect()->route('admin.attributes.index')->with('success', "Attribute “{$name}” deleted.");
    }

    // Values -----------------------------------------------------------------------------------------

    public function storeValue(AttributeValueRequest $request, Attribute $attribute): RedirectResponse|JsonResponse
    {
        $text = $request->input('value');
        $existing = $attribute->values()->whereRaw('LOWER(value) = ?', [mb_strtolower($text)])->first();
        $value = $existing ?? $attribute->values()->create([
            'value' => $text,
            'slug' => ProductSaver::uniqueValueSlug($attribute, $text),
            'sort_order' => (int) $attribute->values()->max('sort_order') + 1,
        ]);

        if ($request->expectsJson()) {
            return response()->json(['id' => $value->id, 'value' => $value->value, 'slug' => $value->slug,
                'message' => $existing ? "“{$value->value}” already existed – selected it." : "“{$value->value}” added to {$attribute->name}."], $existing ? 200 : 201);
        }

        return back()->with('success', "“{$value->value}” added.");
    }

    public function updateValue(AttributeValueRequest $request, Attribute $attribute, AttributeValue $value): RedirectResponse|JsonResponse
    {
        // The slug stays the same so filter links and variant options keep working; only the shown name changes.
        $value->update(['value' => $request->input('value')]);
        CatalogueTools::flushStorefrontCaches();

        if ($request->expectsJson()) {
            return response()->json(['id' => $value->id, 'value' => $value->value, 'message' => 'Value renamed.']);
        }

        return back()->with('success', 'Value renamed.');
    }

    public function destroyValue(Request $request, Attribute $attribute, AttributeValue $value): RedirectResponse|JsonResponse
    {
        $products = $value->products()->count();
        $variants = $this->variationsUsing($attribute->slug, $value->slug);
        if ($products || $variants) {
            $message = "“{$value->value}” is used by ".($products ? $products.' '.Str::plural('product', $products) : '')
                .($products && $variants ? ' and ' : '').($variants ? $variants.' '.Str::plural('variant', $variants) : '')
                .'. Merge it into another value instead.';

            return $request->expectsJson() ? response()->json(['message' => $message], 422) : back()->with('error', $message);
        }
        $value->delete();
        CatalogueTools::flushStorefrontCaches();

        return $request->expectsJson() ? response()->json(['message' => 'Value deleted.']) : back()->with('success', "“{$value->value}” deleted.");
    }

    public function reorderValues(Request $request, Attribute $attribute): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'max:2000'], 'ids.*' => ['integer']])['ids'];
        $own = $attribute->values()->pluck('id')->all();
        DB::transaction(function () use ($ids, $own) {
            foreach (array_values(array_intersect(array_map('intval', $ids), $own)) as $position => $id) {
                AttributeValue::query()->whereKey($id)->update(['sort_order' => $position]);
            }
        });
        CatalogueTools::flushStorefrontCaches();

        return response()->json(['message' => 'New order saved.']);
    }

    /** Merge duplicate values: products and variants using the merged values move to the target, then the duplicates are deleted. */
    public function mergeValues(Request $request, Attribute $attribute): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', Rule::exists('attribute_values', 'id')->where('attribute_id', $attribute->id)],
            'target_id' => ['required', 'integer', Rule::exists('attribute_values', 'id')->where('attribute_id', $attribute->id)],
        ], [
            'ids.required' => 'Select the values to merge.',
            'target_id.required' => 'Choose the value to keep.',
        ]);
        $target = AttributeValue::findOrFail((int) $validated['target_id']);
        $merge = AttributeValue::query()->whereIn('id', array_diff(array_map('intval', $validated['ids']), [$target->id]))->get();
        if ($merge->isEmpty()) {
            return back()->with('warning', 'Select at least one value other than the one you keep.');
        }

        DB::transaction(function () use ($attribute, $target, $merge) {
            $productIds = DB::table('attribute_value_product')->whereIn('attribute_value_id', $merge->pluck('id'))->pluck('product_id')->unique();
            DB::table('attribute_value_product')->insertOrIgnore($productIds->map(fn ($id) => ['attribute_value_id' => $target->id, 'product_id' => $id])->all());
            $slugs = $merge->pluck('slug')->all();
            $this->rewriteVariationOptions(fn (array $options) => isset($options[$attribute->slug]) && in_array($options[$attribute->slug], $slugs, true)
                ? array_merge($options, [$attribute->slug => $target->slug]) : null);
            AttributeValue::query()->whereIn('id', $merge->pluck('id'))->delete();
        });
        CatalogueTools::flushStorefrontCaches();

        $count = $merge->count();

        return back()->with('success', "{$count} ".Str::plural('value', $count)." merged into “{$target->value}”.");
    }

    // Helpers ------------------------------------------------------------------------------------------

    protected function formData(Attribute $attribute): array
    {
        $values = collect();
        if ($attribute->exists) {
            $values = $attribute->values()
                ->withCount(['products' => fn ($q) => $q->whereNull('products.deleted_at')])
                ->get(['id', 'attribute_id', 'value', 'slug', 'sort_order']);
            $variantUse = $this->variationUsageBySlug($attribute->slug);
            $values->each(fn ($v) => $v->setAttribute('variants_count', $variantUse[$v->slug] ?? 0));
        }

        return [
            'attribute' => $attribute,
            'values' => $values,
            'productCount' => $attribute->exists ? DB::table('attribute_value_product')
                ->join('attribute_values', 'attribute_values.id', '=', 'attribute_value_product.attribute_value_id')
                ->join('products', 'products.id', '=', 'attribute_value_product.product_id')
                ->where('attribute_values.attribute_id', $attribute->id)->whereNull('products.deleted_at')
                ->distinct()->count('attribute_value_product.product_id') : 0,
            'locked' => in_array($attribute->slug, static::lockedSlugs(), true),
            'duplicates' => $values->groupBy(fn ($v) => Str::slug($v->value))->filter(fn ($g) => $g->count() > 1)->count(),
        ];
    }

    /** Number of variants whose options use this attribute (and value). */
    protected function variationsUsing(string $attributeSlug, ?string $valueSlug = null): int
    {
        $usage = $this->variationUsageBySlug($attributeSlug);

        return $valueSlug === null ? array_sum($usage) : ($usage[$valueSlug] ?? 0);
    }

    /** @return array<string,int> value slug => variants using it */
    protected function variationUsageBySlug(string $attributeSlug): array
    {
        $usage = [];
        ProductVariation::query()->select(['id', 'options'])->where('options', 'like', '%"'.addcslashes($attributeSlug, '%_\\').'"%')
            ->each(function (ProductVariation $variation) use ($attributeSlug, &$usage) {
                $value = ((array) $variation->options)[$attributeSlug] ?? null;
                if (is_string($value)) {
                    $usage[$value] = ($usage[$value] ?? 0) + 1;
                }
            });

        return $usage;
    }

    /** Apply $map(options) to every variant; $map returns the new options or null to leave the variant alone. */
    protected function rewriteVariationOptions(callable $map): void
    {
        ProductVariation::query()->select(['id', 'options'])->each(function (ProductVariation $variation) use ($map) {
            $new = $map((array) $variation->options);
            if ($new !== null) {
                ProductVariation::query()->whereKey($variation->id)->update(['options' => json_encode($new)]);
            }
        });
    }
}
