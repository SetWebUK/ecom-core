{{-- Product editor: main column (inside x-data="productForm(...)"). --}}
@php
    use Pine\Commerce\Services\Admin\LocalTime;
    use Pine\Commerce\Services\Admin\OrderStatus;
@endphp

{{-- Title, URL, descriptions --}}
<x-admin.card>
    <div class="stack-fields">
        <x-admin.input name="name" label="Title" required maxlength="255" x-model="name" placeholder="e.g. Classic Linen Shirt – Navy, Slim Fit, Size M" :autofocus="! $product->exists" />

        <x-admin.field label="URL handle" for="f-slug" error="slug" required>
            <div @class(['input-group', 'is-invalid' => $errors->has('slug')])>
                <span class="input-group__addon mono text-xs" x-text="'/' + primaryPath + '/'">/{{ $product->primaryCategory?->path ?? 'product' }}/</span>
                <input type="text" name="slug" id="f-slug" class="input input--mono" x-model="slug" @input="slugInput($event)" @blur="slugBlur()"
                       maxlength="190" autocomplete="off" spellcheck="false" required value="{{ old('slug', $product->slug) }}"
                       @error('slug') aria-invalid="true" aria-describedby="f-slug-error" @enderror>
            </div>
            <p class="url-preview"><x-admin.icon name="link" /><a :href="urlPreview" target="_blank" rel="noopener" x-text="urlPreview" class="break">{{ $product->exists ? $product->url : '' }}</a></p>
            <div x-show="slugChanged" x-cloak class="mt-2">
                <input type="hidden" name="redirect_old_url" value="0">
                <label class="check" for="f-redirect_old_url">
                    <input type="checkbox" class="checkbox" id="f-redirect_old_url" name="redirect_old_url" value="1" @checked(old('redirect_old_url', '1') === '1')>
                    <span class="check__text">
                        <span class="check__label">Redirect the old address to the new one</span>
                        <span class="check__help">Changing the handle changes the product’s web address. Keep this ticked so links and Google results still work.</span>
                    </span>
                </label>
            </div>
            <x-slot:helpSlot>Part of the web address – lower-case letters, numbers and dashes. The path before it comes from the main category.</x-slot:helpSlot>
        </x-admin.field>

        <x-admin.input name="subtitle" label="Subtitle" optional maxlength="255" :value="$product->subtitle" counter="120"
                       help="Short spec line shown under the name on product cards, e.g. “Intel i5 · 8GB RAM · 256GB SSD · 14-inch”." />

        <x-admin.rich-editor name="short_description" label="Short description" :value="$product->short_description" height="200"
                             help="Shown next to the photos, above the Add to basket button. Keep it to a few lines." />

        <x-admin.rich-editor name="description" label="Description" :value="$product->description" height="480"
                             help="The full description under the product. Imported HTML (tables, lists, styling) is kept exactly as it is – use “Source code” to edit it directly." />
    </div>
</x-admin.card>

{{-- Media --}}
@include('commerce::admin.products.partials.media')

