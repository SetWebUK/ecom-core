<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1.1 – tax classes and rates, shipping zones / classes / method types, and per-line + per-rate tax on orders.
 *
 * Additive only: new tables, and new nullable (or defaulted) columns, so every existing row stays valid and code that
 * does not know the new columns keeps working. Data steps keep today's totals exactly:
 *
 *  - a store with the old single "tax.rate" setting (> 0) gets one equivalent rate row (standard class, every country,
 *    also applied to shipping) and "tax.prices_include_tax" = false, which is how that rate was always applied;
 *    a store without it (0 %) gets no rate rows – no tax, as before;
 *  - existing shipping methods move into one zone covering the countries they were limited to (or "Everywhere"), keep
 *    their cost, minimum order and country list; a free method (code "free…", cost 0) becomes the "free shipping" type
 *    with the same rule as before (available from the minimum order amount, or with a free-shipping coupon).
 *
 * New installs get sensible UK/EU rates and a UK zone from `commerce:install`, not from here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tax_classes')) {
            Schema::create('tax_classes', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('slug', 100)->unique();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
            $now = now();
            DB::table('tax_classes')->insert([
                ['name' => 'Standard rate', 'slug' => 'standard', 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Reduced rate', 'slug' => 'reduced-rate', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Zero rate', 'slug' => 'zero-rate', 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }

        if (! Schema::hasTable('tax_rates')) {
            Schema::create('tax_rates', function (Blueprint $table) {
                $table->id();
                $table->string('tax_class', 100)->default('standard');
                $table->string('country', 2)->default('');       // '' = every country
                $table->string('state', 100)->default('');       // '' = every state/county/region
                $table->text('postcodes')->nullable();            // one pattern per line: SW1A 1AA, BT*, HS1-HS9, 10000...19999
                $table->text('cities')->nullable();               // one city per line
                $table->decimal('rate', 9, 4)->default(0);        // percent
                $table->string('name', 120)->default('');         // label on receipts, e.g. "VAT"
                $table->unsignedSmallInteger('priority')->default(1);
                $table->boolean('compound')->default(false);
                $table->boolean('shipping')->default(true);       // also charged on shipping
                $table->unsignedInteger('sort_order')->default(0);
                $table->unsignedBigInteger('wp_id')->nullable()->index(); // woocommerce_tax_rates.tax_rate_id (importer)
                $table->timestamps();
                $table->index(['tax_class', 'country']);
            });
        }

        if (! Schema::hasTable('shipping_classes')) {
            Schema::create('shipping_classes', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('slug', 100)->unique();
                $table->string('description', 500)->nullable();
                $table->unsignedBigInteger('wp_id')->nullable()->index(); // product_shipping_class term id (importer)
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('shipping_zones')) {
            Schema::create('shipping_zones', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->json('regions')->nullable();              // ["GB", "US:CA"]; empty = everywhere
                $table->text('postcodes')->nullable();            // one pattern per line; empty = any postcode
                $table->unsignedInteger('sort_order')->default(0);
                $table->unsignedBigInteger('wp_id')->nullable()->index(); // woocommerce_shipping_zones.zone_id + 1 (importer; 1 = rest of the world)
                $table->timestamps();
            });
        }

        Schema::table('shipping_methods', function (Blueprint $table) {
            if (! Schema::hasColumn('shipping_methods', 'shipping_zone_id')) {
                $table->unsignedBigInteger('shipping_zone_id')->nullable()->index();
            }
            if (! Schema::hasColumn('shipping_methods', 'type')) {
                $table->string('type', 30)->default('flat_rate');
            }
            if (! Schema::hasColumn('shipping_methods', 'tax_status')) {
                $table->string('tax_status', 20)->default('taxable');
            }
            if (! Schema::hasColumn('shipping_methods', 'settings')) {
                $table->json('settings')->nullable();
            }
        });

        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'shipping_class_id')) {
                $table->unsignedBigInteger('shipping_class_id')->nullable()->index();
            }
        });

        Schema::table('product_variations', function (Blueprint $table) {
            if (! Schema::hasColumn('product_variations', 'tax_class')) {
                $table->string('tax_class', 100)->nullable();     // null = same as the product
            }
            if (! Schema::hasColumn('product_variations', 'shipping_class_id')) {
                $table->unsignedBigInteger('shipping_class_id')->nullable(); // null = same as the product
            }
        });

        Schema::table('carts', function (Blueprint $table) {
            if (! Schema::hasColumn('carts', 'destination')) {
                $table->json('destination')->nullable();          // address typed at checkout (zones + tax location)
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'shipping_tax')) {
                $table->decimal('shipping_tax', 10, 2)->default(0);
            }
            if (! Schema::hasColumn('orders', 'prices_include_tax')) {
                $table->boolean('prices_include_tax')->nullable();
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'tax_class')) {
                $table->string('tax_class', 100)->nullable();
            }
            if (! Schema::hasColumn('order_items', 'subtotal_tax')) {
                $table->decimal('subtotal_tax', 10, 2)->default(0);
            }
            if (! Schema::hasColumn('order_items', 'taxes')) {
                $table->json('taxes')->nullable();                // {tax_rate_id: amount}
            }
        });

        if (! Schema::hasTable('order_tax_lines')) {
            Schema::create('order_tax_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('tax_rate_id')->nullable()->index();
                $table->string('label', 120);
                $table->decimal('rate', 9, 4)->default(0);
                $table->boolean('compound')->default(false);
                $table->decimal('tax_total', 10, 2)->default(0);
                $table->decimal('shipping_tax_total', 10, 2)->default(0);
                $table->timestamps();
            });
        }

        $this->carryOverTaxRate();
        $this->carryOverShippingMethods();
    }

    /** The old single rate (Settings › Checkout › VAT) → one equivalent rate row, applied exactly as before. */
    protected function carryOverTaxRate(): void
    {
        if (! Schema::hasTable('settings') || DB::table('tax_rates')->exists()) {
            return;
        }
        $settings = DB::table('settings')->whereIn('key', ['tax.rate', 'tax.label', 'tax.prices_include_tax'])->pluck('value', 'key');
        $rate = $settings['tax.rate'] ?? null;
        if (! is_numeric($rate) || (float) $rate <= 0) {
            return;
        }
        $now = now();
        DB::table('tax_rates')->insert([
            'tax_class' => 'standard', 'country' => '', 'state' => '', 'rate' => min(100, (float) $rate),
            'name' => trim((string) ($settings['tax.label'] ?? '')) ?: 'VAT', 'priority' => 1, 'compound' => false,
            'shipping' => true, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        // the old rate was always added on top of the entered prices
        $values = ['tax.prices_include_tax' => 'false', 'tax.display_shop' => 'excl', 'tax.display_cart' => 'excl'];
        foreach ($values as $key => $value) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert(['group' => 'tax', 'key' => $key, 'value' => $value, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        Cache::forget('settings.all');
    }

    /** Existing delivery options → one zone for the countries they cover, behaving exactly as before. */
    protected function carryOverShippingMethods(): void
    {
        if (DB::table('shipping_zones')->exists()) {
            return;
        }
        $methods = DB::table('shipping_methods')->orderBy('sort_order')->orderBy('id')->get();
        if ($methods->isEmpty()) {
            return;
        }
        $countries = [];
        $everywhere = false;
        foreach ($methods as $method) {
            $list = array_values(array_filter((array) json_decode((string) $method->countries, true), 'is_string'));
            $list ? array_push($countries, ...$list) : $everywhere = true;
        }
        $countries = $everywhere ? [] : array_values(array_unique($countries));
        $name = match (true) {
            ! $countries => 'Everywhere',
            $countries === ['GB'] => 'United Kingdom (UK)',
            default => 'Delivery zone',
        };
        $now = now();
        $zoneId = DB::table('shipping_zones')->insertGetId([
            'name' => $name, 'regions' => $countries ? json_encode($countries) : null, 'postcodes' => null,
            'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ($methods as $method) {
            $free = str_starts_with((string) $method->code, 'free') && (float) $method->cost == 0.0;
            DB::table('shipping_methods')->where('id', $method->id)->update([
                'shipping_zone_id' => $zoneId,
                'type' => $free ? 'free_shipping' : 'flat_rate',
                // before v1.1 a free method stayed available below its minimum order when a free-shipping coupon was used
                'settings' => json_encode($free
                    ? ['requires' => $method->min_order_amount !== null && (float) $method->min_order_amount > 0 ? 'either' : '']
                    : ['calculation' => 'order']),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_tax_lines');
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn(['tax_class', 'subtotal_tax', 'taxes']));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['shipping_tax', 'prices_include_tax']));
        Schema::table('carts', fn (Blueprint $t) => $t->dropColumn('destination'));
        Schema::table('product_variations', fn (Blueprint $t) => $t->dropColumn(['tax_class', 'shipping_class_id']));
        Schema::table('products', function (Blueprint $t) {
            $t->dropIndex(['shipping_class_id']);
            $t->dropColumn('shipping_class_id');
        });
        Schema::table('shipping_methods', function (Blueprint $t) {
            $t->dropIndex(['shipping_zone_id']);
            $t->dropColumn(['shipping_zone_id', 'type', 'tax_status', 'settings']);
        });
        Schema::dropIfExists('shipping_zones');
        Schema::dropIfExists('shipping_classes');
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('tax_classes');
    }
};
