<?php

namespace Pine\Commerce\Services\Admin\Catalogue\ProductCsv;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One product import, kept on the private "local" disk under commerce/product-imports/{token}/ so it survives
 * between the batched requests that drive it (and can be resumed after a closed tab or a timeout):
 *
 *   source.csv          the uploaded file
 *   state.json          headers, mapping, options, phase, progress (byte offset + line), counts, parent references
 *   preview.jsonl       one result per row of the dry run
 *   import.jsonl        one result per row of the real run (the downloadable report)
 *
 * Phases: mapping → preview (dry run running) → previewed → importing → done.
 */
class ImportSession
{
    public const DIR = 'commerce/product-imports';

    public const PHASES = ['mapping', 'preview', 'previewed', 'importing', 'done'];

    public array $state;

    protected function __construct(public readonly string $token, array $state)
    {
        $this->state = $state;
    }

    public static function disk(): Filesystem
    {
        return Storage::disk((string) config('commerce.product_csv.disk', 'local'));
    }

    /** New session from an uploaded/local file (validated with CsvFile::inspect). */
    public static function create(string $sourcePath, string $filename, ?int $userId, array $options = []): static
    {
        static::prune();
        $token = Str::random(32);
        $dir = self::DIR.'/'.$token;
        $disk = static::disk();
        $stream = fopen($sourcePath, 'r');
        if (! $stream || ! $disk->writeStream($dir.'/source.csv', $stream)) {
            throw new RuntimeException('The file could not be stored – please try again.');
        }
        if (is_resource($stream)) {
            fclose($stream);
        }

        try {
            $info = CsvFile::inspect($disk->path($dir.'/source.csv'), static::maxRows());
        } catch (RuntimeException $e) {
            $disk->deleteDirectory($dir);
            throw $e;
        }

        $session = new static($token, [
            'token' => $token,
            'filename' => Str::limit($filename, 120),
            'user_id' => $userId,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'headers' => $info['headers'],
            'samples' => $info['samples'],
            'delimiter' => $info['delimiter'],
            'data_offset' => $info['offset'],
            'total' => $info['rows'],
            'woocommerce' => Columns::looksLikeWooCommerce($info['headers']),
            'mapping' => Columns::autoMap($info['headers']),
            'options' => array_replace(static::defaultOptions(), $options),
            'phase' => 'mapping',
            'progress' => null,
        ]);
        $session->save();

        return $session;
    }

    public static function defaultOptions(): array
    {
        return [
            'update_existing' => true,
            'match_by' => 'sku',        // sku | id
            'create_missing' => true,   // categories, attributes and attribute values
            'download_images' => true,  // image URLs → media library
            'empty_cells' => 'skip',    // skip = leave the current value | overwrite = clear it
        ];
    }

    public static function find(string $token): ?static
    {
        if (preg_match('/^[A-Za-z0-9]{32}$/', $token) !== 1) {
            return null;
        }
        $json = static::disk()->get(self::DIR.'/'.$token.'/state.json');
        $state = is_string($json) ? json_decode($json, true) : null;

        return is_array($state) ? new static($token, $state) : null;
    }

    /** The newest sessions (for the "recent imports" list). @return list<static> */
    public static function recent(int $limit = 10): array
    {
        $sessions = [];
        foreach (static::disk()->directories(self::DIR) as $dir) {
            if ($session = static::find(basename($dir))) {
                $sessions[] = $session;
            }
        }
        usort($sessions, fn ($a, $b) => strcmp((string) $b->state['created_at'], (string) $a->state['created_at']));

        return array_slice($sessions, 0, $limit);
    }

    /** Remove sessions older than commerce.product_csv.keep_days (default 14). */
    public static function prune(): void
    {
        $cutoff = now()->subDays(max(1, (int) config('commerce.product_csv.keep_days', 14)))->getTimestamp();
        $disk = static::disk();
        foreach ($disk->directories(self::DIR) as $dir) {
            $state = $dir.'/state.json';
            $time = $disk->exists($state) ? $disk->lastModified($state) : 0;
            if ($time < $cutoff) {
                $disk->deleteDirectory($dir);
            }
        }
    }

    public static function maxRows(): int
    {
        return max(1, (int) config('commerce.product_csv.max_rows', 10000));
    }

