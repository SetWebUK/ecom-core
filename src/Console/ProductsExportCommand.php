<?php

namespace Pine\Commerce\Console;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Pine\Commerce\Services\Admin\Catalogue\ProductCsv\Exporter;
use Pine\Commerce\Services\Admin\Catalogue\ProductFilter;
use Pine\Commerce\Services\Admin\LocalTime;

/**
 * The full product CSV from the command line – the same file as Admin › Products › Import / Export.
 *
 *   php artisan commerce:products:export                                  → storage/app/private/products-full-{date}.csv
 *   php artisan commerce:products:export --output=products.csv --status=active --category=12 --q=mug
 *   php artisan commerce:products:export --output=-                       → stdout
 */
class ProductsExportCommand extends Command
{
    protected $signature = 'commerce:products:export
        {--output= : File to write ("-" = standard output; default storage/app/private/products-full-{date}.csv)}
        {--status= : Only this list tab: active, draft, outofstock, onsale, featured, trashed}
        {--category= : Only this category id (and its sub-categories)}
        {--stock= : instock, low, outofstock or onbackorder}
        {--type= : simple or variable}
        {--q= : Search by name or SKU}
        {--no-variations : Leave out the variant rows}';

    protected $description = 'Export every product (and variant) to the full product CSV';

    public function handle(): int
    {
        if (! \Pine\Commerce\Support\Features::enabled('product_csv', false)) {
            $this->error('The product CSV is switched off (config commerce.features.product_csv).');

            return self::FAILURE;
        }
        $filters = array_filter([
            'status' => $this->option('status'), 'category' => $this->option('category'), 'stock' => $this->option('stock'),
            'type' => $this->option('type'), 'q' => $this->option('q'),
        ], fn ($v) => $v !== null && $v !== '');
        $query = ProductFilter::fromRequest(Request::create('/', 'GET', $filters))->query();

        $output = (string) ($this->option('output') ?: storage_path('app/private/products-full-'.LocalTime::now()->format('Y-m-d-His').'.csv'));
        $stdout = $output === '-';
        if (! $stdout && ! is_dir(dirname($output)) && ! @mkdir(dirname($output), 0775, true)) {
            $this->error('Cannot create the folder for '.$output);

            return self::FAILURE;
        }
        $handle = $stdout ? fopen('php://stdout', 'w') : @fopen($output, 'w');
        if (! $handle) {
            $this->error('Cannot write '.$output);

            return self::FAILURE;
        }
        $rows = (new Exporter(! $this->option('no-variations')))->write($handle, $query);
        if (! $stdout) {
            fclose($handle);
            $this->info(number_format($rows).' rows written to '.$output);
        }

        return self::SUCCESS;
    }
}
