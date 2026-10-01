<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('phone')->nullable()->after('email');
            $table->string('role')->default('customer')->index()->after('phone'); // admin | manager | customer
            $table->boolean('is_active')->default(true)->after('role');
            $table->boolean('marketing_opt_in')->default(false)->after('is_active');
            $table->unsignedBigInteger('wp_id')->nullable()->index();
            $table->timestamp('last_login_at')->nullable();
            $table->string('password')->nullable()->change();
        });

        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('billing'); // billing | shipping
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('company')->nullable();
            $table->string('address_1')->nullable();
            $table->string('address_2')->nullable();
            $table->string('city')->nullable();
            $table->string('county')->nullable();
            $table->string('postcode')->nullable();
            $table->string('country', 2)->default('GB');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_default')->default(true);
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('path')->unique(); // full URL path e.g. clothing/mens-shirts
            $table->longText('description')->nullable();
            $table->longText('extra_content')->nullable(); // SEO copy shown below products
            $table->string('image')->nullable(); // path on public disk
            $table->integer('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->boolean('show_in_menu')->default(true);
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->unsignedBigInteger('wp_id')->nullable()->index();
            $table->timestamps();
            $table->index(['parent_id', 'sort_order']);
        });

        Schema::create('attributes', function (Blueprint $table) {
            $table->id();
            $table->string('name');          // e.g. Memory
            $table->string('slug')->unique(); // e.g. memory (WP pa_memory)
            $table->string('type')->default('select');
            $table->boolean('is_filterable')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_id')->constrained()->cascadeOnDelete();
            $table->string('value');
            $table->string('slug');
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('wp_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['attribute_id', 'slug']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('sku')->nullable()->index();
            $table->string('type')->default('simple'); // simple | variable
            $table->string('status')->default('published')->index(); // published | draft | private
            $table->foreignId('primary_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->text('short_description')->nullable();
            $table->longText('description')->nullable();
            $table->string('subtitle')->nullable(); // short spec line shown on cards
            $table->string('condition')->nullable(); // e.g. "Used - Like New"
            $table->string('brand')->nullable();
            $table->decimal('regular_price', 10, 2)->nullable();
            $table->decimal('sale_price', 10, 2)->nullable();
            $table->timestamp('sale_starts_at')->nullable();
            $table->timestamp('sale_ends_at')->nullable();
            $table->decimal('price', 10, 2)->nullable()->index(); // effective (lowest) current price, maintained by model
            $table->decimal('cost_price', 10, 2)->nullable();
            $table->boolean('manage_stock')->default(false);
            $table->integer('stock_quantity')->nullable();
            $table->string('stock_status')->default('instock')->index(); // instock | outofstock | onbackorder
            $table->string('backorders')->default('no'); // no | notify | yes
            $table->integer('low_stock_threshold')->nullable();
            $table->boolean('sold_individually')->default(false);
            $table->decimal('weight', 8, 3)->nullable();
            $table->decimal('length', 8, 2)->nullable();
            $table->decimal('width', 8, 2)->nullable();
            $table->decimal('height', 8, 2)->nullable();
            $table->string('tax_status')->default('taxable');
            $table->string('tax_class')->nullable();
            $table->boolean('is_featured')->default(false)->index();
            $table->integer('sort_order')->default(0);
            $table->unsignedInteger('total_sales')->default(0);
            $table->decimal('average_rating', 3, 2)->default(0);
            $table->unsignedInteger('review_count')->default(0);
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('focus_keyword')->nullable();
            $table->string('google_product_category')->nullable();
            $table->string('gtin')->nullable();
            $table->string('mpn')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('wp_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('category_product', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['category_id', 'product_id']);
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('path'); // relative to public disk, e.g. uploads/2024/05/foo.jpg
            $table->string('alt')->nullable();
            $table->integer('sort_order')->default(0); // 0 = main image
            $table->timestamps();
        });

        // Which attributes a product uses (display + variation config)
        Schema::create('product_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained()->cascadeOnDelete();
            $table->integer('position')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->boolean('is_variation')->default(false);
            $table->unique(['product_id', 'attribute_id']);
        });

        // Which attribute values a product has (used for filtering + spec display)
        Schema::create('attribute_value_product', function (Blueprint $table) {
            $table->foreignId('attribute_value_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['attribute_value_id', 'product_id']);
        });

        Schema::create('product_variations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku')->nullable();
            $table->json('options'); // {"memory": "16gb"} attribute slug => value slug
            $table->decimal('regular_price', 10, 2)->nullable();
            $table->decimal('sale_price', 10, 2)->nullable();
            $table->boolean('manage_stock')->default(false);
            $table->integer('stock_quantity')->nullable();
            $table->string('stock_status')->default('instock');
            $table->string('image')->nullable();
            $table->decimal('weight', 8, 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('wp_id')->nullable()->index();
            $table->timestamps();
        });

        // Specification key/value rows shown on product page (free-form, ordered)
        Schema::create('product_specs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->text('value');
            $table->integer('sort_order')->default(0);
        });

        Schema::create('related_products', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_id')->constrained('products')->cascadeOnDelete();
            $table->string('type')->default('upsell'); // upsell | cross_sell
            $table->primary(['product_id', 'related_id', 'type']);
        });

        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->unsignedTinyInteger('rating');
            $table->text('content')->nullable();
            $table->boolean('is_approved')->default(false);
            $table->boolean('is_verified_owner')->default(false);
            $table->timestamps();
        });

        Schema::create('stock_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('description')->nullable();
            $table->string('type')->default('percent'); // percent | fixed_cart | fixed_product
            $table->decimal('amount', 10, 2)->default(0);
            $table->boolean('free_shipping')->default(false);
            $table->decimal('minimum_spend', 10, 2)->nullable();
            $table->decimal('maximum_spend', 10, 2)->nullable();
            $table->boolean('individual_use')->default(false);
            $table->boolean('exclude_sale_items')->default(false);
            $table->json('product_ids')->nullable();
            $table->json('excluded_product_ids')->nullable();
            $table->json('category_ids')->nullable();
            $table->json('excluded_category_ids')->nullable();
            $table->json('allowed_emails')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_limit_per_user')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('shipping_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name');         // shown to customer
            $table->string('code')->unique(); // free_shipping, saturday
            $table->text('description')->nullable();
            $table->decimal('cost', 10, 2)->default(0);
            $table->decimal('min_order_amount', 10, 2)->nullable();
            $table->json('countries')->nullable(); // null = all
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->nullable(); // captured at checkout for abandoned cart
            $table->string('coupon_code')->nullable();
            $table->string('shipping_method')->nullable();
            $table->timestamp('abandoned_email_sent_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->json('options')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique(); // customer-facing order number
            $table->string('order_key', 64)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending')->index(); // pending|processing|on-hold|completed|cancelled|refunded|failed
            $table->string('currency', 3)->default('GBP');
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('discount_total', 10, 2)->default(0);
            $table->decimal('shipping_total', 10, 2)->default(0);
            $table->decimal('tax_total', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->decimal('refunded_total', 10, 2)->default(0);
            $table->string('coupon_code')->nullable();
            $table->string('shipping_method')->nullable();
            $table->string('shipping_method_title')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_method_title')->nullable();
            $table->string('transaction_id')->nullable()->index();
            $table->string('email')->index();
            $table->string('phone')->nullable();
            $table->string('billing_first_name')->nullable();
            $table->string('billing_last_name')->nullable();
            $table->string('billing_company')->nullable();
            $table->string('billing_address_1')->nullable();
            $table->string('billing_address_2')->nullable();
            $table->string('billing_city')->nullable();
            $table->string('billing_county')->nullable();
            $table->string('billing_postcode')->nullable();
            $table->string('billing_country', 2)->nullable();
            $table->string('shipping_first_name')->nullable();
            $table->string('shipping_last_name')->nullable();
            $table->string('shipping_company')->nullable();
            $table->string('shipping_address_1')->nullable();
            $table->string('shipping_address_2')->nullable();
            $table->string('shipping_city')->nullable();
            $table->string('shipping_county')->nullable();
            $table->string('shipping_postcode')->nullable();
            $table->string('shipping_country', 2)->nullable();
            $table->string('shipping_phone')->nullable();
            $table->text('customer_note')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('tracking_carrier')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('source')->nullable();        // attribution: utm_source / referrer
            $table->string('source_type')->nullable();   // organic | direct | referral | utm | admin
            $table->string('created_via')->default('checkout');
            $table->json('meta')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('wp_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index('created_at');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('subtotal', 10, 2); // before discounts
            $table->decimal('total', 10, 2);    // after discounts
            $table->decimal('tax', 10, 2)->default(0);
            $table->unsignedInteger('refunded_quantity')->default(0);
            $table->json('options')->nullable(); // e.g. {"Memory": "16GB"}
            $table->timestamps();
        });

        Schema::create('order_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note');
            $table->boolean('is_customer_note')->default(false); // emailed to customer
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->text('reason')->nullable();
            $table->json('items')->nullable(); // [{order_item_id, quantity, amount}]
            $table->boolean('restock')->default(false);
            $table->string('gateway_refund_id')->nullable();
            $table->string('status')->default('completed');
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('gateway');
            $table->string('reference')->nullable()->index();
            $table->decimal('amount', 10, 2);
            $table->string('status'); // pending | succeeded | failed | refunded
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('pages')->nullOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->string('path')->unique(); // full URL path, '' for home
            $table->string('template')->default('default'); // default | home | contact | blog | faq | grading | full-width
            $table->longText('content')->nullable();
            $table->json('blocks')->nullable(); // structured sections for templated pages (home, etc.)
            $table->string('status')->default('published');
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->boolean('noindex')->default(false);
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('wp_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('post_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('excerpt')->nullable();
            $table->longText('content')->nullable();
            $table->string('featured_image')->nullable();
            $table->string('status')->default('published');
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->unsignedBigInteger('wp_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('location')->unique(); // main | mobile | footer_shop | footer_company | footer_legal | top_bar
            $table->timestamps();
        });

        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('menu_items')->cascadeOnDelete();
            $table->string('label');
            $table->string('url')->nullable(); // null = column heading in mega menu
            $table->string('badge')->nullable(); // e.g. "popular"
            $table->string('icon')->nullable();
            $table->string('css_class')->nullable();
            $table->boolean('open_in_new_tab')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('group')->default('general');
            $table->string('key')->unique();
            $table->longText('value')->nullable();
            $table->timestamps();
        });

        Schema::create('redirects', function (Blueprint $table) {
            $table->id();
            $table->string('from_path')->unique();
            $table->string('to_url');
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('path')->unique(); // relative to public disk
            $table->string('filename');
            $table->string('title')->nullable();
            $table->string('alt')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('folder')->nullable();
            $table->unsignedBigInteger('wp_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('form_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('form')->default('contact');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('subject')->nullable();
            $table->text('message')->nullable();
            $table->json('data')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('newsletter_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('source')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('wishlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'product_id']);
        });
    }

    public function down(): void
    {
        foreach ([
            'wishlist_items', 'newsletter_subscribers', 'form_submissions', 'media', 'redirects', 'settings',
            'menu_items', 'menus', 'posts', 'post_categories', 'pages', 'payments', 'refunds', 'order_notes',
            'order_items', 'orders', 'cart_items', 'carts', 'shipping_methods', 'coupons', 'stock_notifications',
            'product_reviews', 'related_products', 'product_specs', 'product_variations', 'attribute_value_product',
            'product_attributes', 'product_images', 'category_product', 'products', 'attribute_values', 'attributes',
            'categories', 'addresses',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
