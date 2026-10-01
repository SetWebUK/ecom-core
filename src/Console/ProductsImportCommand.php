<?php

namespace Pine\Commerce\Console;

use Illuminate\Console\Command;
use Pine\Commerce\Services\Admin\Catalogue\ProductCsv\ImportRunner;
use Pine\Commerce\Services\Admin\Catalogue\ProductCsv\ImportSession;
use RuntimeException;

/**
 * Import a product CSV from the command line – the same importer as Admin › Products › Import / Export (columns
 * matched automatically by their headers; WooCommerce product exports are recognised).
 *
 *   php artisan commerce:products:import products.csv --dry-run                  what would happen, nothing saved
 *   php artisan commerce:products:import products.csv --update-existing --create-missing --download-images
 *   php artisan commerce:products:import woo-export.csv --update-existing --report=storage/app/import-report.csv
 *
 * Without --update-existing, rows matching an existing product (by SKU, or --match=id) are skipped.
 */
class ProductsImportCommand extends Command
{
    protected $signature = 'commerce:products:import
        {file : The CSV file}
        {--dry-run : Check every row and report what would change – nothing is saved}
        {--update-existing : Update products that already exist (default: skip them)}
        {--match=sku : Match existing products by "sku" or "id"}
        {--create-missing : Create missing categories, attributes and attribute values}
        {--download-images : Download image URLs from other sites into the media library}
        {--overwrite-empty : Empty cells clear the current value (default: they leave it unchanged)}
        {--chunk=100 : Rows per batch}
        {--report= : Write the per-row results (CSV) to this file}';

    protected $description = 'Import products from a CSV file (full product CSV or a WooCommerce product export)';

    public function handle(): int
    {
        if (! \Pine\Commerce\Support\Features::enabled('product_csv', false)) {
            $this->error('The product CSV is switched off (config commerce.features.product_csv).');

            return self::FAILURE;
        }
        $file = (string) $this->argument('file');
        if (! is_file($file) || ! is_readable($file)) {
            $this->error("Cannot read {$file}.");

            return self::FAILURE;
        }
        if (! in_array($this->option('match'), ['sku', 'id'], true)) {
            $this->error('--match must be "sku" or "id".');

            return self::FAILURE;
        }

        try {
            $session = ImportSession::create($file, basename($file), null, [
                'update_existing' => (bool) $this->option('update-existing'),
                'match_by' => (string) $this->option('match'),
                'create_missing' => (bool) $this->option('create-missing'),
                'download_images' => (bool) $this->option('download-images'),
                'empty_cells' => $this->option('overwrite-empty') ? 'overwrite' : 'skip',
            ]);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $mapped = array_filter($session->state['mapping']);
        if (! array_intersect($mapped, ['sku', 'id', 'name'])) {
            $session->delete();
            $this->error('No SKU, ID or Name column recognised in the header row.');

            return self::FAILURE;
        }
        $this->line(sprintf('%s: %s rows, %d of %d columns recognised%s.', basename($file), number_format($session->state['total']),
            count($mapped), count($session->state['headers']), $session->state['woocommerce'] ? ' (WooCommerce export)' : ''));
        $ignored = array_values(array_intersect_key($session->state['headers'], array_filter($session->state['mapping'], fn ($t) => ! $t)));
        if ($ignored && $this->output->isVerbose()) {
            $this->line('Ignored columns: '.implode(', ', $ignored));
        }

        $dryRun = (bool) $this->option('dry-run');
        $session->begin($dryRun ? 'preview' : 'importing');
        $bar = $this->output->createProgressBar((int) $session->state['total']);
        $status = (new ImportRunner($session))->run(fn ($status) => $bar->setProgress($status['processed']), max(1, (int) $this->option('chunk')));
        $bar->finish();
        $this->newLine(2);

        $counts = $status['counts'];
        $this->table(['', $dryRun ? 'Would be' : 'Rows'], [
            [$dryRun ? 'created' : 'Created', $counts['create']], [$dryRun ? 'updated' : 'Updated', $counts['update']],
            ['Skipped', $counts['skip']], ['Errors', $counts['error']], ['With warnings', $status['warnings']],
        ]);
        $shown = 0;
        foreach ($session->results(null, ['error']) as $row) {
            if (++$shown > 20) {
                $this->line('… see --report for the rest.');
                break;
            }
            $this->line("  line {$row['line']} ".($row['sku'] !== '' ? "[{$row['sku']}] " : '').implode('; ', $row['messages']));
        }

        if ($report = $this->option('report')) {
            $handle = @fopen((string) $report, 'w');
            if (! $handle) {
                $this->error("Cannot write {$report}.");

                return self::FAILURE;
            }
            fputcsv($handle, ['line', 'result', 'type', 'sku', 'name', 'id', 'messages'], ',', '"', '');
            foreach ($session->results() as $row) {
                fputcsv($handle, [$row['line'], $row['action'], $row['type'] ?? '', $row['sku'], $row['name'], $row['id'] ?? '', implode(' | ', $row['messages'])], ',', '"', '');
            }
            fclose($handle);
            $this->info("Report written to {$report}");
        }
        if ($dryRun) {
            $this->comment('Dry run – nothing was saved.');
        }

        return $counts['error'] > 0 && $counts['create'] + $counts['update'] + $counts['skip'] === 0 ? self::FAILURE : self::SUCCESS;
    }
}
