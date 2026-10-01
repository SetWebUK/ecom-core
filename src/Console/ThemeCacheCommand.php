<?php

namespace Pine\Commerce\Console;

use Illuminate\Console\Command;
use Pine\Commerce\Theme\ThemeManager;

/** Cache the theme manifest (every theme.json + config/*.php) – runs as part of `php artisan optimize`. */
class ThemeCacheCommand extends Command
{
    protected $signature = 'commerce:theme:cache';

    protected $description = 'Cache the storefront theme manifest (bootstrap/cache/commerce-themes.php)';

    public function handle(ThemeManager $themes): int
    {
        $file = $themes->writeCache();
        $this->components->info('Theme manifest cached ('.count($themes->manifests()).' themes): '.str_replace(base_path().'/', '', $file));

        return self::SUCCESS;
    }
}
