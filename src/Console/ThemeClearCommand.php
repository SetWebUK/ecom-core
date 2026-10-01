<?php

namespace Pine\Commerce\Console;

use Illuminate\Console\Command;
use Pine\Commerce\Theme\ThemeManager;

/** Remove the cached theme manifest – runs as part of `php artisan optimize:clear`. */
class ThemeClearCommand extends Command
{
    protected $signature = 'commerce:theme:clear';

    protected $description = 'Remove the cached storefront theme manifest';

    public function handle(ThemeManager $themes): int
    {
        $themes->clearCache();
        $this->components->info('Theme manifest cache cleared.');

        return self::SUCCESS;
    }
}