    public function save(): void
    {
        $this->state['updated_at'] = now()->toIso8601String();
        static::disk()->put($this->path('state.json'), json_encode($this->state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function delete(): void
    {
        static::disk()->deleteDirectory(self::DIR.'/'.$this->token);
    }

    public function path(string $file): string
    {
        return self::DIR.'/'.$this->token.'/'.$file;
    }

    public function absolutePath(string $file): string
    {
        return static::disk()->path($this->path($file));
    }

    public function phase(): string
    {
        return (string) $this->state['phase'];
    }

    public function option(string $key): mixed
    {
        return $this->state['options'][$key] ?? static::defaultOptions()[$key] ?? null;
    }

    /** Start (or restart) a pass over the file: 'preview' (dry run) or 'importing'. */
    public function begin(string $phase): void
    {
        $this->state['phase'] = $phase;
        $this->state['progress'] = [
            'offset' => (int) $this->state['data_offset'],
            'line' => 1,
            'processed' => 0,
            'counts' => ['create' => 0, 'update' => 0, 'skip' => 0, 'error' => 0],
            'warnings' => 0,
            'refs' => [],          // "id:123" / "sku:ABC" => product id, or "new:{line}" (dry run)
            'variation_refs' => [],
            'images' => [],        // sha1(url) => media path (downloaded during this run)
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
        ];
        static::disk()->put($this->path($this->resultsFile()), '');
        $this->save();
    }

    public function isRunning(): bool
    {
        return in_array($this->phase(), ['preview', 'importing'], true) && empty($this->state['progress']['finished_at']);
    }

    public function isDryRun(): bool
    {
        return in_array($this->phase(), ['preview', 'previewed'], true);
    }

    /** preview.jsonl during/after the dry run, import.jsonl during/after the real run. */
    public function resultsFile(?string $phase = null): string
    {
        return in_array($phase ?? $this->phase(), ['preview', 'previewed', 'mapping'], true) ? 'preview.jsonl' : 'import.jsonl';
    }

    /** @param list<array> $results */
    public function appendResults(array $results): void
    {
        if (! $results) {
            return;
        }
        $lines = '';
        foreach ($results as $result) {
            $lines .= json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
        }
        file_put_contents($this->absolutePath($this->resultsFile()), $lines, FILE_APPEND | LOCK_EX);
    }

    /**
     * Results of a pass, optionally only some actions.
     *
     * @return \Generator<int, array>
     */
    public function results(?string $phase = null, ?array $actions = null): \Generator
    {
        $path = $this->absolutePath($this->resultsFile($phase));
        if (! is_file($path)) {
            return;
        }
        $handle = fopen($path, 'r');
        try {
            while (($line = fgets($handle)) !== false) {
                $row = json_decode($line, true);
                if (is_array($row) && ($actions === null || in_array($row['action'] ?? null, $actions, true))) {
                    yield $row;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /** The last result written in the current pass (read from the end of the file). */
    public function lastResult(): ?array
    {
        $path = $this->absolutePath($this->resultsFile());
        if (! is_file($path) || ($size = filesize($path)) === 0) {
            return null;
        }
        $handle = fopen($path, 'r');
        try {
            $chunk = '';
            for ($read = 0; $read < $size;) {
                $step = min(65536, $size - $read);
                $read += $step;
                fseek($handle, $size - $read);
                $chunk = fread($handle, $step).$chunk;
                $lines = preg_split('/\n/', rtrim($chunk, "\n"));
                if (count($lines) > 1 || $read >= $size) {
                    $row = json_decode((string) end($lines), true);

                    return is_array($row) ? $row : null;
                }
            }
        } finally {
            fclose($handle);
        }

        return null;
    }

    /** Percent of rows processed in the current pass. */
    public function percent(): int
    {
        $total = max(1, (int) $this->state['total']);

        return (int) min(100, floor(((int) ($this->state['progress']['processed'] ?? 0)) * 100 / $total));
    }

    /** JSON the run page polls. */
    public function status(): array
    {
        $progress = $this->state['progress'] ?? [];

        return [
            'phase' => $this->phase(),
            'running' => $this->isRunning(),
            'total' => (int) $this->state['total'],
            'processed' => (int) ($progress['processed'] ?? 0),
            'percent' => $this->percent(),
            'counts' => $progress['counts'] ?? ['create' => 0, 'update' => 0, 'skip' => 0, 'error' => 0],
            'warnings' => (int) ($progress['warnings'] ?? 0),
        ];
    }

    public function ownedBy(?object $user): bool
    {
        if (! $user) {
            return false;
        }

        return (int) ($this->state['user_id'] ?? 0) === (int) $user->getKey() || (method_exists($user, 'isAdmin') && $user->isAdmin());
    }
}
