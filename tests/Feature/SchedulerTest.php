<?php

namespace Pine\Commerce\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Pine\Commerce\Mail\BackInStock;
use Pine\Commerce\Mail\LowStockReport;
use Pine\Commerce\Models\Cart;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\Setting;
use Pine\Commerce\Models\StockNotification;
use Pine\Commerce\Scheduling\Scheduler;
use Pine\Commerce\Scheduling\Tasks\PruneStaleData;
use Pine\Commerce\Services\Checkout\CheckoutService;
use Pine\Commerce\Support\Doctor;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/**
 * Core scheduled tasks (Pine\Commerce\Scheduling\Scheduler): registration with Laravel's scheduler, heartbeat and
 * cron detection, recorded runs, the web and checkout fallbacks without cron, every task's work, the
 * commerce:schedule:* commands and the commerce:doctor check. Fresh package install on in-memory SQLite only.
 */
class SchedulerTest extends TestCase
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

    // ------------------------------------------------------------------ registration

    /** @return array<string, \Illuminate\Console\Scheduling\Event> */
    protected function scheduledEvents(): array
    {
        $schedule = new Schedule;
        Scheduler::register($schedule);
        $events = [];
        foreach ($schedule->events() as $event) {
            $events[$event->description] = $event;
        }

        return $events;
    }

    public function test_package_defaults_register_the_heartbeat_and_every_task(): void
    {
        $this->assertTrue(config('commerce.scheduler.enabled'));
        $this->assertTrue(config('commerce.scheduler.web_fallback'), 'new clients: web fallback on');
        $this->assertSame(array_keys(Scheduler::TASKS), array_keys(config('commerce.scheduler.tasks')), 'config lists every task');

        $schedule = new Schedule;
        Scheduler::register($schedule);
        $names = array_map(fn ($e) => $e->mutexName(), $schedule->events());
        $this->assertCount(count(Scheduler::TASKS) + 1, $schedule->events());
        $crons = [];
        foreach ($schedule->events() as $event) {
            $crons[$event->description] = $event->expression;
        }
        $this->assertSame('* * * * *', $crons['commerce:heartbeat']);
        foreach (Scheduler::TASKS as $key => [$label, , $cron]) {
            $this->assertSame($cron, $crons[$label], $key);
        }
        $this->assertCount(count($names), array_unique($names), 'every event has its own overlap mutex');
    }

    public function test_the_application_schedule_contains_the_core_tasks(): void
    {
        $descriptions = array_map(fn ($e) => $e->description, $this->app->make(Schedule::class)->events());
        $this->assertContains('commerce:heartbeat', $descriptions);
        $this->assertContains('Abandoned-cart reminders', $descriptions);
    }

    public function test_tasks_switched_off_in_config_are_not_scheduled(): void
    {
        config(['commerce.scheduler.tasks' => ['maintenance.prune' => false] + config('commerce.scheduler.tasks')]);
        $this->assertFalse(Scheduler::configured('maintenance.prune'));
        $this->assertArrayNotHasKey('Tidy up old data', $this->scheduledEvents());
        $this->assertArrayHasKey('Scheduled sale prices', $this->scheduledEvents());

        config(['commerce.scheduler.enabled' => false]);
        $this->assertSame([], $this->scheduledEvents());
        $this->assertFalse(Scheduler::configured('catalog.sale-prices'));
        $this->assertFalse(Scheduler::configured('no.such-task'));
    }

    // ------------------------------------------------------------------ heartbeat, runs, status

    public function test_heartbeat_detects_cron(): void
    {
        $this->assertNull(Scheduler::lastHeartbeat());
        $this->assertFalse(Scheduler::cronRunning());

        Scheduler::beat();
        $this->assertTrue(Scheduler::cronRunning());
        $this->assertSame('scheduler', DB::table('settings')->where('key', Scheduler::HEARTBEAT)->value('group'));

        $this->travel(6)->minutes();
        $this->assertFalse(Scheduler::cronRunning(), 'no beat within heartbeat_minutes (5)');
        Scheduler::beat();
        $this->assertTrue(Scheduler::cronRunning());
        $this->assertSame(1, DB::table('settings')->where('key', Scheduler::HEARTBEAT)->count(), 'one row, updated in place');
    }

    public function test_heartbeat_event_writes_the_heartbeat(): void
    {
        $this->scheduledEvents()['commerce:heartbeat']->run($this->app);
        $this->assertTrue(Scheduler::cronRunning());
    }

    public function test_runs_are_recorded_with_status_and_next_run(): void
    {
        $result = Scheduler::run('catalog.sale-prices', 'cron');
        $this->assertSame('ok', $result['status']);
        $this->assertSame('0 prices updated', $result['summary']);

        $last = Scheduler::lastRun('catalog.sale-prices');
        $this->assertSame('ok', $last['status']);
        $this->assertSame('cron', $last['via']);
        $this->assertTrue($last['at']->isToday());

        // skipped: switched off in settings
        $this->assertSame('skipped', Scheduler::run('inventory.low-stock-email')['status']);
        $this->assertStringContainsString('Switched off', Scheduler::lastRun('inventory.low-stock-email')['summary']);

        $status = collect(Scheduler::status())->keyBy('key');
        $this->assertCount(count(Scheduler::TASKS), $status);
        $this->assertNotNull($status['catalog.sale-prices']['last']);
        $this->assertNull($status['maintenance.prune']['last']);
        $this->assertTrue($status['maintenance.prune']['next']->isFuture());
        $this->assertNotNull($status['carts.abandoned-emails']['skip'], 'reminders are off by default');
    }

    public function test_a_failing_task_is_recorded_and_never_throws(): void
    {
        config(['auth.passwords.users' => ['provider' => 'users', 'table' => 'no_such_table_xyz', 'expire' => 60]]);
        $this->app->bind(PruneStaleData::class, fn () => new class extends PruneStaleData
        {
            public function handle(): string
            {
                throw new \RuntimeException('disk full');
            }
        });
        $result = Scheduler::run('maintenance.prune');
        $this->assertSame('failed', $result['status']);
        $this->assertSame('disk full', Scheduler::lastRun('maintenance.prune')['summary']);
    }

    public function test_next_run_follows_the_cron_expression_in_the_store_timezone(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-07-01 05:30:00', 'UTC')); // 06:30 in London (BST)
        $next = Scheduler::nextRun('inventory.low-stock-email');
        $this->assertSame('2026-07-01 06:00:00', $next->copy()->setTimezone('UTC')->format('Y-m-d H:i:s'), '07:00 London = 06:00 UTC in summer');
        $this->assertTrue(Scheduler::isDue('inventory.low-stock-email'), 'never ran = due');

        Scheduler::record('inventory.low-stock-email', ['status' => 'ok', 'summary' => '', 'ms' => 1, 'via' => 'cron']);
        $this->assertFalse(Scheduler::isDue('inventory.low-stock-email'));
        $this->travel(31)->minutes();
        $this->assertTrue(Scheduler::isDue('inventory.low-stock-email'));
    }

    // ------------------------------------------------------------------ fallbacks without cron

    public function test_web_fallback_runs_due_tasks_at_most_every_five_minutes_and_never_with_cron(): void
    {
        $ran = Scheduler::webFallback();
        $this->assertSame(array_keys(Scheduler::TASKS), array_keys($ran), 'no cron, never ran: every task is due');
        $this->assertSame('web', Scheduler::lastRun('catalog.sale-prices')['via']);
        $this->assertSame([], Scheduler::webFallback(), 'throttled');

        Cache::forget('commerce.scheduler.web-fallback');
        $this->travel(6)->minutes();
        $again = Scheduler::webFallback();
        $this->assertArrayHasKey('catalog.sale-prices', $again, 'every 5 minutes – due again');
        $this->assertArrayNotHasKey('maintenance.prune', $again, 'daily – not due again');

        Cache::forget('commerce.scheduler.web-fallback');
        $this->travel(6)->minutes();
        Scheduler::beat();
        $this->assertSame([], Scheduler::webFallback(), 'cron is running');

        Cache::forget('commerce.scheduler.web-fallback');
        $this->travel(10)->minutes();
        config(['commerce.scheduler.web_fallback' => false]);
        $this->assertSame([], Scheduler::webFallback(), 'switched off in config');
    }

    protected function staleOrder(): Order
    {
        [$product] = $this->catalogue();
        $order = Order::create([
            'email' => 'late@example.test', 'status' => 'pending', 'created_via' => 'checkout', 'payment_method' => 'stripe',
            'billing_first_name' => 'Late', 'billing_last_name' => 'Payer', 'billing_country' => 'GB',
            'subtotal' => 30, 'total' => 30, 'meta' => ['stock_reduced' => true],
        ]);
        $order->items()->create(['product_id' => $product->id, 'name' => $product->name, 'quantity' => 1, 'unit_price' => 30, 'subtotal' => 30, 'total' => 30]);
        Order::query()->whereKey($order->id)->toBase()->update(['updated_at' => now()->subMinutes(61)]);

        return $order->fresh();
    }

    public function test_cancel_unpaid_orders_task(): void
    {
        $order = $this->staleOrder();
        $result = Scheduler::run('orders.cancel-unpaid', 'cron');
        $this->assertSame('1 unpaid order cancelled', $result['summary']);
        $this->assertSame('cancelled', $order->fresh()->status);

        Setting::set('checkout.hold_stock_minutes', '0');
        $this->assertSame('skipped', Scheduler::run('orders.cancel-unpaid')['status']);
    }

    public function test_checkout_visit_fallback_only_runs_without_cron(): void
    {
        $order = $this->staleOrder();
        Scheduler::beat();
        CheckoutService::cancelStaleOrders();
        $this->assertSame('pending', $order->fresh()->status, 'cron runs the task – the checkout leaves it alone');

        Cache::forget('checkout.stale-order-sweep');
        $this->travel(10)->minutes();
        CheckoutService::cancelStaleOrders();
        $this->assertSame('cancelled', $order->fresh()->status, 'no heartbeat: the checkout visit cancels it (as before)');
    }

    public function test_checkout_fallback_respects_the_config_switch(): void
    {
        $order = $this->staleOrder();
        config(['commerce.scheduler.tasks' => ['orders.cancel-unpaid' => false] + config('commerce.scheduler.tasks')]);
        CheckoutService::cancelStaleOrders();
        $this->assertSame('pending', $order->fresh()->status);
    }

    // ------------------------------------------------------------------ the tasks

    public function test_sale_prices_follow_scheduled_dates_without_touching_updated_at(): void
    {
        [$product] = $this->catalogue();
        $product->forceFill(['sale_starts_at' => now()->addHour(), 'sale_ends_at' => now()->addDays(2)])->save();
        $this->assertSame('40.00', $product->fresh()->price, 'sale not started: regular price stored');
        $stamp = $product->fresh()->updated_at;

        $this->travel(2)->hours();
        $this->assertSame('1 price updated', Scheduler::run('catalog.sale-prices')['summary']);
        $this->assertSame('30.00', $product->fresh()->price);
        $this->assertEquals($stamp, $product->fresh()->updated_at);

        $this->assertSame('0 prices updated', Scheduler::run('catalog.sale-prices')['summary'], 'idempotent');

        $this->travel(3)->days();
        Scheduler::run('catalog.sale-prices');
        $this->assertSame('40.00', $product->fresh()->price, 'sale ended');
    }

    public function test_prune_deletes_only_old_guest_baskets_sessions_and_reset_links(): void
    {
        [$product] = $this->catalogue();
        $user = \Pine\Commerce\Commerce::userModel()::query()->where('email', 'ada@example.test')->first();
        $make = function (array $attrs, int $daysOld) use ($product) {
            $cart = Cart::create(['token' => Str::random(40)] + $attrs);
            $cart->items()->create(['product_id' => $product->id, 'quantity' => 1]);
            Cart::query()->whereKey($cart->id)->toBase()->update(['updated_at' => now()->subDays($daysOld)]);

            return $cart;
        };
        $oldGuest = $make([], 100);
        $recentGuest = $make([], 10);
        $oldCustomer = $make(['user_id' => $user->id], 400);
        $oldConverted = $make(['converted_at' => now()->subDays(200)], 200);
        DB::table('password_reset_tokens')->insert([
            ['email' => 'old@example.test', 'token' => 'x', 'created_at' => now()->subHours(3)],
            ['email' => 'new@example.test', 'token' => 'y', 'created_at' => now()->subMinutes(5)],
        ]);

        $result = Scheduler::run('maintenance.prune');
        $this->assertSame('1 guest basket, 0 sessions, 1 reset link deleted', $result['summary']);
        $this->assertNull(Cart::find($oldGuest->id));
        $this->assertSame(0, DB::table('cart_items')->where('cart_id', $oldGuest->id)->count());
        $this->assertNotNull(Cart::find($recentGuest->id));
        $this->assertNotNull(Cart::find($oldCustomer->id), 'customers’ baskets are kept');
        $this->assertNotNull(Cart::find($oldConverted->id), 'baskets that became orders are kept');
        $this->assertSame(['new@example.test'], DB::table('password_reset_tokens')->pluck('email')->all());

        Setting::set('scheduler.cart_retention_days', '0');
        Cart::query()->whereKey($recentGuest->id)->toBase()->update(['updated_at' => now()->subYears(3)]);
        Scheduler::run('maintenance.prune');
        $this->assertNotNull(Cart::find($recentGuest->id), '0 = keep every basket');
    }

    public function test_prune_removes_expired_database_sessions(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('sessions')) {
            $this->markTestSkipped('No sessions table in this install.');
        }
        config(['session.driver' => 'database', 'session.lifetime' => 120]);
        DB::table('sessions')->insert([
            ['id' => 'old', 'payload' => '', 'last_activity' => now()->subHours(3)->getTimestamp()],
            ['id' => 'live', 'payload' => '', 'last_activity' => now()->subMinutes(5)->getTimestamp()],
        ]);
        $this->assertSame(1, (new PruneStaleData)->pruneSessions());
        $this->assertSame(['live'], DB::table('sessions')->pluck('id')->all());
    }

    public function test_low_stock_email_is_off_by_default_and_lists_low_and_sold_out_products(): void
    {
        [$product] = $this->catalogue();
        Mail::fake();
        $this->assertSame('skipped', Scheduler::run('inventory.low-stock-email')['status']);
        Mail::assertNothingSent();

        Setting::set('scheduler.low_stock_email', true);
        Setting::set('emails.admin_address', 'owner@shop.example.test, stock@shop.example.test');
        $this->assertStringStartsWith('Nothing is low', Scheduler::run('inventory.low-stock-email')['summary']);
        Mail::assertNothingSent();

        $product->forceFill(['stock_quantity' => 1])->save();
        $sold = Product::forceCreate(['name' => 'Sold Out Scarf', 'slug' => 'sold-out-scarf', 'type' => 'simple', 'status' => 'published',
            'regular_price' => 10, 'manage_stock' => true, 'stock_quantity' => 0, 'backorders' => 'no', 'stock_status' => 'outofstock']);
        $result = Scheduler::run('inventory.low-stock-email');
        $this->assertSame('ok', $result['status']);
        Mail::assertSent(LowStockReport::class, function (LowStockReport $mail) use ($product, $sold) {
            $html = $mail->render();

            return $mail->hasTo('owner@shop.example.test') && $mail->hasTo('stock@shop.example.test')
                && $mail->low->pluck('id')->all() === [$product->id] && $mail->out->pluck('id')->all() === [$sold->id]
                && str_contains($html, 'Classic Linen Shirt') && str_contains($html, '1 left') && str_contains($html, 'Sold Out Scarf')
                && $mail->envelope()->subject === 'Stock report: 1 low, 1 sold out';
        });
    }

    public function test_back_in_stock_sweep_sends_waiting_alerts_for_available_products(): void
    {
        [$product] = $this->catalogue();
        Mail::fake();
        $waiting = StockNotification::create(['product_id' => $product->id, 'email' => 'fan@example.test']);
        $out = Product::forceCreate(['name' => 'Gone', 'slug' => 'gone', 'type' => 'simple', 'status' => 'published', 'regular_price' => 5,
            'manage_stock' => true, 'stock_quantity' => 0, 'backorders' => 'no', 'stock_status' => 'outofstock']);
        StockNotification::create(['product_id' => $out->id, 'email' => 'fan@example.test']);

        $this->assertSame('1 sent, 0 failed, 1 still waiting', Scheduler::run('stock.back-in-stock')['summary']);
        Mail::assertSent(BackInStock::class, fn ($m) => $m->hasTo('fan@example.test'));
        $this->assertNotNull($waiting->fresh()->notified_at);

        config(['commerce.features.stock_alerts' => false]);
        $this->assertSame('skipped', Scheduler::run('stock.back-in-stock')['status']);
    }

    // ------------------------------------------------------------------ commands + doctor

    public function test_schedule_status_command(): void
    {
        Scheduler::run('catalog.sale-prices', 'manual');
        $this->artisan('commerce:schedule:status')
            ->expectsOutputToContain('Cron has never run schedule:run')
            ->expectsOutputToContain('ok: 0 prices updated')
            ->assertSuccessful();

        Scheduler::beat();
        $this->artisan('commerce:schedule:status')->expectsOutputToContain('Cron is running')->assertSuccessful();

        $this->artisan('commerce:schedule:status', ['--json' => true])->expectsOutputToContain('"cron_running": true')->assertSuccessful();
    }

    public function test_schedule_task_command(): void
    {
        $this->artisan('commerce:schedule:task', ['task' => 'catalog.sale-prices'])->expectsOutputToContain('catalog.sale-prices: ok')->assertSuccessful();
        $this->assertSame('manual', Scheduler::lastRun('catalog.sale-prices')['via']);
        $this->artisan('commerce:schedule:task', ['task' => 'nope'])->assertFailed();
    }

    public function test_doctor_reports_the_cron_heartbeat(): void
    {
        $check = fn () => collect((new Doctor)->run())->firstWhere('title', 'Scheduler');

        $this->assertSame(Doctor::INFO, $check()['status'], 'no cron but web fallback on: informational');
        $this->assertStringContainsString('No cron detected', $check()['message']);
        $this->assertStringContainsString('schedule:run', $check()['message']);

        config(['commerce.scheduler.web_fallback' => false]);
        $this->assertSame(Doctor::WARN, $check()['status']);
        $this->assertStringContainsString('cPanel', $check()['fix']);

        Scheduler::beat();
        $this->assertSame(Doctor::PASS, $check()['status']);
        $this->assertStringContainsString('Cron is running', $check()['message']);
    }

    // ------------------------------------------------------------------ admin

    public function test_scheduled_tasks_settings_screen(): void
    {
        $this->actingAs($this->neutralAdmin());
        Scheduler::run('catalog.sale-prices');
        $this->get(route('admin.settings.edit', 'automation'))->assertOk()
            ->assertSee('Cron is not set up')
            ->assertSee('php artisan schedule:run', false)
            ->assertSee('data-task="catalog.sale-prices"', false)
            ->assertSee('0 prices updated')
            ->assertSee('Daily low-stock email');

        $this->put(route('admin.settings.update', 'automation'), ['scheduler__low_stock_email' => '1', 'scheduler__cart_retention_days' => '30'])
            ->assertRedirect();
        $this->assertTrue(setting('scheduler.low_stock_email'));
        $this->assertSame(30, PruneStaleData::cartRetentionDays());

        Scheduler::beat();
        $this->get(route('admin.settings.edit', 'automation'))->assertOk()->assertSee('Cron is running');
        $this->get(route('admin.settings.index'))->assertOk()->assertSee('Scheduled tasks');
    }
}
