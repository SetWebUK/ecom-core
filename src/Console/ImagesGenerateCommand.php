<?php

namespace Pine\Commerce\Console;

use Illuminate\Console\Command;
use Pine\Commerce\Models\Media;
use Pine\Commerce\Services\Media\ImageGenerator;
use Pine\Commerce\Services\Media\Images;

/**
 * (Re)build the size variants of commerce.images.sizes (+ WebP twins) for existing uploads, in chunks.
 *
 *   php artisan commerce:images:generate --missing --dry-run      what would be created
 *   php artisan commerce:images:generate --missing                fill the gaps (never replaces a file – safe to re-run)
 *   php artisan commerce:images:generate --size=card --path=uploads/2026/05
 *   php artisan commerce:images:generate --scan --path=uploads    files on disk without a media-library row too
 *
 * Originals are never modified or deleted, and a file that is a media-library item of its own is never written.
 * Without --missing existing variants are re-encoded from the original (asks first when interactive).
 */
class ImagesGenerateCommand extends Command
{
    protected $signature = 'commerce:images:generate
        {--missing : Only create variants that do not exist yet (existing files are never touched)}
        {--size=* : Only these sizes (names from commerce.images.sizes); repeatable}
        {--path= : Only originals in this folder (e.g. uploads/2026/05) or this one file}
        {--scan : Walk the image files on disk (under --path, default "uploads") instead of the media library}
        {--no-webp : Do not write WebP twins}
        {--dry-run : Only list what would be written}
        {--chunk=100 : Media rows loaded per query}
        {--limit=0 : Stop after this many originals (0 = all)}';

    protected $description = 'Generate the configured image sizes (and WebP twins) for existing uploads';

    /** @var array<string,int> */
    protected array $totals = [];

    protected int $originals = 0;

    public function handle(): int
    {
        $this->totals = [];
        $this->originals = 0;
        Images::flush(); // sizes may have changed since the last call in this process
        $sizes = Images::sizes();
        if (! $sizes) {
            $this->components->warn('No image sizes are configured (commerce.images.sizes).');

            return self::SUCCESS;
        }
        $only = array_values(array_filter((array) $this->option('size')));
        if ($unknown = array_diff($only, array_keys($sizes))) {
            $this->components->error('Unknown size(s): '.implode(', ', $unknown).'. Configured: '.implode(', ', array_keys($sizes)).'.');

            return self::FAILURE;
        }
        $path = trim((string) $this->option('path'), '/');
        if (str_contains($path, '..')) {
            $this->components->error('--path must be a folder or file on the public disk.');

            return self::FAILURE;
        }

        $generator = new ImageGenerator;
        if (! $generator->driver()) {
            $this->components->error('Neither the GD nor the Imagick PHP extension is available.');

            return self::FAILURE;
        }
        $missing = (bool) $this->option('missing');
        $dryRun = (bool) $this->option('dry-run');
        if (! $missing && ! $dryRun && $this->input->isInteractive()
            && ! $this->confirm('Without --missing, existing size variants are re-encoded from their originals (originals are never changed). Continue?', true)) {
            return self::FAILURE;
        }

        $this->components->info(sprintf('%s sizes %s with %s%s%s.', $dryRun ? 'Checking' : 'Generating',
            implode(', ', $only ?: array_keys($sizes)), $generator->driver()->name(),
            $missing ? ' (missing only)' : '', $this->option('no-webp') ? ', no WebP' : ''));

        $options = ['missing' => $missing, 'dry_run' => $dryRun, 'sizes' => $only ?: null];
        if ($this->option('no-webp')) {
            $options['webp'] = false;
        }
        $limit = max(0, (int) $this->option('limit'));

        foreach ($this->originals($path) as $original) {
            if ($limit && $this->originals >= $limit) {
                break;
            }
            $this->originals++;
            $results = $generator->generate($original, $options);
            foreach ($results as $result) {
                $this->totals[$result['status']] = ($this->totals[$result['status']] ?? 0) + 1;
                if ($result['status'] === 'failed' || $this->output->isVerbose()
                    || ($dryRun && str_starts_with($result['status'], 'would'))) {
                    $this->line(sprintf('  %-13s %-16s %s%s', $result['status'], $result['size'], $result['path'],
                        isset($result['message']) ? ' – '.$result['message'] : ''));
                }
            }
        }

        ksort($this->totals);
        $this->newLine();
        $this->table(['Originals', ...array_keys($this->totals)], [[$this->originals, ...array_values($this->totals)]]);
        if ($this->originals === 0) {
            $this->components->warn('No images found'.($path !== '' ? ' under '.$path : '').'.');
        }

        return ($this->totals['failed'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return iterable<string> relative paths of originals */
    protected function originals(string $path): iterable
    {
        $disk = Images::disk();
        if ($path !== '' && Images::isLocal($path) && $disk->exists($path) && ! is_dir($disk->path($path))) {
            yield $path;

            return;
        }

        if ($this->option('scan')) {
            $root = $path !== '' ? $path : 'uploads';
            $directories = [$root, ...$disk->allDirectories($root)];
            sort($directories);
            foreach ($directories as $dir) {
                $files = $disk->files($dir);
                $names = array_flip(array_map('basename', $files));
                sort($files);
                foreach ($files as $file) {
                    if (Images::isOriginal($file, $names) && Images::isLocal($file)) {
                        yield $file;
                    }
                }
            }

            return;
        }

        $query = Media::query()
            ->where('mime_type', 'like', 'image/%')
            ->where('mime_type', '!=', 'image/svg+xml')
            ->when($path !== '', fn ($q) => $q->where('path', 'like', addcslashes($path, '%_\\').'/%'));
        foreach ($query->lazyById(max(1, (int) $this->option('chunk')), 'id') as $media) {
            $file = ltrim((string) $media->path, '/');
            if (Images::isLocal($file)) {
                yield $file;
            }
        }
    }
}
