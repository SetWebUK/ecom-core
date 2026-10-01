<?php

namespace Pine\Commerce\Services\Admin\Catalogue\ProductCsv;

use RuntimeException;

/**
 * Reads an uploaded product CSV: UTF-8 (with or without BOM) or Windows-1252 (Excel "CSV" without UTF-8), comma,
 * semicolon or tab separated, quoted cells over several lines. Rows are read from a byte offset so an import can
 * stop after any row and carry on in the next request.
 *
 *   $info = CsvFile::inspect($path, $maxRows);   // headers, delimiter, data offset, row count, sample rows
 *   foreach (CsvFile::rows($path, $info['delimiter'], $offset, $line) as [$line, $cells, $nextOffset]) …
 */
class CsvFile
{
    public const SAMPLE_ROWS = 3;

    /**
     * @return array{headers: list<string>, delimiter: string, offset: int, rows: int, samples: list<list<string>>}
     */
    public static function inspect(string $path, int $maxRows): array
    {
        $handle = @fopen($path, 'r');
        if (! $handle) {
            throw new RuntimeException('The file could not be read.');
        }
        try {
            $bom = fread($handle, 3);
            $start = $bom === "\xEF\xBB\xBF" ? 3 : 0;
            fseek($handle, $start);
            $first = (string) fgets($handle);
            if (trim($first) === '') {
                throw new RuntimeException('The file is empty – the first row must hold the column names.');
            }
            $delimiter = static::delimiter($first);
            fseek($handle, $start);
            $headers = fgetcsv($handle, 0, $delimiter, '"', '');
            $headers = array_map(fn ($h) => static::text((string) $h), $headers ?: []);
            while ($headers && end($headers) === '') {
                array_pop($headers); // trailing empty header cells (Excel)
            }
            if (count(array_filter($headers, fn ($h) => $h !== '')) < 2) {
                throw new RuntimeException('The first row must hold the column names (at least two columns, e.g. “sku” and “name”).');
            }
            $offset = (int) ftell($handle);

            $rows = 0;
            $samples = [];
            while (($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                if (static::blank($cells)) {
                    continue;
                }
                if (++$rows > $maxRows) {
                    throw new RuntimeException('The file has more than '.number_format($maxRows).' rows – split it into smaller files.');
                }
                if (count($samples) < self::SAMPLE_ROWS) {
                    $samples[] = array_map(fn ($c) => static::text((string) $c), $cells);
                }
            }
        } finally {
            fclose($handle);
        }
        if ($rows === 0) {
            throw new RuntimeException('The file has no rows under the column names.');
        }

        return ['headers' => $headers, 'delimiter' => $delimiter, 'offset' => $offset, 'rows' => $rows, 'samples' => $samples];
    }

    /**
     * Data rows from $offset: yields [line number, cells (UTF-8, trimmed), offset after this row].
     * $line = the line number of the row before $offset (1 = the header row).
     *
     * @return \Generator<int, array{0:int, 1:list<string>, 2:int}>
     */
    public static function rows(string $path, string $delimiter, int $offset, int $line = 1): \Generator
    {
        $handle = @fopen($path, 'r');
        if (! $handle) {
            throw new RuntimeException('The uploaded file is no longer available – upload it again.');
        }
        try {
            fseek($handle, $offset);
            while (($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $line++;
                if (static::blank($cells)) {
                    continue;
                }
                yield [$line, array_map(fn ($c) => static::text((string) $c), $cells), (int) ftell($handle)];
            }
        } finally {
            fclose($handle);
        }
    }

    public static function delimiter(string $firstLine): string
    {
        $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /** UTF-8, trimmed; Windows-1252 text (Excel's plain "CSV") is converted. */
    public static function text(string $value): string
    {
        if ($value !== '' && ! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }

        return trim($value);
    }

    protected static function blank(array $cells): bool
    {
        return $cells === [null] || implode('', array_map(fn ($c) => trim((string) $c), $cells)) === '';
    }
}
