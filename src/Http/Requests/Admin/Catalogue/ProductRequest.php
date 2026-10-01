<?php

namespace Pine\Commerce\Http\Requests\Admin\Catalogue;

use Pine\Commerce\Models\Attribute;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ProductVariation;
use Pine\Commerce\Services\Admin\LocalTime;
use Pine\Commerce\Services\Admin\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create/update a product (the one-page product editor). The controller never saves $request->all():
 * productData() returns the exact product columns and the other *() methods return normalised relation data,
 * which Pine\Commerce\Services\Admin\Catalogue\ProductSaver writes in one transaction.
 */
class ProductRequest extends FormRequest
{
    public const MONEY_FIELDS = ['regular_price', 'sale_price', 'cost_price'];

    public const DECIMAL_FIELDS = ['weight', 'length', 'width', 'height'];

    /**
     * Attributes edited in the side panel rather than in the Attributes card: role => attribute slug. Condition
     * (config commerce.catalog.condition_attribute, flag product_condition) and Brand (commerce.catalog.brand_attribute,
     * flag product_brand); a role whose flag is off is not offered at all.
     *
     * @return array<string, string> e.g. ['condition' => 'condition', 'brand' => 'make']
     */
    public static function asideAttributes(): array
    {
        return array_filter([
            'condition' => commerce_feature('product_condition') ? (string) config('commerce.catalog.condition_attribute', 'condition') : null,
            'brand' => commerce_feature('product_brand') ? (string) config('commerce.catalog.brand_attribute', 'brand') : null,
        ]);
    }

    public const IMAGE_PATH = '/^(?!.*\.\.)[A-Za-z0-9][A-Za-z0-9\/_\-.() ]*\.(jpe?g|png|gif|webp|avif)$/i';

    protected ?Collection $attributeCache = null;

    public function authorize(): bool
    {
        return (bool) $this->user()?->canAccessAdmin();
    }

    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach (array_merge(self::MONEY_FIELDS, self::DECIMAL_FIELDS) as $field) {
            if (is_string($this->input($field))) {
                $clean[$field] = trim(str_replace(['£', ',', ' '], '', $this->input($field)));
            }
        }
        foreach (['name', 'sku', 'gtin', 'mpn', 'subtitle', 'brand', 'condition', 'focus_keyword', 'google_product_category'] as $field) {
            if (is_string($this->input($field))) {
                $clean[$field] = trim($this->input($field));
            }
        }
        // Browsers post textarea line breaks as CRLF: keep the stored HTML byte-for-byte when nothing was edited
        foreach (['description', 'short_description'] as $field) {
            if (is_string($this->input($field))) {
                $clean[$field] = str_replace("\r\n", "\n", $this->input($field));
            }
        }
        $slug = $this->input('slug');
        $clean['slug'] = is_string($slug) && trim($slug) !== '' ? Str::slug(trim($slug)) : (is_string($this->input('name')) ? Str::limit(Str::slug($this->input('name')), 180, '') : '');

        // Lists: drop the empty sentinel inputs the pickers/cards post when nothing is selected
        foreach (['category_ids', 'upsell_ids', 'cross_sell_ids'] as $field) {
            $clean[$field] = array_values(array_filter((array) $this->input($field, []), fn ($v) => $v !== null && $v !== ''));
        }
        foreach (['images', 'specs', 'product_attributes', 'variations'] as $field) {
            $clean[$field] = array_values(array_filter((array) $this->input($field, []), 'is_array'));
        }

