<?php

namespace Pine\Commerce\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/**
 * A client may run the store on a database connection with a table prefix (shared hosting, the "scratch" import
 * rehearsal). Raw SQL in the package must go through the grammar (wrap / wrapTable) or DB::getTablePrefix(), never a
 * bare table name – otherwise the back office fails with "no such table". The whole neutral store is installed on a
 * prefixed in-memory SQLite connection here and every back-office list screen is opened.
 */
class TablePrefixTest extends TestCase
{
    use InstallsNeutralStore;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            $this->markTestSkipped('Needs phpunit\'s in-memory SQLite database.');
        }
        config(['database.connections.sqlite.prefix' => 'pc_']);
        DB::purge('sqlite');
        $this->setUpNeutralStore();
    }

    protected function tearDown(): void
    {
        $this->tearDownNeutralStore();
        parent::tearDown();
    }

    public function test_tables_really_are_prefixed(): void
    {
        $this->assertSame('pc_', DB::connection()->getTablePrefix());
        $tables = array_column(DB::select("select name from sqlite_master where type = 'table'"), 'name');
        $this->assertContains('pc_orders', $tables);
        $this->assertNotContains('orders', $tables);
    }

    public function test_back_office_screens_work_on_a_prefixed_connection(): void
    {
        [$product, $category, $order] = $this->catalogue();
        $customer = $order->user;
        $this->actingAs($this->neutralAdmin());

        $screens = [
            route('admin.dashboard'),
            route('admin.products.index'), route('admin.products.index', ['stock' => 'low']), route('admin.products.index', ['sort' => 'stock']),
            route('admin.products.index', ['q' => 'linen', 'category' => $category->id]),
            route('admin.products.inventory'), route('admin.products.edit', $product),
            route('admin.customers.index'), route('admin.customers.index', ['sort' => 'total_spent', 'q' => 'ada']),
            route('admin.customers.index', ['sort' => 'name']), route('admin.customers.show', $customer),
            route('admin.orders.index'), route('admin.orders.index', ['q' => 'Ada']), route('admin.orders.show', $order),
            route('admin.categories.index'), route('admin.attributes.index'), route('admin.coupons.index'),
            route('admin.carts.index'), route('admin.pages.index'), route('admin.posts.index'), route('admin.redirects.index'),
            route('admin.reviews.index'), route('admin.staff.index'), route('admin.form-submissions.index'),
            route('admin.menus.index'), route('admin.search', ['q' => 'linen']), route('admin.search.suggest', ['q' => 'ada']),
        ];
        foreach (['today', '7d', '30d', 'year'] as $range) {
            $screens[] = route('admin.reports.index', ['range' => $range]);
        }
        $this->withoutExceptionHandling();
        $failures = [];
        foreach ($screens as $url) {
            try {
                $status = $this->get($url)->getStatusCode();
                if ($status !== 200) {
                    $failures[] = "{$url}: HTTP {$status}";
                }
            } catch (\Throwable $e) {
                $failures[] = "{$url}: ".mb_substr($e->getMessage(), 0, 300);
            }
        }
        $this->assertSame([], $failures, implode("\n", $failures));

        // the back-office menu badges (one raw query; it fails silently to 0)
        $counts = (new \ReflectionMethod(\Pine\Commerce\View\Components\Admin\Sidebar::class, 'counts'))
            ->invoke((new \ReflectionClass(\Pine\Commerce\View\Components\Admin\Sidebar::class))->newInstanceWithoutConstructor());
        $this->assertSame(1, $counts['orders'], 'processing orders badge');

        // storefront listing/search on the same connection
        $this->get(route('shop'))->assertOk()->assertSee('Classic Linen Shirt');
        $this->get(route('search.suggest', ['q' => 'shirt']))->assertOk();
    }
}
