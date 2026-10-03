<?php

namespace Pine\Commerce\Import\WooApi;

use RuntimeException;

/**
 * The log file of one API import run ({commerce.woo_api.path}/runs/run-{id}.log, never under public/): one line per
 * event, "[2026-10-03 10:00:00] LEVEL message", secrets masked. Read back by the progress page (from a byte offset)
 * and downloadable from Admin › Import.
 */
class RunLog
{
    public function __construct(public readonly string $file, private readonly ?Connection $connection = null) {}

    public static function directory(): string
    {
        $base = rtrim((string) (config('commerce.woo_api.path') ?: storage_path('app/private/woo-api-import')), '/');
        $public = rtrim(public_path(), '/').'/';
        if (str_starts_with($base.'/', $public)) {
            throw new RuntimeException('commerce.woo_api.path must not be inside public/.');
        }

        return $base;
    }

    public static function for(int $runId, ?Connection $connection = null): self
    {
        $dir = self::directory().'/runs';
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir}.");
        }

        return new self($dir.'/run-'.$runId.'.log', $connection);
    }

    public function info(string $message): void
    {
        $this->write('INFO', $message);
    }

    public function warning(string $message): void
    {
        $this->write('WARN', $message);
    }

    public function error(string $message): void
    {
        $this->write('ERROR', $message);
    }

    public function debug(string $message): void
    {
        $this->write('HTTP', $message);
    }

    public function write(string $level, string $message): void
    {
        if ($this->connection) {
            $message = $this->connection->mask($message);
        }
        $line = '['.now()->format('Y-m-d H:i:s').'] '.str_pad($level, 5).' '.str_replace(["\r", "\n"], ' ', $message)."\n";
        @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
    }

    /** Lines from byte $offset on, and the next offset. @return array{0:list<string>, 1:int} */
    public function tail(int $offset = 0, int $maxBytes = 65536): array
    {
        if (! is_file($this->file)) {
            return [[], 0];
        }
        $size = filesize($this->file) ?: 0;
        if ($offset > $size) {
            $offset = 0;
        }
        if ($size - $offset > $maxBytes) {
            $offset = $size - $maxBytes; // only the last part on first load
        }
        $handle = fopen($this->file, 'rb');
        fseek($handle, $offset);
        $chunk = (string) stream_get_contents($handle);
        fclose($handle);
        $end = strrpos($chunk, "\n");
        if ($end === false) {
            return [[], $offset];
        }
        $complete = substr($chunk, 0, $end);
        $lines = explode("\n", $complete);
        if ($offset > 0 && $offset === $size - $maxBytes) {
            array_shift($lines); // probably cut in the middle
        }

        return [array_values(array_filter($lines, fn ($l) => $l !== '')), $offset + $end + 1];
    }
}
