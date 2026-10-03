<?php

namespace Pine\Commerce\Import\WooApi;

use Pine\Commerce\Models\Setting;

/**
 * Is the API shop the site this shop was already imported from by the database importer
 * (setting import.wordpress.site_url, written by commerce:import-wordpress since 1.5)? Then the API import should
 * update those rows (source "same site": no import_source) instead of adding a second copy.
 */
final class SameSite
{
    public static function importedSiteUrl(): ?string
    {
        try {
            $url = Setting::get('import.wordpress.site_url');
        } catch (\Throwable) {
            return null;
        }

        return is_string($url) && $url !== '' ? $url : null;
    }

    public static function matches(Connection $connection): bool
    {
        $imported = self::importedSiteUrl();
        if (! $imported) {
            return false;
        }
        $host = fn (string $url) => preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));

        return $host($imported) !== '' && $host($imported) === $host($connection->url);
    }
}
