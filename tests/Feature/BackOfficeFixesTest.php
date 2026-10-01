<?php

namespace Pine\Commerce\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Pine\Commerce\Import\Adapters\RankMath;
use Pine\Commerce\Import\Data\WpPost;
use Pine\Commerce\Import\Steps\VariationsStep;
use Pine\Commerce\Models\Coupon;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ProductVariation;
use Pine\Commerce\Services\Admin\Catalogue\ProductSaver;
use Pine\Commerce\Services\Cart;
use Pine\Commerce\Services\Checkout\CartLine;
use Pine\Commerce\Services\Checkout\CouponEngine;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/**
 * Back-office / checkout / importer bug fixes of pine/commerce 1.1 on a fresh neutral store (in-memory SQLite):
 * specific coupon removal reasons, password-reset tokens of deleted customers, variation order on import and save,
 * bank-transfer payments confirmed by staff, and the importer's breadcrumb category.
 */
class BackOfficeFixesTest extends TestCase
{
    use InstallsNeutralStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNeutralStore();
    }

    protected function tearDown(): void
    {
        $this->tearDownNeutralStore();
        parent::tearDown();
    }

    public function test_a_coupon_over_its_per_customer_limit_names_the_reason(): void
    {
        [$product, , $order] = $this->catalogue();
        $coupon = Coupon::create(['code' => 'WELCOME', 'type' => 'percent', 'amount' => 10, 'usage_limit_per_user' => 1, 'is_active' => true]);
        $order->forceFill(['coupon_code' => 'WELCOME'])->save(); // Ada already used it
        $lines = collect([new CartLine(null, $product->fresh(), null, 1, 30.0)]);

        $engine = app(CouponEngine::class);
        $this->assertNull($engine->validate($coupon, $lines, 'someone@example.test'));
        $reason = $engine->validate($coupon, $lines, 'ADA@example.test');
        $this->assertSame('Sorry, coupon "WELCOME" can only be used once per customer and you have already used it.', $reason);
        $coupon->forceFill(['usage_limit_per_user' => 3])->save();
        Order::create(['email' => 'ada@example.test', 'status' => 'completed', 'total' => 1, 'coupon_code' => 'welcome'])->save();
        Order::create(['email' => 'ada@example.test', 'status' => 'processing', 'total' => 1, 'coupon_code' => 'x, welcome'])->save();
        $this->assertSame('Sorry, coupon "WELCOME" can only be used 3 times per customer and you have already used it 3 times.',
            $engine->validate($coupon->fresh(), $lines, 'ada@example.test'));

        // the basket notice carries that reason instead of a generic "invalid"
        $this->assertSame('Sorry, coupon "WELCOME" can only be used once per customer and you have already used it. It has now been removed from your order.',
            Cart::removedNotice($reason));
        $this->assertSame('The minimum spend for coupon "X" is £50.00. It has now been removed from your order.',
            Cart::removedNotice('The minimum spend for coupon "X" is £50.00.'));
        $this->assertSame('Sorry, it seems the coupon "X" is not yours - it has now been removed from your order.',
            Cart::removedNotice('Sorry, it seems the coupon "X" is not yours - it has now been removed from your order.'));
    }

    public function test_the_basket_drops_a_coupon_with_the_specific_reason_once_the_email_is_known(): void
    {
        [$product, , $order] = $this->catalogue();
        Coupon::create(['code' => 'ONCE', 'type' => 'percent', 'amount' => 10, 'usage_limit_per_user' => 1, 'is_active' => true]);
        $order->forceFill(['coupon_code' => 'ONCE'])->save();

        $this->post(route('cart.add'), ['product_id' => $product->id, 'quantity' => 1], ['Accept' => 'application/json'])->assertOk();
        $cart = app(Cart::class);
        $this->assertTrue($cart->applyCoupon('ONCE')['ok']); // no email yet
        $cart->setEmail('ada@example.test');
        Cart::flush();
        $cart = app(Cart::class);
        $this->assertSame([], $cart->coupons());
        $this->assertContains('Sorry, coupon "ONCE" can only be used once per customer and you have already used it. It has now been removed from your order.',
            $cart->pullNotices());
    }

    public function test_deleting_a_customer_removes_their_password_reset_token(): void
    {
        $customer = \Pine\Commerce\Commerce::userModel()::forceCreate(['name' => 'Tim', 'email' => 'tim@example.test',
            'password' => Hash::make('Password12345'), 'role' => 'customer', 'is_active' => true]);
        DB::table('password_reset_tokens')->insert(['email' => 'tim@example.test', 'token' => Hash::make('t'), 'created_at' => now()]);
        DB::table('password_reset_tokens')->insert(['email' => 'other@example.test', 'token' => Hash::make('t'), 'created_at' => now()]);

        $this->actingAs($this->neutralAdmin())->delete(route('admin.customers.destroy', $customer))->assertSessionHas('success');

        $this->assertDatabaseMissing('users', ['email' => 'tim@example.test']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'tim@example.test']);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'other@example.test']);
    }

    public function test_imported_variations_are_numbered_by_menu_order_then_post_id(): void
    {
        $rows = [
            ['wp_id' => 12, 'product_id' => 1, 'sort_order' => 0],
            ['wp_id' => 10, 'product_id' => 1, 'sort_order' => 0],
            ['wp_id' => 30, 'product_id' => 2, 'sort_order' => 5],
            ['wp_id' => 11, 'product_id' => 1, 'sort_order' => 0],
            ['wp_id' => 31, 'product_id' => 2, 'sort_order' => 1],
            ['wp_id' => 9, 'product_id' => 1, 'sort_order' => 3],
        ];
        $out = VariationsStep::normaliseSortOrder($rows);
        $order = fn (int $product) => collect($out)->where('product_id', $product)->sortBy('sort_order')->pluck('wp_id')->all();
        $this->assertSame([10, 11, 12, 9], $order(1));
        $this->assertSame([31, 30], $order(2));
        $this->assertSame([0, 1, 2, 3], collect($out)->where('product_id', 1)->pluck('sort_order')->sort()->values()->all());
    }

    public function test_saving_a_product_keeps_the_variation_order_unless_it_was_changed(): void
    {
        $product = Product::forceCreate(['name' => 'Cable', 'slug' => 'cable', 'type' => 'variable', 'status' => 'published', 'stock_status' => 'instock']);
        $make = fn (string $sku) => ProductVariation::forceCreate(['product_id' => $product->id, 'sku' => $sku, 'options' => ['length' => $sku],
            'regular_price' => 5, 'stock_status' => 'instock', 'is_active' => true, 'sort_order' => 0]);
        [$a, $b, $c] = [$make('1m'), $make('2m'), $make('3m')]; // imported: all 0
        $row = fn (?ProductVariation $v, int $position, string $sku = 'new') => ['id' => $v?->id, 'options' => ['length' => $v?->sku ?? $sku], 'sku' => $v?->sku ?? $sku,
            'regular_price' => 5.0, 'sale_price' => null, 'manage_stock' => false, 'stock_quantity' => null, 'stock_status' => 'instock',
            'image' => null, 'is_active' => true, 'sort_order' => $position];
        $stored = fn () => $product->variations()->get()->map(fn ($v) => $v->sku.':'.$v->sort_order)->all();

        // saved unchanged (form order = stored order): nothing renumbered
        ProductSaver::syncVariations($product, [$row($a, 0), $row($b, 1), $row($c, 2)]);
        $this->assertSame(['1m:0', '2m:0', '3m:0'], $stored());

        // a variant added at the end: appended after the stored positions
        ProductSaver::syncVariations($product, [$row($a, 0), $row($b, 1), $row($c, 2), $row(null, 3, '4m')]);
        $this->assertSame(['1m:0', '2m:0', '3m:0', '4m:1'], $stored());

        // the user moved one: the form's positions are saved
        $d = $product->variations()->where('sku', '4m')->first();
        ProductSaver::syncVariations($product, [$row($c, 0), $row($a, 1), $row($b, 2), $row($d, 3)]);
        $this->assertSame(['3m:0', '1m:1', '2m:2', '4m:3'], $stored());

        // a removed variant does not count as a reorder
        ProductSaver::syncVariations($product, [$row($c, 0), $row($b, 1), $row($d, 2)]);
        $this->assertSame(['3m:0', '2m:2', '4m:3'], $stored());
    }

    public function test_marking_a_bank_transfer_order_paid_confirms_its_pending_payment(): void
    {
        [, , $order] = $this->catalogue();
        $order->forceFill(['status' => 'on-hold', 'payment_method' => 'bacs', 'paid_at' => null])->save();
        $payment = $order->payments()->create(['gateway' => 'bacs', 'reference' => $order->number, 'amount' => $order->total, 'status' => 'pending']);
        $other = $order->payments()->create(['gateway' => 'stripe', 'reference' => 'pi_1', 'amount' => $order->total, 'status' => 'pending']);

        $this->actingAs($this->neutralAdmin())
            ->put(route('admin.orders.status', $order), ['status' => 'processing'])->assertSessionHas('success');

        $payment->refresh();
        $order->refresh();
        $this->assertSame('succeeded', $payment->status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame($order->paid_at->toIso8601String(), $payment->payload['completed_at']);
        $this->assertNotEmpty($payment->payload['confirmed_by']);
        $this->assertSame('pending', $other->fresh()->status, 'only the order\'s own payment method');
    }

    public function test_fulfilling_an_unpaid_bank_transfer_order_confirms_the_payment_too(): void
    {
        [, , $order] = $this->catalogue();
        $order->forceFill(['status' => 'on-hold', 'payment_method' => 'bacs'])->save();
        $payment = $order->payments()->create(['gateway' => 'bacs', 'reference' => $order->number, 'amount' => $order->total, 'status' => 'pending']);

        $this->actingAs($this->neutralAdmin())
            ->post(route('admin.orders.fulfil', $order), ['tracking_carrier' => '', 'tracking_number' => ''])->assertRedirect();

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('succeeded', $payment->fresh()->status);
    }

    public function test_rank_math_breadcrumb_term_is_the_primary_else_the_first_category_by_name(): void
    {
        $adapter = new RankMath;
        $post = WpPost::fromRow((object) ['ID' => 5, 'post_type' => 'product', 'post_name' => 'x', 'post_title' => 'X', 'post_status' => 'publish',
            'post_parent' => 0, 'post_date' => '2026-01-01 00:00:00', 'post_date_gmt' => '2026-01-01 00:00:00', 'post_modified' => '2026-01-01 00:00:00',
            'post_modified_gmt' => '2026-01-01 00:00:00', 'post_content' => '', 'post_excerpt' => '', 'menu_order' => 0, 'post_author' => 1, 'comment_status' => 'open', 'guid' => '', 'post_mime_type' => '']);
        $terms = [
            ['id' => 212, 'name' => 'Organic Cotton Shirts', 'parent' => 141],
            ['id' => 144, 'name' => 'Linen Shirts', 'parent' => 141],
            ['id' => 174, 'name' => 'Hemp Shirts', 'parent' => 141],
        ];
        $this->assertSame(174, $adapter->breadcrumbTermId($post, [], 'product_cat', $terms)); // no primary: first by name
        $this->assertSame(174, $adapter->breadcrumbTermId($post, ['rank_math_primary_product_cat' => ''], 'product_cat', $terms));
        $this->assertSame(174, $adapter->breadcrumbTermId($post, ['rank_math_primary_product_cat' => '999'], 'product_cat', $terms)); // not one of its terms
        $this->assertSame(212, $adapter->breadcrumbTermId($post, ['rank_math_primary_product_cat' => '212'], 'product_cat', $terms));
        $this->assertNull($adapter->breadcrumbTermId($post, [], 'product_cat', []));
    }
}
