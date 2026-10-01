<?php

namespace Pine\Commerce\Services\Admin\Catalogue\ProductCsv;

use Illuminate\Support\Facades\Cache;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Services\Admin\Catalogue\ProductSaver;
use Pine\Commerce\Services\Admin\CatalogueTools;
use RuntimeException;
use Throwable;

/**
 * Runs an ImportSession in batches: every step() reads up to commerce.product_csv.chunk_size rows (hard cap
 * MAX_CHUNK) from where the last step stopped, within a time budget, and saves the progress – no queue worker needed.
 * The admin page calls it once per HTTP request; `commerce:products:import` loops over it.
 *
 * A step that died half-way (timeout) is recovered on the next one from the per-row results file (each result
 * carries the byte offset after its row), so rows are not imported twice.
 */
class ImportRunner
{
    public const MAX_CHUNK = 200;

    public function __construct(protected ImportSession $session) {}

    /** Process the next batch of the running pass. Returns the session status (+ 'busy' when another step holds the lock). */
    public function step(?int $rows = null, ?float $seconds = null): array
    {
        if (! $this->session->isRunning()) {
            return $this->session->status();
        }
        $lock = Cache::lock('commerce.product-import.'.$this->session->token, 300);
        if (! $lock->get()) {
            return $this->session->status() + ['busy' => true];
        }

        try {
            $fresh = ImportSession::find($this->session->token) ?? throw new RuntimeException('The import has expired.');
            $this->session->state = $fresh->state;
            if (! $this->session->isRunning()) {
                return $this->session->status();
            }
            $this->recover();

            $limit = max(1, min(self::MAX_CHUNK, $rows ?? (int) config('commerce.product_csv.chunk_size', 25)));
            $budget = $seconds ?? (float) config('commerce.product_csv.time_budget', 20);
            $started = microtime(true);
            $dryRun = $this->session->isDryRun();
            $importer = new RowImporter($this->session, $dryRun, new ImageImporter((bool) $this->session->option('download_images')));

            $progress = &$this->session->state['progress'];
            $done = 0;
            $finished = true;
            foreach (CsvFile::rows($this->session->absolutePath('source.csv'), $this->session->state['delimiter'], (int) $progress['offset'], (int) $progress['line']) as [$line, $cells, $offset]) {
                if ($done >= $limit || ($done > 0 && microtime(true) - $started > $budget)) {
                    $finished = false;
                    break;
                }
                $result = $importer->handle($line, $cells);
                $result['offset'] = $offset;
                $this->session->appendResults([$result]);
                $progress['offset'] = $offset;
                $progress['line'] = $line;
                $progress['processed']++;
                $progress['counts'][$result['action']] = ($progress['counts'][$result['action']] ?? 0) + 1;
                if ($result['action'] !== 'error' && $result['messages']) {
                    $progress['warnings']++;
                }
                $done++;
            }

            if (! $dryRun) {
                $this->afterBatch($importer);
            }
            if ($finished) {
                $progress['finished_at'] = now()->toIso8601String();
                if ($dryRun) {
                    $this->session->state['preview'] = ['counts' => $progress['counts'], 'warnings' => $progress['warnings'], 'processed' => $progress['processed']];
                    $this->session->state['phase'] = 'previewed';
                } else {
                    $this->session->state['phase'] = 'done';
                    $this->session->state['result'] = ['counts' => $progress['counts'], 'warnings' => $progress['warnings'], 'processed' => $progress['processed']];
                }
            }
            unset($progress);
            $this->session->save();

            return $this->session->status();
        } finally {
            $lock->release();
        }
    }

    /** Run the pass to the end (CLI). $tick is called after every batch with the status. */
    public function run(?callable $tick = null, ?int $rows = null): array
    {
        do {
            $status = $this->step($rows, 60.0);
            if ($tick) {
                $tick($status);
            }
        } while ($status['running'] && empty($status['busy']));

        return $status;
    }

    /** Parents of written variants get their price/stock refreshed; caches flushed; back-in-stock emails sent. */
    protected function afterBatch(RowImporter $importer): void
    {
        foreach (array_keys($importer->variableParents) as $id) {
            if ($product = Product::query()->find($id)) {
                ProductSaver::refreshVariable($product);
                $before = $importer->restockCheck[$id] ?? null;
                if ($before !== null && ProductSaver::becameAvailable($before, ProductSaver::availability($product->refresh()))) {
                    $importer->restocked[] = $id;
                }
            }
        }
        if ($importer->touched) {
            CatalogueTools::flushStorefrontCaches();
        }
        if ($importer->restocked) {
            try {
                CatalogueTools::notifyBackInStock(array_values(array_unique($importer->restocked)));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** Rows written by a step that stopped before saving its progress: skip past them (last result's offset). */
    protected function recover(): void
    {
        $progress = &$this->session->state['progress'];
        $tail = $this->session->lastResult();
        if (! $tail || (int) ($tail['offset'] ?? 0) <= (int) $progress['offset']) {
            return; // the usual case: the last step saved its progress
        }
        $last = null;
        $count = 0;
        $counts = ['create' => 0, 'update' => 0, 'skip' => 0, 'error' => 0];
        $warnings = 0;
        foreach ($this->session->results() as $row) {
            if ((int) ($row['offset'] ?? 0) > (int) $progress['offset']) {
                foreach ((array) ($row['refs'] ?? []) as $key) { // parent references of the rows written by the lost batch
                    if (isset($row['ref'])) {
                        $progress['refs'][$key] = $row['ref'];
                    }
                }
            }
            $last = $row;
            $count++;
            $counts[$row['action']] = ($counts[$row['action']] ?? 0) + 1;
            if (($row['action'] ?? '') !== 'error' && ! empty($row['messages'])) {
                $warnings++;
            }
        }
        if ($last && (int) ($last['offset'] ?? 0) > (int) $progress['offset']) {
            $progress['offset'] = (int) $last['offset'];
            $progress['line'] = (int) $last['line'];
            $progress['processed'] = $count;
            $progress['counts'] = $counts;
            $progress['warnings'] = $warnings;
        }
        unset($progress);
    }
}
