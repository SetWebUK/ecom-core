<?php

namespace Pine\Commerce\Exports;

use Symfony\Component\HttpFoundation\StreamedResponse;

/** Minimal streaming CSV writer (no extra packages), Excel-friendly (UTF-8 BOM). */
abstract class CsvExport
{
    /** @param  iterable<array<int, mixed>>  $rows */
    public static function stream(string $filename, array $headings, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headings, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headings, escape: '\\');
            foreach ($rows as $row) {
                fputcsv($out, array_map([static::class, 'cell'], $row), escape: '\\');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Neutralise spreadsheet formula injection in user-supplied values. */
    public static function cell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($value)) {
            return "'".$value;
        }

        return $value;
    }
}
