<?php

namespace Pine\Commerce\Import\Steps;

use Illuminate\Support\Facades\Storage;
use Pine\Commerce\Import\ImportContext;

/**
 * --copy-uploads: copy wp-content/uploads (or --uploads-path) into the public disk under uploads/ – COPIES, never
 * symlinks (LiteSpeed does not follow symlinks out of public/). Existing files with the same size are verified and
 * skipped. Executable/server files (php, phtml, .htaccess …) are quarantined to storage/app/private/quarantine-uploads,
 * private directories (WooCommerce downloadable files, logs) go to storage/app/private/wp-uploads, and directories in
 * `commerce-import.media.skip_dirs` are skipped. In --dry-run nothing is copied, only counted.
 */
class MediaFilesStep extends AbstractStep
{
    public const QUARANTINE_EXTENSIONS = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps', 'pl', 'py', 'cgi',
        'sh', 'bash', 'exe', 'dll', 'so', 'asp', 'aspx', 'jsp', 'htaccess', 'htpasswd', 'ini', 'user.ini'];

    public const PRIVATE_DIRS = ['woocommerce_uploads', 'wc-logs', 'wpforms', 'gravity_forms', 'db7_forms'];

    public const SKIP_DIRS = ['cache', 'backup', 'backups', 'updraft', 'ai1wm-backups', 'backwpup', 'wp-staging', 'et-cache',
        'jet-engine', 'wpcode', 'shortpixel-backups', 'ShortpixelBackups', 'smush-webp', 'wflogs'];

    public function key(): string
    {
        return 'media.files';
    }

    public function section(): string
    {
        return 'media';
    }

    public function shouldRun(ImportContext $ctx): bool
    {
        return ! empty($ctx->options['copy_uploads']);
    }

    public static function sourceDir(ImportContext $ctx): ?string
    {
        $dir = $ctx->options['uploads_path'] ?? null;
        if (! $dir && ! empty($ctx->options['wp_path'])) {
            $uploadPath = trim((string) $ctx->wp->option('upload_path', ''));
            $dir = $uploadPath !== '' && str_starts_with($uploadPath, '/') ? $uploadPath
                : rtrim($ctx->options['wp_path'], '/').'/'.($uploadPath !== '' ? $uploadPath : 'wp-content/uploads');
        }

        return $dir && is_dir($dir) ? rtrim($dir, '/') : null;
    }

    protected function import(): void
    {
        $source = self::sourceDir($this->ctx);
        if (! $source) {
            $this->ctx->warn('--copy-uploads: uploads directory not found (pass --uploads-path or --wp-path).');
            $this->ctx->count('Upload files', '—', 0, 'uploads directory not found');

            return;
        }
        $disk = Storage::disk((string) ($this->ctx->config('media.disk') ?? 'public'));
        $target = rtrim($disk->path('uploads'), '/');
        $quarantine = rtrim((string) ($this->ctx->config('media.quarantine_path') ?? storage_path('app/private/quarantine-uploads')), '/');
        $private = rtrim((string) ($this->ctx->config('media.private_path') ?? storage_path('app/private/wp-uploads')), '/');
        $skip = array_flip(array_merge(self::SKIP_DIRS, (array) $this->ctx->config('media.skip_dirs', [])));
        $privateDirs = array_flip(array_merge(self::PRIVATE_DIRS, (array) $this->ctx->config('media.private_dirs', [])));

        $stats = ['scanned' => 0, 'copied' => 0, 'verified' => 0, 'quarantined' => 0, 'private' => 0, 'skipped' => 0, 'failed' => 0, 'bytes' => 0];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $file) use ($skip, $source, &$stats) {
                    if ($file->isLink()) {
                        $stats['skipped']++;

                        return false; // never follow or copy symlinks
                    }
                    if ($file->isDir()) {
                        $rel = ltrim(substr($file->getPathname(), strlen($source)), '/');
                        if (isset($skip[$rel]) || isset($skip[$file->getFilename()]) && substr_count($rel, '/') === 0) {
                            $stats['skipped']++;

                            return false;
                        }
                    }

                    return true;
                }),
            \RecursiveIteratorIterator::LEAVES_ONLY);

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }
            $stats['scanned']++;
            $rel = ltrim(substr($file->getPathname(), strlen($source)), '/');
            $top = explode('/', $rel)[0];
            $name = strtolower($file->getFilename());
            $ext = strtolower($file->getExtension());
            if (in_array($ext, self::QUARANTINE_EXTENSIONS, true) || str_starts_with($name, '.ht') || $name === '.user.ini') {
                $dest = $quarantine.'/'.$rel;
                $stats['quarantined']++;
            } elseif (isset($privateDirs[$top])) {
                $dest = $private.'/'.$rel;
                $stats['private']++;
            } else {
                $dest = $target.'/'.$rel;
            }
            if (is_file($dest) && filesize($dest) === $file->getSize()) {
                $stats['verified']++;

                continue;
            }
            if ($this->ctx->dryRun) {
                $stats['copied']++;
                $stats['bytes'] += $file->getSize();

                continue;
            }
            if (! is_dir(dirname($dest)) && ! @mkdir(dirname($dest), 0775, true) && ! is_dir(dirname($dest))) {
                $stats['failed']++;
                $this->ctx->warn('Cannot create directory for '.$dest);

                continue;
            }
            if (@copy($file->getPathname(), $dest)) {
                @touch($dest, $file->getMTime());
                $stats['copied']++;
                $stats['bytes'] += $file->getSize();
            } else {
                $stats['failed']++;
                $this->ctx->warn('Could not copy upload '.$rel);
            }
        }
        if ($stats['quarantined']) {
            $this->ctx->warn($stats['quarantined'].' executable/server file(s) in uploads were quarantined to '.str_replace(base_path().'/', '', $quarantine).' (never served).');
        }
        $this->ctx->forgetAttachments();
        $this->ctx->count('Upload files', $stats['scanned'], $stats['copied'] + $stats['verified'],
            sprintf('%s %d (%.1f MB), verified %d, private %d, quarantined %d, skipped dirs/links %d, failed %d',
                $this->ctx->dryRun ? 'would copy' : 'copied', $stats['copied'], $stats['bytes'] / 1048576, $stats['verified'],
                $stats['private'], $stats['quarantined'], $stats['skipped'], $stats['failed']));
    }
}