        // Money inside variations
        $variations = $clean['variations'];
        foreach ($variations as $i => $row) {
            foreach (['regular_price', 'sale_price'] as $field) {
                if (isset($row[$field]) && is_string($row[$field])) {
                    $variations[$i][$field] = trim(str_replace(['£', ',', ' '], '', $row[$field]));
                }
            }
            foreach (['sku', 'stock_quantity'] as $field) {
                if (isset($row[$field]) && is_string($row[$field])) {
                    $variations[$i][$field] = trim($row[$field]);
                }
            }
        }
        $clean['variations'] = $variations;
        $this->merge($clean);
    }

    public function rules(): array
    {
        $product = $this->product();
        $variable = $this->input('type') === 'variable';

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:190', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('products', 'slug')->ignore($product?->id)],
            'redirect_old_url' => ['boolean'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500000'],
            'short_description' => ['nullable', 'string', 'max:65000'],
            'status' => ['required', Rule::in(array_keys(OrderStatus::PRODUCT_STATUSES))],
            'published_at' => ['nullable', 'date'],
            'type' => ['required', Rule::in(['simple', 'variable'])],
            'is_featured' => ['boolean'],

            'category_ids' => ['array', 'max:100'],
            'category_ids.*' => ['integer', Rule::exists('categories', 'id')],
            'primary_category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'condition' => ['nullable', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:100'],

            'regular_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'sale_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'cost_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'schedule_sale' => ['boolean'],
            'sale_starts_at' => ['nullable', 'date'],
            'sale_ends_at' => ['nullable', 'date'],

            'sku' => ['nullable', 'string', 'max:100'],
            'gtin' => ['nullable', 'string', 'max:50', 'regex:/^[0-9A-Za-z\- ]*$/'],
            'mpn' => ['nullable', 'string', 'max:100'],
            'manage_stock' => ['boolean'],
            'stock_quantity' => [Rule::requiredIf(! $variable && $this->boolean('manage_stock')), 'nullable', 'integer', 'min:-99999', 'max:9999999'],
            'stock_status' => ['required', Rule::in(array_keys(OrderStatus::STOCK_STATUSES))],
            'backorders' => ['required', Rule::in(['no', 'notify', 'yes'])],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'sold_individually' => ['boolean'],

            'weight' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'tax_status' => ['nullable', Rule::in(['taxable', 'shipping', 'none'])],
            'tax_class' => ['nullable', 'string', 'max:100'],
            'shipping_class_id' => ['nullable', 'integer', 'exists:shipping_classes,id'],
            'variations.*.tax_class' => ['nullable', 'string', 'max:100'],
            'variations.*.shipping_class_id' => ['nullable', 'integer'],
            'length' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'width' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'height' => ['nullable', 'numeric', 'min:0', 'max:999999'],

            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:1000'],
            'focus_keyword' => ['nullable', 'string', 'max:255'],
            'google_product_category' => ['nullable', 'string', 'max:255'],

            'images' => ['array', 'max:60'],
            'images.*.path' => ['required', 'string', 'max:255', 'regex:'.self::IMAGE_PATH],
            'images.*.alt' => ['nullable', 'string', 'max:255'],

            'specs' => ['array', 'max:100'],
            'specs.*.key' => ['nullable', 'string', 'max:50', 'regex:/^[a-z0-9_\-]*$/'],
            'specs.*.label' => ['nullable', 'string', 'max:255'],
            'specs.*.value' => ['nullable', 'string', 'max:2000'],
            'specs.*.description' => ['nullable', 'string', 'max:255'],

            'product_attributes' => ['array', 'max:50'],
            'product_attributes.*.attribute_id' => ['required', 'integer', 'distinct', Rule::exists('attributes', 'id')],
            'product_attributes.*.values' => ['array', 'max:200'],
            'product_attributes.*.values.*' => ['integer'],
            'product_attributes.*.visible' => ['boolean'],
            'product_attributes.*.variation' => ['boolean'],

            'variations' => ['array', 'max:250'],
            'variations.*.id' => ['nullable', 'integer'],
            'variations.*.options' => ['required', 'string', 'max:2000'],
            'variations.*.sku' => ['nullable', 'string', 'max:100'],
            'variations.*.regular_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'variations.*.sale_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'variations.*.stock_quantity' => ['nullable', 'integer', 'min:-99999', 'max:9999999'],
            'variations.*.stock_status' => ['nullable', Rule::in(array_keys(OrderStatus::STOCK_STATUSES))],
            'variations.*.image' => ['nullable', 'string', 'max:255', 'regex:'.self::IMAGE_PATH],
            'variations.*.is_active' => ['boolean'],

            'upsell_ids' => ['array', 'max:50'],
            'upsell_ids.*' => ['integer', Rule::exists('products', 'id')],
            'cross_sell_ids' => ['array', 'max:50'],
            'cross_sell_ids.*' => ['integer', Rule::exists('products', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Give the product a title.',
            'slug.required' => 'Enter a URL handle (letters, numbers and dashes).',
            'slug.regex' => 'Use lower-case letters, numbers and single dashes only.',
            'slug.unique' => 'Another product already uses this URL handle. Try “'.$this->input('slug').'-2”.',
            '*.decimal' => 'Use at most 2 decimal places.',
            'stock_quantity.required' => 'Enter how many you have in stock (or switch off “Track quantity”).',
            'images.*.path.regex' => 'One of the images isn’t a valid image file.',
            'gtin.regex' => 'A barcode can only contain numbers and letters.',
            'variations.*.regular_price.numeric' => 'Enter a valid price for each variant.',
            'variations.*.stock_quantity.integer' => 'Stock must be a whole number.',
            'product_attributes.*.attribute_id.distinct' => 'Each attribute can only be added once.',
            'category_ids.*.exists' => 'One of the chosen categories no longer exists.',
            'upsell_ids.*.exists' => 'One of the linked products no longer exists.',
            'cross_sell_ids.*.exists' => 'One of the linked products no longer exists.',
        ];
    }

    public function attributes(): array
    {
        return [
            'regular_price' => 'price',
            'sale_price' => 'sale price',
            'cost_price' => 'cost per item',
            'stock_quantity' => 'quantity',
            'low_stock_threshold' => 'low stock threshold',
            'sale_starts_at' => 'sale start',
            'sale_ends_at' => 'sale end',
            'gtin' => 'barcode (GTIN)',
            'mpn' => 'MPN',
            'meta_title' => 'page title',
            'meta_description' => 'meta description',
            'variations.*.regular_price' => 'variant price',
            'variations.*.sale_price' => 'variant sale price',
            'variations.*.sku' => 'variant SKU',
            'variations.*.stock_quantity' => 'variant stock',
            'specs.*.label' => 'specification name',
            'specs.*.value' => 'specification value',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $errors = $validator->errors();
                $product = $this->product();
                $variable = $this->input('type') === 'variable';

                // Pricing
                if (! $variable && ! $errors->hasAny(['regular_price', 'sale_price'])) {
                    $regular = $this->input('regular_price');
                    $sale = $this->input('sale_price');
                    if ($this->input('status') === 'published' && ! is_numeric($regular)) {
                        $errors->add('regular_price', 'Enter a price before publishing (customers can’t buy a product without one).');
                    }
                    if (is_numeric($sale) && ! is_numeric($regular)) {
                        $errors->add('sale_price', 'Enter the regular price as well as the sale price.');
                    } elseif (is_numeric($sale) && is_numeric($regular) && (float) $sale >= (float) $regular) {
                        $errors->add('sale_price', 'The sale price must be lower than the regular price.');
                    }
                }
                if ($this->boolean('schedule_sale') && ! $errors->hasAny(['sale_starts_at', 'sale_ends_at'])) {
                    $starts = LocalTime::fromInput($this->input('sale_starts_at'));
                    $ends = LocalTime::fromInput($this->input('sale_ends_at'));
                    if ($starts && $ends && $ends->lte($starts)) {
                        $errors->add('sale_ends_at', 'The sale must end after it starts.');
                    }
                }

                // SKU unique across products and other products' variants
                $sku = (string) $this->input('sku');
                if ($sku !== '' && ! $errors->has('sku')) {
                    $taken = Product::query()->where('sku', $sku)->when($product, fn ($q) => $q->whereKeyNot($product->id))->exists()
                        || ProductVariation::query()->where('sku', $sku)->when($product, fn ($q) => $q->where('product_id', '<>', $product->id))->exists();
                    if ($taken) {
                        $errors->add('sku', 'Another product already uses this SKU.');
                    }
                }

                // Primary category must be one of the chosen categories
                $categoryIds = array_map('intval', (array) $this->input('category_ids', []));
                $primary = $this->input('primary_category_id');
                if ($primary && $categoryIds && ! in_array((int) $primary, $categoryIds, true)) {
                    $errors->add('primary_category_id', 'The main category must be one of the product’s categories.');
                }

                // Specs: a value needs a label
                foreach ((array) $this->input('specs', []) as $i => $row) {
                    if (trim((string) ($row['value'] ?? '')) !== '' && trim((string) ($row['label'] ?? '')) === '') {
                        $errors->add("specs.$i.label", 'Give this specification a name.');
                    }
                }

                // Attribute values must belong to their attribute; Condition/Brand are set in the side panel
                $attributes = $this->allAttributes();
                foreach ((array) $this->input('product_attributes', []) as $i => $row) {
                    $attribute = $attributes->get((int) ($row['attribute_id'] ?? 0));
                    if (! $attribute) {
                        continue;
                    }
                    if (in_array($attribute->slug, self::asideAttributes(), true)) {
                        $errors->add("product_attributes.$i.attribute_id", "{$attribute->name} is set in the side panel.");
                    }
                    $valid = $attribute->values->pluck('id')->all();
                    foreach ((array) ($row['values'] ?? []) as $valueId) {
                        if (! in_array((int) $valueId, $valid, true)) {
                            $errors->add("product_attributes.$i.values", "One of the {$attribute->name} values no longer exists – reload the page.");
                            break;
                        }
                    }
                }

                if ($variable && ! $errors->has('variations')) {
                    $this->validateVariations($errors, $product);
                }
            },
        ];
    }

    protected function validateVariations($errors, ?Product $product): void
    {
        $rows = (array) $this->input('variations', []);
        $attributes = $this->allAttributes()->keyBy('slug');
        $variationAttributes = collect((array) $this->input('product_attributes', []))
            ->filter(fn ($row) => filter_var($row['variation'] ?? false, FILTER_VALIDATE_BOOL))
            ->map(fn ($row) => $this->allAttributes()->get((int) ($row['attribute_id'] ?? 0))?->slug)
            ->filter()->values()->all();
        $ownIds = $product ? $product->variations()->pluck('id')->all() : [];
        $seen = [];
        $skus = [];

        foreach ($rows as $i => $row) {
            $id = $row['id'] ?? null;
            if ($id && ! in_array((int) $id, $ownIds, true)) {
                $errors->add("variations.$i.id", 'This variant belongs to another product – reload the page.');

                continue;
            }
            $options = json_decode((string) ($row['options'] ?? ''), true);
            if (! is_array($options) || ! $options) {
                $errors->add("variations.$i.options", 'A variant needs at least one option (e.g. Memory: 16GB).');

                continue;
            }
            foreach ($options as $attrSlug => $valueSlug) {
                $attribute = $attributes->get((string) $attrSlug);
                if (! $attribute || ! is_string($valueSlug) || ! $attribute->values->contains('slug', $valueSlug)) {
                    $errors->add("variations.$i.options", 'A variant uses an option that no longer exists – remove it or pick another value.');

                    continue 2;
                }
                if ($variationAttributes && ! in_array($attrSlug, $variationAttributes, true)) {
                    $errors->add("variations.$i.options", "A variant uses “{$attribute->name}”, which is no longer used for variants.");

                    continue 2;
                }
            }
            ksort($options);
            $signature = json_encode($options);
            if (isset($seen[$signature])) {
                $errors->add("variations.$i.options", 'Two variants have the same options – remove one of them.');
            }
            $seen[$signature] = true;

            $active = filter_var($row['is_active'] ?? true, FILTER_VALIDATE_BOOL);
            $regular = $row['regular_price'] ?? null;
            $sale = $row['sale_price'] ?? null;
            if ($active && ($regular === null || $regular === '') && $this->input('status') === 'published') {
                $errors->add("variations.$i.regular_price", 'Enter a price for every active variant.');
            }
            if (is_numeric($sale) && is_numeric($regular) && (float) $sale >= (float) $regular) {
                $errors->add("variations.$i.sale_price", 'A variant’s sale price must be lower than its price.');
            }
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku !== '') {
                if (isset($skus[$sku]) || $sku === (string) $this->input('sku')) {
                    $errors->add("variations.$i.sku", "The SKU “{$sku}” is used more than once.");
                } elseif (Product::query()->where('sku', $sku)->when($product, fn ($q) => $q->whereKeyNot($product->id))->exists()
                    || ProductVariation::query()->where('sku', $sku)->when($product, fn ($q) => $q->where('product_id', '<>', $product->id))->exists()) {
                    $errors->add("variations.$i.sku", "Another product already uses the SKU “{$sku}”.");
                }
                $skus[$sku] = true;
            }
        }

        if ($this->input('status') === 'published' && ! collect($rows)->contains(fn ($r) => filter_var($r['is_active'] ?? true, FILTER_VALIDATE_BOOL))) {
            $errors->add('variations', 'Add at least one active variant before publishing, or save as a draft.');
        }
    }

    // Normalised data ------------------------------------------------------------------------------

    public function product(): ?Product
    {
        $product = $this->route('product');

        return $product instanceof Product ? $product : null;
    }

    /** Product columns for fill()/save(). */
    public function productData(): array
    {
        $variable = $this->input('type') === 'variable';
        $money = fn (string $field) => is_numeric($this->input($field)) ? round((float) $this->input($field), 2) : null;
        $decimal = fn (string $field) => is_numeric($this->input($field)) ? (float) $this->input($field) : null;
        $text = fn (string $field) => ($v = trim((string) $this->input($field))) !== '' ? $v : null;
        $manage = ! $variable && $this->boolean('manage_stock');
        $qty = $manage && is_numeric($this->input('stock_quantity')) ? (int) $this->input('stock_quantity') : null;
        $backorders = $this->input('backorders', 'no');
        $stockStatus = $this->input('stock_status', 'instock');
        if ($manage && $qty !== null) {
            $stockStatus = $qty > 0 ? 'instock' : ($backorders === 'no' ? 'outofstock' : 'onbackorder');
        }
        $scheduled = $this->boolean('schedule_sale') && $money('sale_price') !== null;
        $product = $this->product();
        $status = $this->input('status');
        $publishedAt = LocalTime::fromInputKeeping($this->input('published_at'), $product?->published_at);
        if (! $publishedAt && $status === 'published') {
            $publishedAt = $product?->published_at ?? now();
        }

        return [
            'name' => $this->input('name'),
            'slug' => $this->input('slug'),
            'subtitle' => $text('subtitle'),
            'description' => $this->input('description') ?: null,
            'short_description' => $this->input('short_description') ?: null,
            'status' => $status,
            'published_at' => $publishedAt,
            'type' => $this->input('type'),
            'is_featured' => $this->boolean('is_featured'),
            'regular_price' => $variable ? ($product?->regular_price) : $money('regular_price'),
            'sale_price' => $variable ? null : $money('sale_price'),
            'sale_starts_at' => $scheduled && ! $variable ? LocalTime::fromInput($this->input('sale_starts_at')) : null,
            'sale_ends_at' => $scheduled && ! $variable ? LocalTime::fromInput($this->input('sale_ends_at')) : null,
            'cost_price' => $money('cost_price'),
            'sku' => $text('sku'),
            'gtin' => $text('gtin'),
            'mpn' => $text('mpn'),
            'manage_stock' => $manage,
            'stock_quantity' => $manage ? $qty : null,
            'stock_status' => $stockStatus,
            'backorders' => $manage ? $backorders : 'no',
            'low_stock_threshold' => is_numeric($this->input('low_stock_threshold')) ? (int) $this->input('low_stock_threshold') : null,
            'sold_individually' => $this->boolean('sold_individually'),
            'weight' => $decimal('weight'),
            'length' => $decimal('length'),
            'width' => $decimal('width'),
            'height' => $decimal('height'),
            'meta_title' => $text('meta_title'),
            'meta_description' => $text('meta_description'),
            'focus_keyword' => $text('focus_keyword'),
            'google_product_category' => $text('google_product_category'),
        ] + $this->taxAndShipping();
    }

    /** Tax status/class and shipping class – only when the form sent them (quick edit and older forms leave them alone). */
    protected function taxAndShipping(): array
    {
        $data = [];
        if ($this->has('tax_status')) {
            $data['tax_status'] = in_array($this->input('tax_status'), ['taxable', 'shipping', 'none'], true) ? $this->input('tax_status') : 'taxable';
        }
        if ($this->has('tax_class')) {
            $class = \Pine\Commerce\Models\TaxClass::normalise((string) $this->input('tax_class'));
            $data['tax_class'] = $class === \Pine\Commerce\Models\TaxClass::STANDARD ? null : $class; // null = standard, like WooCommerce's ''
        }
        if ($this->has('shipping_class_id')) {
            $data['shipping_class_id'] = $this->input('shipping_class_id') ? (int) $this->input('shipping_class_id') : null;
        }

        return $data;
    }

    /** @return list<int> */
    public function categoryIds(): array
    {
        return array_values(array_unique(array_map('intval', (array) $this->input('category_ids', []))));
    }

    public function primaryCategoryId(): ?int
    {
        $ids = $this->categoryIds();
        $primary = (int) $this->input('primary_category_id');

        return in_array($primary, $ids, true) ? $primary : ($ids[0] ?? null);
    }

    /** @return list<array{path:string, alt:?string}> in display order (first = main image) */
    public function images(): array
    {
        return collect((array) $this->input('images', []))
            ->map(fn ($row) => ['path' => ltrim((string) $row['path'], '/'), 'alt' => ($alt = trim((string) ($row['alt'] ?? ''))) !== '' ? $alt : null])
            ->unique('path')->values()->all();
    }

    /** @return list<array{key:?string,label:string,value:string,description:?string}> */
    public function specs(): array
    {
        return collect((array) $this->input('specs', []))
            ->map(fn ($row) => [
                'key' => ($k = trim((string) ($row['key'] ?? ''))) !== '' ? $k : null,
                'label' => trim((string) ($row['label'] ?? '')),
                'value' => trim((string) ($row['value'] ?? '')),
                'description' => ($d = trim((string) ($row['description'] ?? ''))) !== '' ? $d : null,
            ])
            ->filter(fn ($row) => $row['label'] !== '' || $row['value'] !== '')
            ->values()->all();
    }

    /** @return list<array{attribute_id:int, values:list<int>, visible:bool, variation:bool}> in display order */
    public function attributeRows(): array
    {
        $variable = $this->input('type') === 'variable';

        return collect((array) $this->input('product_attributes', []))
            ->map(fn ($row) => [
                'attribute_id' => (int) $row['attribute_id'],
                'values' => array_values(array_unique(array_map('intval', (array) ($row['values'] ?? [])))),
                'visible' => filter_var($row['visible'] ?? true, FILTER_VALIDATE_BOOL),
                'variation' => filter_var($row['variation'] ?? false, FILTER_VALIDATE_BOOL),
            ])
            ->filter(fn ($row) => $row['values'] || ($variable && $row['variation']))
            ->values()->all();
    }

    /** @return list<array> normalised variant rows (only for products with variants) */
    public function variations(): array
    {
        if ($this->input('type') !== 'variable') {
            return [];
        }

        return collect((array) $this->input('variations', []))->values()->map(function ($row, $i) {
            $options = (array) json_decode((string) $row['options'], true);
            ksort($options);
            $qty = isset($row['stock_quantity']) && is_numeric($row['stock_quantity']) ? (int) $row['stock_quantity'] : null;
            $status = $row['stock_status'] ?? 'instock';
            if ($qty !== null) {
                $status = $qty > 0 ? 'instock' : 'outofstock';
            }

            return [
                'id' => ! empty($row['id']) ? (int) $row['id'] : null,
                'options' => array_map('strval', $options),
                'sku' => ($sku = trim((string) ($row['sku'] ?? ''))) !== '' ? $sku : null,
                'regular_price' => isset($row['regular_price']) && is_numeric($row['regular_price']) ? round((float) $row['regular_price'], 2) : null,
                'sale_price' => isset($row['sale_price']) && is_numeric($row['sale_price']) ? round((float) $row['sale_price'], 2) : null,
                'manage_stock' => $qty !== null,
                'stock_quantity' => $qty,
                'stock_status' => in_array($status, array_keys(OrderStatus::STOCK_STATUSES), true) ? $status : 'instock',
                'image' => ($image = trim((string) ($row['image'] ?? ''))) !== '' ? ltrim($image, '/') : null,
                'is_active' => filter_var($row['is_active'] ?? true, FILTER_VALIDATE_BOOL),
                'sort_order' => $i,
            ] + (array_key_exists('tax_class', $row) ? ['tax_class' => ($c = trim((string) $row['tax_class'])) !== '' && $c !== 'parent' ? \Pine\Commerce\Models\TaxClass::normalise($c) : null] : [])
              + (array_key_exists('shipping_class_id', $row) ? ['shipping_class_id' => is_numeric($row['shipping_class_id']) && (int) $row['shipping_class_id'] > 0 ? (int) $row['shipping_class_id'] : null] : []);
        })->all();
    }

    /** @return array{upsell:list<int>, cross_sell:list<int>} */
    public function related(): array
    {
        $own = $this->product()?->id;
        $ids = fn (string $field) => array_values(array_filter(array_unique(array_map('intval', (array) $this->input($field, []))), fn ($id) => $id !== $own));

        return ['upsell' => $ids('upsell_ids'), 'cross_sell' => $ids('cross_sell_ids')];
    }

    public function conditionValue(): ?string
    {
        return ($v = trim((string) $this->input('condition'))) !== '' ? $v : null;
    }

    public function brandValue(): ?string
    {
        return ($v = trim((string) $this->input('brand'))) !== '' ? $v : null;
    }

    public function wantsRedirect(): bool
    {
        return $this->boolean('redirect_old_url', true);
    }

    /** All attributes with their values (one query, memoised). */
    protected function allAttributes(): Collection
    {
        return $this->attributeCache ??= Attribute::query()->with(['values' => fn ($q) => $q->select('id', 'attribute_id', 'value', 'slug')])->get()->keyBy('id');
    }
}
