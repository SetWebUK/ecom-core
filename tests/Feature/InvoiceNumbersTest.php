<?php

namespace Pine\Commerce\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\Setting;
use Pine\Commerce\Services\Invoices\InvoiceNumbers;
use Pine\Commerce\Services\Invoices\Invoices;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/**
 * Sequential invoice numbers: issued on paid/completed, formatted, never reused, safe under concurrency.
 * Fresh neutral store on in-memory SQLite; the concurrency test uses its own temporary SQLite FILE (never MySQL).
 */
class InvoiceNumbersTest extends TestCase
{
    use InstallsNeutralStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNeutralStore();
        Invoices::flush();
    }

    protected function tearDown(): void
    {
        Invoices::flush();
        $this->tearDownNeutralStore();
        parent::tearDown();
    }

    protected function newOrder(string $status = 'pending', array $attributes = []): Order
    {
        return Order::create($attributes + ['email' => 'buyer@example.test', 'status' => $status, 'subtotal' => 10, 'total' => 10]);
    }

    public function test_numbers_are_issued_in_sequence_when_orders_are_paid_and_never_change(): void
    {
        $a = $this->newOrder();
        $b = $this->newOrder();
        $this->assertNull($a->fresh()->invoice_number, 'pending orders have no invoice number');
        $this->assertNull(Invoices::number($a->fresh()));

        $b->updateStatus('processing');
        $a->updateStatus('processing');
        $this->assertSame('INV-00001', $b->fresh()->invoice_number, 'package default format: INV- + 5 digits');
        $this->assertSame('INV-00002', $a->fresh()->invoice_number);
        $this->assertNotNull($a->fresh()->invoice_date);

        // later status changes keep the number
        $a->fresh()->updateStatus('completed');
        $a->fresh()->updateStatus('refunded');
        $this->assertSame('INV-00002', $a->fresh()->invoice_number);
        $this->assertSame('INV-00002', app(InvoiceNumbers::class)->assign($a->fresh()), 'assign() is idempotent');

        // a new order paid straight to completed gets the next number
        $c = $this->newOrder();
        $c->updateStatus('completed');
        $this->assertSame('INV-00003', $c->fresh()->invoice_number);
        $this->assertSame(4, app(InvoiceNumbers::class)->peek());
        $this->assertSame(3, app(InvoiceNumbers::class)->last());
    }

    public function test_assign_on_completed_waits_for_completion(): void
    {
        Setting::set('invoices.assign_on', 'completed');
        $order = $this->newOrder();
        $order->updateStatus('processing');
        $this->assertNull($order->fresh()->invoice_number);
        $this->assertFalse(Invoices::available($order->fresh()));
        $order->fresh()->updateStatus('completed');
        $this->assertSame('INV-00001', $order->fresh()->invoice_number);
    }

    public function test_cancelled_failed_and_on_hold_orders_get_no_number(): void
    {
        foreach (['on-hold', 'cancelled', 'failed'] as $status) {
            $order = $this->newOrder();
            $order->updateStatus($status);
            $this->assertNull($order->fresh()->invoice_number, $status);
        }
        $this->assertSame(0, app(InvoiceNumbers::class)->last());
    }

    public function test_format_prefix_suffix_padding_and_date_tokens(): void
    {
        Setting::set('invoices.prefix', '{Y}/');
        Setting::set('invoices.suffix', '-UK');
        Setting::set('invoices.padding', 3);
        Carbon::setTestNow('2027-03-15 10:00:00');
        try {
            $order = $this->newOrder();
            $order->updateStatus('processing');
            $this->assertSame('2027/001-UK', $order->fresh()->invoice_number);
        } finally {
            Carbon::setTestNow();
        }

        // an empty prefix is a real choice (not "use the default")
        Setting::set('invoices.prefix', '');
        Setting::set('invoices.suffix', '');
        Setting::set('invoices.padding', 0);
        $next = $this->newOrder();
        $next->updateStatus('processing');
        $this->assertSame('2', $next->fresh()->invoice_number);
    }

    public function test_next_number_only_moves_forward_and_numbers_are_never_reused(): void
    {
        $first = $this->newOrder();
        $first->updateStatus('processing');
        $this->assertSame('INV-00001', $first->fresh()->invoice_number);

        Setting::set('invoices.next_number', 500);
        $jump = $this->newOrder();
        $jump->updateStatus('processing');
        $this->assertSame('INV-00500', $jump->fresh()->invoice_number);

        // moving it back is ignored – the counter never goes backwards
        Setting::set('invoices.next_number', 2);
        $after = $this->newOrder();
        $after->updateStatus('processing');
        $this->assertSame('INV-00501', $after->fresh()->invoice_number);

        // a formatted number that already exists (e.g. imported) is skipped, not duplicated
        $this->newOrder('completed')->forceFill(['invoice_number' => 'INV-00502'])->save();
        $skip = $this->newOrder();
        $skip->updateStatus('processing');
        $this->assertSame('INV-00503', $skip->fresh()->invoice_number);

        // the Settings form refuses a lower number
        $this->actingAs($this->neutralAdmin());
        $this->put(route('admin.settings.update', 'invoices'), ['invoices__next_number' => 10])
            ->assertSessionHasErrors('invoices__next_number');
        $this->put(route('admin.settings.update', 'invoices'), ['invoices__next_number' => 900])->assertSessionHasNoErrors();
        $this->assertSame(900, app(InvoiceNumbers::class)->peek());
    }

    public function test_numbering_off_uses_the_order_number_and_writes_nothing(): void
    {
        Setting::set('invoices.numbering', false);
        $order = $this->newOrder();
        $order->updateStatus('processing');
        $this->assertNull($order->fresh()->invoice_number);
        $this->assertSame((string) $order->number, Invoices::number($order->fresh()));
        $this->assertTrue(Invoices::available($order->fresh()));
        $this->assertSame(0, app(InvoiceNumbers::class)->last());
        $this->assertSame('invoice-'.$order->number.'.pdf', Invoices::filename($order->fresh()));
    }

    public function test_orders_paid_before_numbering_was_switched_on_get_a_number_when_their_invoice_is_first_made(): void
    {
        Setting::set('invoices.numbering', false);
        $old = $this->newOrder();
        $old->updateStatus('completed');
        Setting::set('invoices.numbering', true);

        $this->assertNull($old->fresh()->invoice_number);
        $this->assertSame('INV-00001', Invoices::number($old->fresh()));
        $this->assertSame('INV-00001', $old->fresh()->invoice_number);
        $this->assertNull(Invoices::number($this->newOrder('pending')), 'unpaid orders still get none');
    }

    /**
     * Several PHP processes issue numbers at the same moment – each process tries EVERY order (so the same order is
     * raced too). Afterwards every order has exactly one number, no number is used twice and there are no gaps.
     */
    public function test_concurrent_processes_never_issue_the_same_number(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Needs the pcntl and posix extensions.');
        }
        $file = storage_path('framework/testing/invoice-race-'.getmypid().'-'.uniqid().'.sqlite');
        @mkdir(dirname($file), 0775, true);
        touch($file);
        $default = config('database.default');
        config(['database.connections.invoice_race' => [
            'driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => false,
            'busy_timeout' => 20000, 'journal_mode' => 'wal', 'transaction_mode' => 'IMMEDIATE',
        ]]);

        try {
            $schema = Schema::connection('invoice_race');
            $schema->create('orders', function (Blueprint $table) {
                $table->id();
                $table->string('number')->unique();
                $table->string('order_key', 64)->nullable();
                $table->string('status')->default('processing');
                $table->string('invoice_number', 60)->nullable()->unique();
                $table->timestamp('invoice_date')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
            $schema->create('sequences', function (Blueprint $table) {
                $table->string('name', 60)->primary();
                $table->unsignedBigInteger('value')->default(0);
                $table->timestamps();
            });
            $orders = 40;
            for ($i = 1; $i <= $orders; $i++) {
                DB::connection('invoice_race')->table('orders')->insert(['number' => (string) (1000 + $i), 'status' => 'processing', 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::purge('invoice_race');
            Setting::allCached(); // settings memo filled before forking (children never touch the in-memory DB)

            $children = [];
            for ($p = 0; $p < 4; $p++) {
                $pid = pcntl_fork();
                if ($pid === 0) {
                    // child: its own connection to the file database
                    $ok = true;
                    try {
                        DB::purge('invoice_race');
                        config(['database.default' => 'invoice_race']);
                        Invoices::flush();
                        $ids = range(1, $orders);
                        mt_srand(getmypid());
                        shuffle($ids);
                        foreach ($ids as $id) {
                            $order = Order::query()->find($id);
                            app(InvoiceNumbers::class)->assign($order);
                        }
                    } catch (\Throwable $e) {
                        $ok = false;
                        file_put_contents($file.'.err', getmypid().': '.$e->getMessage()."\n", FILE_APPEND);
                    }
                    // hard exit: no PHPUnit shutdown handlers in the child
                    posix_kill(getmypid(), $ok ? SIGKILL : SIGTERM);
                }
                $this->assertGreaterThan(0, $pid, 'fork failed');
                $children[] = $pid;
            }
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifsignaled($status) && pcntl_wtermsig($status) === SIGKILL, 'a child process failed: '.@file_get_contents($file.'.err'));
            }

            config(['database.default' => $default]);
            DB::purge('invoice_race');
            $numbers = DB::connection('invoice_race')->table('orders')->pluck('invoice_number')->all();
            $this->assertCount($orders, array_filter($numbers), 'every order got a number');
            $this->assertCount($orders, array_unique($numbers), 'no number was issued twice');
            $expected = array_map(fn ($n) => 'INV-'.str_pad((string) $n, 5, '0', STR_PAD_LEFT), range(1, $orders));
            sort($numbers);
            $this->assertSame($expected, $numbers, 'no gaps, no duplicates');
            $this->assertSame($orders, (int) DB::connection('invoice_race')->table('sequences')->where('name', 'invoice')->value('value'));
        } finally {
            config(['database.default' => $default]);
            DB::purge('invoice_race');
            Invoices::flush();
            foreach ([$file, $file.'-wal', $file.'-shm', $file.'.err'] as $f) {
                @unlink($f);
            }
        }
    }
}
