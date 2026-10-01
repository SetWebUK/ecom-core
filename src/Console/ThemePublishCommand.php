<?php

namespace Pine\Commerce\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Pine\Commerce\Theme\Theme;
use Pine\Commerce\Theme\ThemeManager;

/**
 * Copies theme assets (themes/{slug}/assets/**) to public/{public_path} – the active theme's chain by default, parents
 * first. Always COPIES (LiteSpeed does not follow symlinks out of public/). Files that no longer exist in the source
 * are only deleted with --prune. Writes public/{public_path}/.commerce-theme (slug, version, source hash) and refuses to
 * publish over a directory that holds another theme's marker.
 */
class ThemePublishCommand extends Command
{
    protected $signature = 'commerce:theme:publish
        {slug? : Theme to publish (default: the active theme and its parents)}
        {--all : Publish every installed theme}
        {--prune : Delete published files that no longer exist in the theme source}';

    protected $description = 'Copy storefront theme assets into public/';

    public const FORBIDDEN = ['admin-assets', 'vendor', 'storage', 'build', 'images', 'css', 'js', 'index.php', '.htaccess'];

    public function handle(ThemeManager $themes, Filesystem $files): int
    {
        if ($this->option('all')) {
            $targets = array_values($themes->all());
        } else {
            $slug = $this->argument('slug') ?: $themes->configuredSlug();
            if (! $themes->exists($slug)) {
                $this->error("Theme [{$slug}] is not installed.");

                return self::FAILURE;
            }
            $targets = array_reverse($themes->chain($slug)); // parents first
        }

        $status = self::SUCCESS;
        foreach ($targets as $theme) {
            if (! $this->publish($theme, $files)) {
                $status = self::FAILURE;
            }
        }

        return $status;
    }

    protected function publish(Theme $theme, Filesystem $files): bool
    {
        $source = $theme->assetsPath();
        $relative = $theme->publicPath();
        if (! is_dir($source)) {
            $this->components->warn("[{$theme->slug}] has no assets/ directory – nothing to publish.");

            return true;
        }
        if (in_array(explode('/', $relative)[0], self::FORBIDDEN, true) || str_contains($relative, '..')) {
            $this->error("[{$theme->slug}] public_path \"{$relative}\" is not allowed.");

            return false;
        }
        $target = public_path($relative);
        for ($dir = $target; strlen($dir) > strlen(public_path()); $dir = dirname($dir)) {
            if (is_link($dir)) {
                $this->error("public/{$relative} is (inside) a symlink – LiteSpeed will not serve it. Remove the link and re-run.");

                return false;
            }
        }
        $markerFile = $target.'/.commerce-theme';
        if (is_file($markerFile)) {
            $marker = json_decode((string) file_get_contents($markerFile), true);
            if (is_array($marker) && ($marker['slug'] ?? $theme->slug) !== $theme->slug) {
                $this->error("public/{$relative} holds the published assets of theme [{$marker['slug']}] – refusing to overwrite them with [{$theme->slug}].");

                return false;
            }
        }

        $files->ensureDirectoryExists($target);
        $copied = 0;
        $hash = hash_init('md5');
        $sourceFiles = [];
        foreach ($files->allFiles($source, true) as $file) {
            $rel = str_replace('\\', '/', $file->getRelativePathname());
            $sourceFiles[$rel] = true;
            $dest = $target.'/'.$rel;
            hash_update($hash, $rel."\0".md5_file($file->getPathname()));
            if (is_file($dest) && filesize($dest) === $file->getSize() && md5_file($dest) === md5_file($file->getPathname())) {
                continue; // unchanged: keep the file (and its mtime, which drives ?v= cache busting)
            }
            $files->ensureDirectoryExists(dirname($dest));
            if (! $files->copy($file->getPathname(), $dest)) {
                $this->error("Could not copy {$rel} to public/{$relative}.");

                return false;
            }
            @touch($dest, $file->getMTime());
            $copied++;
        }

        $pruned = 0;
        if ($this->option('prune')) {
            foreach ($files->allFiles($target, true) as $file) {
                $rel = str_replace('\\', '/', $file->getRelativePathname());
                if ($rel !== '.commerce-theme' && ! isset($sourceFiles[$rel])) {
                    $files->delete($file->getPathname());
                    $pruned++;
                }
            }
        }

        file_put_contents($markerFile, json_encode([
            'slug' => $theme->slug,
            'version' => $theme->version,
            'hash' => hash_final($hash),
            'published_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $this->components->info("[{$theme->slug}] ".count($sourceFiles)." files → public/{$relative} ({$copied} copied".($pruned ? ", {$pruned} pruned" : '').').');

        return true;
    }
}
