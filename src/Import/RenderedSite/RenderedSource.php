<?php

namespace Pine\Commerce\Import\RenderedSite;

use Illuminate\Support\Facades\Http;

/**
 * Rendered HTML of source-site URLs (ARCHITECTURE.md §12.6): pre-rendered snapshot directories first (file name =
 * path with "/" → "__", "home.html" for the front page), else – only when a site URL is configured (--site-url) – an
 * HTTP GET of {site-url}/{path}/ (optional Host header --site-host), one request at a time with a 5 s timeout,
 * cached on disk under storage/app/import/rendered/{host}/. Nothing is fetched without --site-url.
 */
class RenderedSource
{
    private array $memo = [];

    public int $fetched = 0;

    public int $failed = 0;

    /** @param list<string> $snapshotDirs */
    public function __construct(
        private readonly array $snapshotDirs = [],
        private readonly ?string $siteUrl = null,
        private readonly ?string $siteHost = null,
        private readonly ?string $cacheDir = null,
        private readonly int $timeout = 5,
    ) {}

    public static function fileName(string $path): string
    {
        $path = trim($path, '/');

        return ($path === '' ? 'home' : str_replace('/', '__', $path)).'.html';
    }

    /** HTML of a snapshot file by name (e.g. 'home.html'), from the snapshot directories only. */
    public function file(string $file): ?string
    {
        foreach ($this->snapshotDirs as $dir) {
            $path = rtrim($dir, '/').'/'.$file;
            if (is_file($path)) {
                return file_get_contents($path);
            }
        }

        return null;
    }

    /** Rendered HTML of a source URL path ('' = home). */
    public function html(string $path): ?string
    {
        $path = trim($path, '/');
        if (array_key_exists($path, $this->memo)) {
            return $this->memo[$path];
        }
        $html = $this->file(self::fileName($path));
        if ($html === null && $this->siteUrl) {
            $html = $this->fetch($path);
        }

        return $this->memo[$path] = $html;
    }

    public function canFetch(): bool
    {
        return (bool) $this->siteUrl;
    }

    private function fetch(string $path): ?string
    {
        $cache = $this->cacheDir ? rtrim($this->cacheDir, '/').'/'.($this->siteHost ?: parse_url($this->siteUrl, PHP_URL_HOST)).'/'.self::fileName($path) : null;
        if ($cache && is_file($cache)) {
            return file_get_contents($cache);
        }
        $url = rtrim($this->siteUrl, '/').'/'.($path === '' ? '' : $path.'/');
        try {
            $request = Http::timeout($this->timeout)->withOptions(['allow_redirects' => false])
                ->withUserAgent('PineCommerceImporter/1.0');
            if ($this->siteHost) {
                $request = $request->withHeaders(['Host' => $this->siteHost]);
            }
            $response = $request->get($url);
            if (! $response->successful()) {
                $this->failed++;

                return null;
            }
            $html = $response->body();
        } catch (\Throwable) {
            $this->failed++;

            return null;
        }
        $this->fetched++;
        if ($cache) {
            @mkdir(dirname($cache), 0775, true);
            @file_put_contents($cache, $html);
        }

        return $html;
    }
}
