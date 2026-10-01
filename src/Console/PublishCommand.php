<?php

namespace Pine\Commerce\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Copies the package's public files into public/: the back-office assets (css/js/img/vendor) to
 * public/{commerce.admin.assets_url} and the product placeholder image to public/images/placeholder.png.
 *
 * Always COPIES (never symlinks): LiteSpeed does not follow symlinks out of public/. Run after every deploy /
 * package upgrade (commerce:install does it too).
 */
class PublishCommand extends Command
{
    protected $signature = 'commerce:publish
        {--force : Also overwrite public/images/placeholder.png}';

    protected $description = 'Copy the pine/commerce admin assets and placeholder image into public/';

    public function handle(Filesystem $files): int
    {
        $source = dirname(__DIR__, 2).'/resources/assets';
        $base = trim((string) config('commerce.admin.assets_url', 'vendor/commerce/admin'), '/');
        if ($base === '' || in_array(explode('/', $base)[0], ['storage', 'assets', 'build', 'themes'], true)) {
            $this->error("Refusing to publish the admin assets to public/{$base} (commerce.admin.assets_url).");

            return self::FAILURE;
        }

        $target = public_path($base);
        foreach ([$target, dirname($target)] as $dir) {
            if (is_link($dir)) {
                $this->error("public/{$base} is a symlink – LiteSpeed will not serve it. Remove the link and re-run.");

                return self::FAILURE;
            }
        }

        $files->ensureDirectoryExists($target);
        if (! $files->copyDirectory($source.'/admin', $target)) {
            $this->error("Could not copy the admin assets to {$target}.");

            return self::FAILURE;
        }
        $this->components->info("Admin assets copied to public/{$base}.");

        $placeholder = public_path('images/placeholder.png');
        if (! is_file($placeholder) || $this->option('force')) {
            $files->ensureDirectoryExists(dirname($placeholder));
            $files->copy($source.'/core/images/placeholder.png', $placeholder);
            $this->components->info('Placeholder image copied to public/images/placeholder.png.');
        }

        return self::SUCCESS;
    }
}
