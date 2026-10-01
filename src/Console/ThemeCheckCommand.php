<?php

namespace Pine\Commerce\Console;

use Illuminate\Console\Command;
use Pine\Commerce\Theme\ThemeContract;
use Pine\Commerce\Theme\ThemeManager;

/**
 * Validate a theme: theme.json, parent chain, public_path, Theme.php class, and that every view of the theme contract
 * (docs/ARCHITECTURE.md §8 – required rows + the rows of the features it "supports") resolves through the
 * theme's chain. Non-zero exit on failure.
 */
class ThemeCheckCommand extends Command
{
    protected $signature = 'commerce:theme:check {slug? : Theme to check (default: the active theme)}';

    protected $description = 'Validate a storefront theme against the theme contract';

    public function handle(ThemeManager $themes): int
    {
        $slug = $this->argument('slug') ?: $themes->configuredSlug();
        $problems = ThemeContract::check($themes, $slug);
        if ($problems === []) {
            $chain = implode(' → ', array_map(fn ($t) => $t->slug, $themes->chain($slug)));
            $this->components->info("Theme [{$slug}] is valid (chain: {$chain}).");

            return self::SUCCESS;
        }
        $this->components->error("Theme [{$slug}] has ".count($problems).' problem(s):');
        foreach ($problems as $problem) {
            $this->line('  - '.$problem);
        }

        return self::FAILURE;
    }
}