{{-- Pricing --}}
<x-admin.card title="Pricing">
    <div x-show="type === 'variable'" x-cloak>
        <x-admin.callout type="neutral" icon="squares-2x2">This product has variants – set a price for each variant in the Variants section. The shop shows the lowest price as “From £…”.</x-admin.callout>
    </div>
    <div x-show="type !== 'variable'">
        <div class="form-grid">
            <x-admin.money name="regular_price" label="Price" :value="$product->regular_price" x-model="regular" help="The normal selling price, including VAT." />
            <x-admin.field label="Sale price" for="f-sale_price" error="sale_price" optional>
                <div @class(['input-group', 'is-invalid' => $errors->has('sale_price')])>
                    <span class="input-group__addon">£</span>
                    <input type="text" inputmode="decimal" name="sale_price" id="f-sale_price" class="input input--num" x-model="sale" placeholder="Not on sale" autocomplete="off"
                           value="{{ old('sale_price', $product->sale_price !== null ? number_format((float) $product->sale_price, 2, '.', '') : '') }}">
                </div>
                <x-slot:helpSlot><span x-text="saleNote || 'Leave empty when the product isn’t on sale.'">Leave empty when the product isn’t on sale.</span></x-slot:helpSlot>
            </x-admin.field>
        </div>
        <div class="mt-4" x-show="sale !== ''">
            <x-admin.toggle name="schedule_sale" label="Schedule the sale" help="Start and/or end the sale automatically (UK time)." :checked="(bool) ($product->sale_starts_at || $product->sale_ends_at)" x-model="schedule" />
            <div class="form-grid mt-3" x-show="schedule" x-cloak>
                <x-admin.datetime name="sale_starts_at" label="Sale starts" optional :value="$product->sale_starts_at" help="Blank = straight away." />
                <x-admin.datetime name="sale_ends_at" label="Sale ends" optional :value="$product->sale_ends_at" help="Blank = until you remove the sale price." />
            </div>
        </div>
        <div class="subsection">
            <div class="form-grid">
                <x-admin.money name="cost_price" label="Cost per item" optional :value="$product->cost_price" x-model="cost" help="What you paid. Customers never see this." />
            </div>
            <dl class="money-summary" aria-live="polite">
                <div><dt>Selling for</dt><dd x-text="currentPrice === null ? '—' : Admin.money(currentPrice)">—</dd></div>
                <div><dt>Profit</dt><dd :class="{ 'text-danger': profit !== null && profit < 0 }" x-text="profit === null ? '—' : Admin.money(profit)">—</dd></div>
                <div><dt>Margin</dt><dd :class="{ 'text-danger': margin !== null && margin < 0 }" x-text="margin === null ? '—' : margin.toFixed(1) + '%'">—</dd></div>
            </dl>
        </div>
    </div>
</x-admin.card>

{{-- Inventory --}}
<x-admin.card title="Inventory">
    <div class="form-grid form-grid--3">
        <x-admin.input name="sku" label="SKU" optional maxlength="100" :value="$product->sku" class="input--mono" help="Your stock code." />
        <x-admin.input name="gtin" label="Barcode (GTIN / EAN)" optional maxlength="50" :value="$product->gtin" class="input--mono" />
        <x-admin.input name="mpn" label="MPN" optional maxlength="100" :value="$product->mpn" class="input--mono" help="Manufacturer part number." />
    </div>

    <div class="subsection" x-show="type === 'variable'" x-cloak>
        <p class="text-sm text-muted">Stock is tracked per variant – see the Variants section.</p>
    </div>
    <div class="subsection" x-show="type !== 'variable'">
        <x-admin.toggle name="manage_stock" label="Track quantity" help="The shop counts stock down as orders come in and shows “Out of stock” at 0." :checked="(bool) $product->manage_stock" x-model="manageStock" />
        <div class="form-grid mt-4" x-show="manageStock">
            <x-admin.input type="number" name="stock_quantity" label="Quantity in stock" step="1" inputmode="numeric" :value="$product->stock_quantity" class="input--num" />
            <x-admin.input type="number" name="low_stock_threshold" label="Low stock warning at" optional step="1" min="0" inputmode="numeric" :value="$product->low_stock_threshold" class="input--num"
                           :help="'Blank = the store default ('.Pine\Commerce\Services\Admin\CatalogueTools::lowStockThreshold().').'" />
            <x-admin.select name="backorders" label="When it’s out of stock" :value="$product->backorders ?: 'no'" :options="['no' => 'Stop selling', 'notify' => 'Keep selling (tell the customer it’s on backorder)', 'yes' => 'Keep selling']" />
        </div>
        <div class="form-grid mt-4" x-show="!manageStock" x-cloak>
            <x-admin.select name="stock_status" label="Stock status" :value="$product->stock_status ?: 'instock'" :options="OrderStatus::STOCK_STATUSES" />
        </div>
        <div class="mt-4">
            <x-admin.checkbox name="sold_individually" label="Limit to one per order" help="Customers can only buy one of this item in each order." :checked="(bool) $product->sold_individually" />
        </div>
    </div>
