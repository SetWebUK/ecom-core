<?php

namespace Pine\Commerce\Updater;

use Pine\Commerce\Support\Features;
use RuntimeException;

/**
 * Admin › Updates (since 1.3): paths and switches of the platform updater (config commerce.updater, feature switch
 * "updater"). See docs/PLAYBOOK.md part 3.6.
 */
final class Updater
{
    public const PACKAGE = 'pine/commerce';

    public static function enabled(): bool
    {
        return Features::enabled('updater', false);
    }

    /** The client project (composer.json, composer.lock, vendor/, artisan). */
    public static function projectPath(): string
    {
        return rtrim((string) (config('commerce.updater.project_path') ?: base_path()), '/');
    }

    /** Working directory of the updater: lock, run logs, skeleton checkouts (never under public/). */
    public static function workPath(string $path = ''): string
    {
        $base = rtrim((string) (config('commerce.updater.path') ?: storage_path('app/private/updater')), '/');

        return $path === '' ? $base : $base.'/'.ltrim($path, '/');
    }

    /** Where database backups go (config commerce.updater.backup_path; default {workPath}/backups). */
    public static function backupPath(): string
    {
        return rtrim((string) (config('commerce.updater.backup_path') ?: static::workPath('backups')), '/');
    }

    /** Refuse paths inside public/ (backups and checkouts must never be downloadable). */
    public static function assertPrivate(string $path): void
    {
        $public = rtrim(public_path(), '/').'/';
        if (str_starts_with(rtrim($path, '/').'/', $public)) {
            throw new RuntimeException("{$path} is inside public/ – choose a directory the web server does not serve.");
        }
    }

    public static function ensureDirectory(string $path): string
    {
        if (! is_dir($path) && ! @mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException("Cannot create {$path}.");
        }

        return $path;
    }
}
