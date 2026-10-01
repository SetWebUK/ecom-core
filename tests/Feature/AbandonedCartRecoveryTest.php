<?php

namespace Pine\Commerce\Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Pine\Commerce\Commerce;
use Pine\Commerce\Events\OrderPlaced;
use Pine\Commerce\Mail\AbandonedCartReminder;
use Pine\Commerce\Models\Cart;
use Pine\Commerce\Models\CartRecoveryEmail;
use Pine\Commerce\Models\Coupon;
use Pine\Commerce\Models\EmailUnsubscribe;
use Pine\Commerce\Models\NewsletterSubscriber;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\Setting;
use Pine\Commerce\Scheduling\Scheduler;
use Pine\Commerce\Services\Recovery\AbandonedCartRecovery;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/**
 * Abandoned-cart recovery emails: off by default, consent, the configurable sequence, coupons, stop rules, the email
 * itself, the signed restore / unsubscribe links, recovered revenue and the back-office screens.
 * Fresh package install on in-memory SQLite only.
 */
class AbandonedCartRecoveryTest extends TestCase
{
    use InstallsNeutralStore;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNeutralStore();
        [$this->product] = $this->catalogue();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        $this->tearDownNeutralStore();
        parent::tearDown();
    }

    protected function enable(string $consent = 'all'): void
    {
        Setting::set('abandoned_carts.emails_enabled', true);
        Setting::set('abandoned_carts.consent', $consent);
    }

    protected function basket(string $email = 'shopper@example.test', int $hoursIdle = 2, int $qty = 2, array $attrs = []): Cart
    {
        $cart = Cart::create(['token' => Str::random(40), 'email' => $email] + $attrs);
        $cart->items()->create(['product_id' => $this->product->id, 'quantity' => $qty]);
        Cart::query()->whereKey($cart->id)->toBase()->update(['created_at' => now()->subHours($hoursIdle + 1), 'updated_at' => now()->subHours($hoursIdle)]);

        return $cart->fresh();
    }

    protected function send(): array
    {
        return app(AbandonedCartRecovery::class)->sendDue();
    }

    // ------------------------------------------------------------------ switches + consent

    public function test_off_by_default_everywhere(): void
    {
        $this->basket();
        $this->assertFalse(AbandonedCartRecovery::enabled());
        $this->assertSame('Reminder emails are switched off (Settings › Abandoned carts).', app(AbandonedCartRecovery::class)->disabledReason());
        $this->assertSame(['sent' => 0, 'failed' => 0, 'stopped' => 0], $this->send());
        $this->assertSame('skipped', Scheduler::run('carts.abandoned-emails')['status']);
        Mail::assertNothingSent();

        $this->enable();
        config(['commerce.features.abandoned_carts' => false]);
        $this->assertSame('Feature abandoned_carts is off.', app(AbandonedCartRecovery::class)->disabledReason());
        $this->assertSame(0, $this->send()['sent']);
    }

    public function test_marketing_consent_is_required_by_default(): void
    {
        $this->enable('marketing');
        $cart = $this->basket();
        $this->assertSame(0, $this->send()['sent'], 'no consent on file');

        NewsletterSubscriber::create(['email' => 'shopper@example.test', 'source' => 'footer']);
        $this->assertSame(1, $this->send()['sent']);
        Mail::assertSent(AbandonedCartReminder::class, fn ($m) => $m->hasTo('shopper@example.test') && $m->cart->is($cart));

        // an account with the marketing box ticked also counts; an unsubscribed newsletter address does not
        $user = Commerce::userModel()::query()->where('email', 'ada@example.test')->first();
        $this->assertFalse(AbandonedCartRecovery::hasMarketingConsent('ada@example.test'));
        $user->forceFill(['marketing_opt_in' => true])->save();
        $this->assertTrue(AbandonedCartRecovery::hasMarketingConsent('ADA@example.test'));
        NewsletterSubscriber::create(['email' => 'gone@example.test', 'unsubscribed_at' => now()]);
        $this->assertFalse(AbandonedCartRecovery::hasMarketingConsent('gone@example.test'));
    }

    public function test_signed_in_customers_basket_uses_their_account_email(): void
    {
        $this->enable();
        $user = Commerce::userModel()::forceCreate(['name' => 'Grace Hopper', 'first_name' => 'Grace', 'last_name' => 'Hopper',
            'email' => 'grace@example.test', 'password' => bcrypt('Password12345'), 'role' => 'customer', 'is_active' => true]);
        $cart = $this->basket('', 2, 1, ['user_id' => $user->id]);
        $this->assertSame('grace@example.test', AbandonedCartRecovery::contactEmail($cart));
        $this->assertSame(1, $this->send()['sent']);
        Mail::assertSent(AbandonedCartReminder::class, fn ($m) => $m->hasTo('grace@example.test')
            && str_contains($m->render(), 'Hi Grace'));
    }

    // ------------------------------------------------------------------ the sequence

    public function test_three_step_sequence_timing(): void
    {
        $this->enable();
        Setting::set('abandoned_carts.step3.enabled', true);
        $cart = $this->basket('shopper@example.test', 0);

        $this->assertSame(0, $this->send()['sent'], 'not idle for an hour yet');
        $this->travel(61)->minutes();
        $this->assertSame(1, $this->send()['sent']);
        $this->assertSame(0, $this->send()['sent'], 'one email per step');
        $this->assertSame([1], $cart->recoveryEmails()->pluck('step')->all());
        $this->assertNotNull($cart->fresh()->abandoned_email_sent_at);
        $this->assertEquals($cart->updated_at, $cart->fresh()->updated_at, 'sending never moves the last-activity time');

        $this->travel(22)->hours();
        $this->assertSame(0, $this->send()['sent'], 'step 2 waits 24h after the last activity');
        $this->travel(2)->hours();
        $this->assertSame(1, $this->send()['sent']);
        $this->travel(47)->hours();
        $this->assertSame(0, $this->send()['sent']);
        $this->travel(2)->hours();
        $this->assertSame(1, $this->send()['sent']);
        $this->assertSame([1, 2, 3], $cart->recoveryEmails()->pluck('step')->all());
        $this->travel(10)->days();
        $this->assertSame(0, $this->send()['sent'], 'sequence finished');
        Mail::assertSent(AbandonedCartReminder::class, 3);
    }

    public function test_one_email_per_run_even_when_several_steps_are_due(): void
    {
        $this->enable();
        $cart = $this->basket('shopper@example.test', 30); // idle 30h: steps 1 and 2 both past their delay
        $this->assertSame(1, $this->send()['sent']);
        $this->assertSame([1], $cart->recoveryEmails()->pluck('step')->all());
        $this->assertSame(0, $this->send()['sent'], 'step 2 keeps its 23h gap after step 1');
        $this->travel(23)->hours();
        $this->assertSame(1, $this->send()['sent']);
    }

    public function test_disabled_steps_are_skipped_and_customer_activity_restarts_the_clock(): void
    {
        $this->enable();
        Setting::set('abandoned_carts.step1.enabled', false);
        $cart = $this->basket('shopper@example.test', 5);
        $this->assertSame(0, $this->send()['sent'], 'first enabled step is step 2 (24h)');
        $this->travel(20)->hours();
        $cart->touch(); // the shopper came back and changed the basket
        $this->travel(20)->hours();
        $this->assertSame(0, $this->send()['sent']);
        $this->travel(5)->hours();
        $this->assertSame(1, $this->send()['sent']);
        $this->assertSame([2], $cart->recoveryEmails()->pluck('step')->all());

        foreach ([1, 2, 3] as $n) {
            Setting::set("abandoned_carts.step{$n}.enabled", false);
        }
        $this->assertSame('Every reminder step is switched off (Settings › Abandoned carts).', app(AbandonedCartRecovery::class)->disabledReason());
    }

    public function test_old_small_and_anonymous_baskets_are_ignored(): void
    {
        $this->enable();
        $this->basket('old@example.test', 24 * 8);           // older than max_age_days (7)
        $this->basket('', 3);                                 // no email
        $this->basket('not-an-email', 3);
        Setting::set('abandoned_carts.min_value', '100');
        $this->basket('small@example.test', 3, 1);            // £30 < £100
        $this->assertSame(0, $this->send()['sent']);
        $this->basket('big@example.test', 3, 4);              // £120
        $this->assertSame(1, $this->send()['sent']);
    }

    // ------------------------------------------------------------------ stop rules

    public function test_sequence_stops_on_order_emptied_basket_and_unsubscribe(): void
    {
        $this->enable();
        $converted = $this->basket('a@example.test', 3, 1, ['converted_at' => now()]);
        $emptied = $this->basket('b@example.test');
        $emptied->items()->delete();
        $unsub = $this->basket('c@example.test');
        EmailUnsubscribe::add('C@example.test', EmailUnsubscribe::ABANDONED_CART);
        $ordered = $this->basket('d@example.test');
        Order::create(['email' => 'd@example.test', 'status' => 'processing', 'billing_country' => 'GB', 'subtotal' => 30, 'total' => 30]);
        $pendingElsewhere = $this->basket('e@example.test');
        Order::create(['email' => 'e@example.test', 'status' => 'failed', 'billing_country' => 'GB', 'subtotal' => 30, 'total' => 30]);

        $result = $this->send();
        $this->assertSame(1, $result['sent'], 'only the basket whose other order failed');
        $this->assertSame(3, $result['stopped']);
        $this->assertNull($converted->fresh()->recovery_stopped_at, 'converted baskets are simply never selected');
        $this->assertSame('emptied', $emptied->fresh()->recovery_stop_reason);
        $this->assertSame('unsubscribed', $unsub->fresh()->recovery_stop_reason);
        $this->assertSame('ordered', $ordered->fresh()->recovery_stop_reason);
        Mail::assertSent(AbandonedCartReminder::class, fn ($m) => $m->hasTo('e@example.test'));

        $this->travel(2)->days();
        $this->assertSame(0, $this->send()['stopped'], 'stopped baskets are not looked at again');
    }

    // ------------------------------------------------------------------ coupons + email content

    public function test_step_coupon_is_single_use_for_the_address_and_expires(): void
    {
        $this->enable();
        Setting::set('abandoned_carts.step1.coupon', 'percent');
        Setting::set('abandoned_carts.step1.coupon_amount', '15');
        Setting::set('abandoned_carts.step1.coupon_days', '3');
        $cart = $this->basket();
        $this->send();

        $email = $cart->recoveryEmails()->first();
        $coupon = Coupon::findByCode($email->coupon_code);
        $this->assertStringStartsWith('BASKET-', $coupon->code);
        $this->assertSame('percent', $coupon->type);
        $this->assertSame('15.00', $coupon->amount);
        $this->assertSame(1, $coupon->usage_limit);
        $this->assertSame(['shopper@example.test'], $coupon->allowed_emails);
        $this->assertTrue($coupon->expires_at->isSameDay(now()->addDays(3)));
        Mail::assertSent(AbandonedCartReminder::class, fn ($m) => str_contains($m->render(), $coupon->code) && str_contains($m->render(), '15% off'));

        // coupons switched off: no code
        config(['commerce.features.coupons' => false]);
        $this->assertNull(app(AbandonedCartRecovery::class)->createCoupon($cart, 'x@example.test', AbandonedCartRecovery::step(1)));
    }

    public function test_reminder_email_renders_basket_links_and_unsubscribe_headers(): void
    {
        $this->enable();
        Setting::set('abandoned_carts.step1.subject', 'Still want it, {name}? – {store}');
        $cart = $this->basket();
        $this->send();

        Mail::assertSent(AbandonedCartReminder::class, function (AbandonedCartReminder $mail) use ($cart) {
            $html = $mail->render();
            $this->assertSame('Still want it, there? – Acme Store', $mail->envelope()->subject);
            $this->assertStringContainsString('Classic Linen Shirt', $html);
            $this->assertStringContainsString('Qty 2', $html);
            $this->assertStringContainsString('£60.00', $html, 'two at the £30 sale price');
            $this->assertStringContainsString('Return to my basket', $html);
            $this->assertStringContainsString('/basket/restore/'.$cart->id.'/?e=', $html);
            $this->assertStringContainsString('signature=', $html);
            $this->assertStringContainsString('/basket/unsubscribe/'.$cart->id.'/', $html);
            $this->assertStringNotContainsString('<img src="'.url('/').'/track', $html);
            $headers = $mail->headers()->text;
            $this->assertStringStartsWith('<'.rtrim(url('basket/unsubscribe/'.$cart->id), '/').'/?e=', $headers['List-Unsubscribe']);
            $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);

            return true;
        });
    }

    public function test_a_failed_send_leaves_nothing_behind_and_is_retried(): void
    {
        $this->enable();
        Setting::set('abandoned_carts.step1.coupon', 'fixed_cart');
        $cart = $this->basket();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP down'));
        $this->assertSame(['sent' => 0, 'failed' => 1, 'stopped' => 0], $this->send());
        $this->assertSame(0, CartRecoveryEmail::count());
        $this->assertSame(0, Coupon::query()->where('code', 'like', 'BASKET-%')->count());
        $this->assertNull($cart->fresh()->abandoned_email_sent_at);
    }

    // ------------------------------------------------------------------ links

    protected function sentEmail(Cart $cart): CartRecoveryEmail
    {
        $this->send();

        return $cart->recoveryEmails()->firstOrFail();
    }

    public function test_restore_link_brings_the_basket_back_applies_the_coupon_and_opens_the_checkout(): void
    {
        $this->enable();
        Setting::set('abandoned_carts.step1.coupon', 'fixed_cart');
        Setting::set('abandoned_carts.step1.coupon_amount', '5');
        $cart = $this->basket();
        $email = $this->sentEmail($cart);
        $url = AbandonedCartRecovery::restoreUrl($email);

        $response = $this->get($url);
        $response->assertRedirect(url('checkout'));
        $response->assertCookie(\Pine\Commerce\Services\Cart::cookieName(), $cart->token);
        $this->assertSame(1, $email->fresh()->clicks);
        $this->assertNotNull($email->fresh()->clicked_at);
        $this->assertSame($email->coupon_code, $cart->fresh()->coupon_code);

        // tampered or unsigned links are refused
        $this->get(str_replace('/restore/'.$cart->id.'/', '/restore/'.($cart->id + 1).'/', $url))->assertForbidden();
        $this->get(url('basket/restore/'.$cart->id))->assertForbidden();
        // expired
        $this->travel(AbandonedCartRecovery::LINK_DAYS + 1)->days();
        $this->get($url)->assertForbidden();
    }

    public function test_restore_merges_into_the_visitors_own_basket(): void
    {
        $this->enable();
        $saved = $this->basket('shopper@example.test', 2, 3);
        $email = $this->sentEmail($saved);
        $other = Product::forceCreate(['name' => 'Wool Scarf', 'slug' => 'wool-scarf', 'type' => 'simple', 'status' => 'published',
            'regular_price' => 12, 'manage_stock' => false, 'stock_status' => 'instock']);
        $mine = Cart::create(['token' => Str::random(40)]);
        $mine->items()->create(['product_id' => $other->id, 'quantity' => 1]);
        $mine->items()->create(['product_id' => $this->product->id, 'quantity' => 1]);

        $this->withCookie(\Pine\Commerce\Services\Cart::cookieName(), $mine->token)
            ->get(AbandonedCartRecovery::restoreUrl($email))->assertRedirect(url('checkout'));

        $lines = $mine->items()->pluck('quantity', 'product_id')->all();
        $this->assertSame([$other->id => 1, $this->product->id => 3], $lines, 'larger quantity wins, nothing doubled');
        $this->assertSame('shopper@example.test', $mine->fresh()->email);
        $this->assertSame('merged', $saved->fresh()->recovery_stop_reason);
    }

    public function test_restore_of_a_checked_out_basket_says_so(): void
    {
        $this->enable();
        $cart = $this->basket();
        $email = $this->sentEmail($cart);
        $cart->forceFill(['converted_at' => now()])->save();
        $this->get(AbandonedCartRecovery::restoreUrl($email))->assertRedirect(url('basket'));
        $this->assertSame(1, $email->fresh()->clicks, 'the click still counts');
    }

    public function test_restore_route_is_gone_while_the_feature_is_off(): void
    {
        $this->enable();
        $cart = $this->basket();
        $email = $this->sentEmail($cart);
        config(['commerce.features.abandoned_carts' => false]);
        $this->get(AbandonedCartRecovery::restoreUrl($email))->assertNotFound();
    }

    public function test_unsubscribe_page_and_one_click_post(): void
    {
        $this->enable();
        $cart = $this->basket();
        $email = $this->sentEmail($cart);
        $url = AbandonedCartRecovery::unsubscribeUrl($email);

        $this->get($url)->assertOk()->assertSee('Unsubscribe from basket reminders')->assertSee('shopper@example.test')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertFalse(EmailUnsubscribe::has('shopper@example.test', EmailUnsubscribe::ABANDONED_CART), 'GET alone changes nothing');

        // RFC 8058 one-click: a POST from the mail app, no CSRF token, no session
        $this->post($url, ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertSee('Unsubscribed');
        $this->assertTrue(EmailUnsubscribe::has('shopper@example.test', EmailUnsubscribe::ABANDONED_CART));
        $this->assertSame('unsubscribed', $cart->fresh()->recovery_stop_reason);

        // the confirmation form
        $this->post($url, [], ['Accept' => 'text/html'])->assertOk()->assertSee('won’t get any more basket reminder emails', false);

        // a later basket from the same address is never emailed
        $this->basket('shopper@example.test', 3);
        $this->assertSame(0, $this->send()['sent']);

        $this->post(url('basket/unsubscribe/'.$cart->id))->assertForbidden();
    }

    // ------------------------------------------------------------------ recovered revenue

    public function test_orders_from_reminded_baskets_count_as_recovered(): void
    {
        $this->enable();
        $cart = $this->basket();
        $this->sentEmail($cart);
        $order = Order::create(['email' => 'shopper@example.test', 'status' => 'processing', 'billing_country' => 'GB',
            'subtotal' => 60, 'total' => 60, 'meta' => ['cart_id' => $cart->id]]);
        event(new OrderPlaced($order));
        $this->assertSame($order->id, (int) $cart->fresh()->recovered_order_id);

        // clicked a reminder, then ordered from another basket within 7 days
        $clicked = $this->basket('other@example.test');
        $mail = $this->sentEmail($clicked);
        app(AbandonedCartRecovery::class)->recordClick($mail);
        $second = Order::create(['email' => 'Other@example.test', 'status' => 'pending', 'billing_country' => 'GB',
            'subtotal' => 30, 'total' => 30, 'meta' => ['cart_id' => 999999]]);
        event(new OrderPlaced($second));
        $this->assertSame($second->id, (int) $clicked->fresh()->recovered_order_id);

        // an order with no reminder behind it
        $plain = Order::create(['email' => 'nobody@example.test', 'status' => 'processing', 'billing_country' => 'GB', 'subtotal' => 5, 'total' => 5]);
        event(new OrderPlaced($plain));

        $stats = AbandonedCartRecovery::stats();
        $this->assertSame(2, $stats['emails']);
        $this->assertSame(1, $stats['clicks']);
        $this->assertSame(1, $stats['recovered'], 'the pending (unpaid) order does not count yet');
        $this->assertSame(60.0, $stats['revenue']);
        $second->updateStatus('processing');
        $this->assertSame(90.0, AbandonedCartRecovery::stats()['revenue']);
    }

    // ------------------------------------------------------------------ back office

    public function test_admin_settings_screen_saves_the_sequence(): void
    {
        $this->actingAs($this->neutralAdmin());
        $this->get(route('admin.settings.edit', 'abandoned_carts'))->assertOk()
            ->assertSee('Send reminder emails')->assertSee('Email 3')->assertSee('You left something in your basket')
            ->assertDontSee('Reminders need scheduled tasks', false);
        config(['commerce.scheduler.web_fallback' => false]); // no cron and no web fallback: say so
        $this->get(route('admin.settings.edit', 'abandoned_carts'))->assertOk()->assertSee('Reminders need scheduled tasks', false);

        $this->put(route('admin.settings.update', 'abandoned_carts'), [
            'abandoned_carts__emails_enabled' => '1', 'abandoned_carts__consent' => 'all', 'abandoned_carts__max_age_days' => '5',
            'abandoned_carts__step1__enabled' => '1', 'abandoned_carts__step1__delay_hours' => '2', 'abandoned_carts__step1__subject' => 'Come back',
            'abandoned_carts__step1__coupon' => 'percent', 'abandoned_carts__step1__coupon_amount' => '10', 'abandoned_carts__step1__coupon_days' => '5',
        ])->assertRedirect(route('admin.settings.edit', 'abandoned_carts'));
        $this->assertTrue(AbandonedCartRecovery::enabled());
        $this->assertSame('all', AbandonedCartRecovery::consent());
        $step = AbandonedCartRecovery::step(1);
        $this->assertSame([2, 'Come back', 'percent'], [$step['delay_hours'], $step['subject'], $step['coupon']]);

        $this->put(route('admin.settings.update', 'abandoned_carts'), ['abandoned_carts__consent' => 'everyone'])->assertSessionHasErrors();

        config(['commerce.features.abandoned_carts' => false]);
        $this->get(route('admin.settings.edit', 'abandoned_carts'))->assertNotFound();
    }

    public function test_admin_basket_list_detail_timeline_and_stop(): void
    {
        $this->actingAs($this->neutralAdmin());
        $this->enable();
        $cart = $this->basket();
        $email = $this->sentEmail($cart);
        app(AbandonedCartRecovery::class)->recordClick($email);

        $this->get(route('admin.carts.index'))->assertOk()
            ->assertSee('data-recovery-stats', false)->assertSee('1 sent')->assertSee('Clicked')
            ->assertSee(route('admin.carts.show', $cart->id), false);

        $this->get(route('admin.carts.show', $cart->id))->assertOk()
            ->assertSee('Basket #'.$cart->id)->assertSee('Reminder 1 sent to shopper@example.test')
            ->assertSee('Returned to the basket from reminder 1')->assertSee('Stop reminders');

        $this->post(route('admin.carts.stop', $cart->id))->assertRedirect(route('admin.carts.show', $cart->id));
        $this->assertSame('staff', $cart->fresh()->recovery_stop_reason);
        $this->get(route('admin.carts.show', $cart->id))->assertOk()->assertSee('Reminders stopped: Stopped by staff');

        $this->get(route('admin.dashboard'))->assertOk()->assertSee('data-dashboard-recovery', false)->assertSee('Recovered revenue');
    }

    public function test_dashboard_card_hidden_while_reminders_were_never_used(): void
    {
        $this->actingAs($this->neutralAdmin());
        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee('data-dashboard-recovery', false);
        $this->get(route('admin.carts.index'))->assertOk()->assertDontSee('data-recovery-stats', false);
    }
}