</x-admin.card>

{{-- Variants --}}
@include('commerce::admin.products.partials.variants')

{{-- Specifications --}}
@include('commerce::admin.products.partials.specs')

{{-- Attributes --}}
@include('commerce::admin.products.partials.attributes')

{{-- Tax & shipping --}}
<x-admin.card title="Tax & shipping">
    <div class="form-grid">
        <x-admin.select name="tax_status" label="Tax status" :value="old('tax_status', $product->tax_status ?: 'taxable')"
                        :options="['taxable' => 'Taxable', 'shipping' => 'Only its shipping is taxable', 'none' => 'Not taxable']" help="e.g. “Not taxable” for second-hand goods sold under the margin scheme." />
        <x-admin.select name="tax_class" label="Tax class" :value="old('tax_class', \Pine\Commerce\Models\TaxClass::normalise($product->tax_class))"
                        :options="\Pine\Commerce\Models\TaxClass::options()" help="Rates per class: Settings › Tax." />
    </div>
    <div class="form-grid mt-4">
        <x-admin.input type="number" name="weight" label="Weight" optional step="0.001" min="0" suffix="kg" inputmode="decimal" :value="$product->weight !== null ? rtrim(rtrim(number_format((float) $product->weight, 3, '.', ''), '0'), '.') : null" />
        <x-admin.select name="shipping_class_id" label="Shipping class" :value="old('shipping_class_id', $product->shipping_class_id)" :options="\Pine\Commerce\Models\ShippingClass::options()"
                        placeholder="No shipping class" help="Classes: Settings › Shipping." />
    </div>
    <div class="form-grid form-grid--3 mt-4">
        <x-admin.input type="number" name="length" label="Length" optional step="0.01" min="0" suffix="cm" inputmode="decimal" :value="$product->length !== null ? (float) $product->length : null" />
        <x-admin.input type="number" name="width" label="Width" optional step="0.01" min="0" suffix="cm" inputmode="decimal" :value="$product->width !== null ? (float) $product->width : null" />
        <x-admin.input type="number" name="height" label="Height" optional step="0.01" min="0" suffix="cm" inputmode="decimal" :value="$product->height !== null ? (float) $product->height : null" />
    </div>
</x-admin.card>

{{-- Related products --}}
<x-admin.card title="Related products" subtitle="Hand-picked suggestions. Leave empty to let the shop choose similar products.">
    <div class="stack-fields">
        <x-admin.product-picker name="upsell_ids" label="You may also like (on the product page)" :value="old('upsell_ids', $product->related->filter(fn ($p) => $p->pivot->type === 'upsell')->pluck('id')->all())"
                                help="Better or alternative products – e.g. the same model with more memory." />
        <x-admin.product-picker name="cross_sell_ids" label="Frequently bought together (in the basket)" :value="old('cross_sell_ids', $product->related->filter(fn ($p) => $p->pivot->type === 'cross_sell')->pluck('id')->all())"
                                help="Add-ons like chargers, bags or software." />
    </div>
</x-admin.card>

{{-- SEO --}}
<x-admin.card title="Search engine listing" subtitle="How the product appears in Google.">
    <div class="stack-fields">
        <x-admin.seo-fields :title="$product->meta_title" :description="$product->meta_description" title-source="#f-name" description-source="#f-short_description"
                            :fallback-title="$product->name" :fallback-description="$product->short_description" :url="$product->exists ? $product->url : null"
                            slug-source="#f-slug" :base-url="rtrim(url('/'), '/').'/'.($product->primaryCategory?->path ?? 'product')" />
        <div class="form-grid">
            <x-admin.input name="focus_keyword" label="Focus keyword" optional maxlength="255" :value="$product->focus_keyword" help="The search phrase this page should rank for, e.g. “mens linen shirt”." />
            <x-admin.input name="google_product_category" label="Google product category" optional maxlength="255" :value="$product->google_product_category"
                           help="Google Shopping category ID or path. Blank = the store default (Settings › SEO)." />
        </div>
    </div>
</x-admin.card>
