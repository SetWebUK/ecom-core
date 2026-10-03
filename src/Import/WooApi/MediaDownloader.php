<?php

namespace Pine\Commerce\Import\WooApi;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Pine\Commerce\Services\Admin\Catalogue\ProductCsv\ImageImporter;
use Pine\Commerce\Services\Admin\LocalTime;
use Pine\Commerce\Import\Support\Upserter;
use Throwable;

/**
 * Downloads the shop's images into the public disk once:
 *
 *  - a WordPress upload (".../wp-content/uploads/2025/01/shirt.jpg") keeps its path ("uploads/2025/01/shirt.jpg"),
 *    exactly where the database importer's --copy-uploads puts it, so links rewritten in page content resolve;
 *    other URLs go to uploads/YYYY/MM/;
 *  - deduplicated by source URL (media.source_hash = sha1(url)) and by content (an identical file already at the
 *    path is reused; a different file gets a "-1" name);
 *  - images only (JPG, PNG, GIF, WebP, AVIF – checked from the bytes, not the extension), at most
 *    commerce.woo_api.max_image_kb, redirects followed (max 3) through the SSRF guard;
 *  - then processed like an admin upload (orientation, max size, the core image sizes) and recorded in the media
 *    library (wp_id = the WordPress attachment id when known, import_source = the run's source).
 */
class MediaDownloader
{
    public int $downloaded = 0;

    public int $reused = 0;

    /** sha1(url) => path, for this process */
    private array $memo = [];

    /** @param (callable(string):void)|null $log */
    public function __construct(private readonly Connection $connection, private readonly Upserter $upserter, private readonly bool $dryRun = false, private $log = null) {}

    /**
     * Public-disk path of the image at $url (downloaded when needed), or null with the reason in $error.
     */
    public function fetch(string $url, ?int $attachmentId = null, ?string $alt = null, ?string $title = null, ?string &$error = null): ?string
    {
        $error = null;
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            $error = 'not an http(s) URL';

            return null;
        }
        $hash = sha1($url);
        if (isset($this->memo[$hash])) {
            return $this->memo[$hash];
        }
        if ($known = $this->known($hash)) {
            $this->reused++;

            return $this->memo[$hash] = $known;
        }
        if ($this->dryRun) {
            return $this->memo[$hash] = $this->targetPath($url, 'jpg');
        }
        try {
            [$body, $mime, $size] = $this->download($url);
        } catch (Throwable $e) {
            $error = $this->connection->mask($e->getMessage());

            return null;
        }
        $extension = ImageImporter::EXTENSIONS[$mime];
        $path = $this->targetPath($url, $extension);
        $disk = Storage::disk('public');
        // same path already taken: identical bytes → reuse, else a free "-1", "-2" … name
        for ($i = 1; $disk->exists($path); $i++) {
            if (sha1_file($disk->path($path)) === sha1($body)) {
                $this->record($path, $hash, $attachmentId, $alt, $title, $mime, null);
                $this->reused++;

                return $this->memo[$hash] = $path;
            }
            $path = preg_replace('/(?:-\d+)?\.(\w+)$/', '-'.$i.'.$1', $path, 1);
        }
        if (! $disk->put($path, $body)) {
            $error = 'could not be saved';

            return null;
        }
        ImageImporter::optimise($path, $extension); // orientation, max size, the core image sizes
        $absolute = $disk->path($path);
        clearstatcache(true, $absolute);
        $stored = @getimagesize($absolute) ?: $size;
        $this->record($path, $hash, $attachmentId, $alt, $title, $stored['mime'] ?? $mime, $stored);
        $this->downloaded++;

        return $this->memo[$hash] = $path;
    }

    /** "…/wp-content/uploads/2025/01/a.jpg" → "uploads/2025/01/a.jpg"; anything else → "uploads/YYYY/MM/{name}.{ext}". */
    public function targetPath(string $url, string $extension): string
    {
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
        if (preg_match('#/wp-content/uploads/(.+)$#', $path, $m) && preg_match('/^(?!.*\.\.)[A-Za-z0-9][A-Za-z0-9\/_\-.() ]*\.(jpe?g|png|gif|webp|avif)$/i', $m[1])) {
            return 'uploads/'.$m[1];
        }
        $name = Str::limit(Str::slug(pathinfo($path, PATHINFO_FILENAME)), 80, '') ?: 'image';

        return 'uploads/'.LocalTime::now()->format('Y/m').'/'.$name.'.'.$extension;
    }

    protected function known(string $hash): ?string
    {
        if (! ImageImporter::tracksSources()) {
            return null;
        }
        $path = DB::table('media')->where('source_hash', $hash)->orderBy('id')->value('path');

        return $path && Storage::disk('public')->exists($path) ? $path : null;
    }

    /** @return array{0:string, 1:string, 2:array} body, mime, getimagesize() */
    protected function download(string $url): array
    {
        $maxBytes = max(1, (int) config('commerce.woo_api.max_image_kb', 10240)) * 1024;
        $allowPrivate = (bool) config('commerce.woo_api.allow_private_hosts', false);
        $response = null;
        for ($hop = 0; $hop < 4; $hop++) {
            $target = UrlGuard::check($url, $allowPrivate);
            $options = ['allow_redirects' => false, 'verify' => $this->connection->verifyTls,
                // stop a download as soon as it passes the size limit (not only after it arrived)
                'progress' => function ($total, $downloaded) use ($maxBytes) {
                    if ($downloaded > $maxBytes || $total > $maxBytes) {
                        throw new WooApiException('larger than '.self::size($maxBytes), 'invalid');
                    }
                }];
            if ($target['ip'] && defined('CURLOPT_RESOLVE')) {
                $ip = str_contains($target['ip'], ':') ? '['.$target['ip'].']' : $target['ip'];
                $options['curl'] = [CURLOPT_RESOLVE => [$target['host'].':'.$target['port'].':'.$ip]];
            }
            try {
                $response = Http::timeout(max(1, (int) config('commerce.woo_api.image_timeout', 30)))
                    ->withOptions($options)
                    ->withHeaders(['User-Agent' => (string) config('commerce.woo_api.user_agent', 'PineCommerce-WooImport/1.0'), 'Accept' => 'image/*'])
                    ->get($url);
            } catch (Throwable $e) {
                for ($cause = $e; $cause; $cause = $cause->getPrevious()) {
                    if ($cause instanceof WooApiException) {
                        throw $cause; // our own size-limit abort, wrapped by Guzzle
                    }
                }
                throw new WooApiException('download failed ('.mb_substr(preg_replace('#https?://\S+#', '(url)', $e->getMessage()) ?? '', 0, 200).')', 'network');
            }
            if ($response->redirect() && ($location = $response->header('Location'))) {
                $url = str_starts_with($location, 'http') ? $location
                    : (string) \GuzzleHttp\Psr7\UriResolver::resolve(new \GuzzleHttp\Psr7\Uri($url), new \GuzzleHttp\Psr7\Uri($location));

                continue;
            }
            break;
        }
        if (! $response || ! $response->successful()) {
            throw new WooApiException('HTTP '.($response?->status() ?? 'error'), 'server');
        }
        $length = $response->header('Content-Length');
        if (is_numeric($length) && (int) $length > $maxBytes) {
            throw new WooApiException('larger than '.self::size($maxBytes), 'invalid');
        }
        $body = $response->body();
        if (strlen($body) > $maxBytes) {
            throw new WooApiException('larger than '.self::size($maxBytes), 'invalid');
        }
        $size = @getimagesizefromstring($body);
        $mime = $size['mime'] ?? null;
        if (! $size || ! isset(ImageImporter::EXTENSIONS[$mime])) {
            throw new WooApiException('not a JPG, PNG, GIF, WebP or AVIF image', 'invalid');
        }

        return [$body, $mime, $size];
    }

    /** Media library row for a stored file (matched on its path). */
    protected function record(string $path, string $hash, ?int $attachmentId, ?string $alt, ?string $title, string $mime, ?array $size): void
    {
        $existing = DB::table('media')->where('path', $path)->first(['id']);
        $absolute = Storage::disk('public')->path($path);
        $row = array_filter([
            'filename' => basename($path),
            'title' => $title !== null && trim($title) !== '' ? Str::limit(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 190, '') : null,
            'alt' => $alt !== null && trim($alt) !== '' ? Str::limit(html_entity_decode($alt, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 250, '') : null,
            'mime_type' => $mime,
            'size' => @filesize($absolute) ?: null,
            'width' => $size[0] ?? null,
            'height' => $size[1] ?? null,
            'folder' => trim(dirname(substr($path, strlen('uploads/'))), './') ?: null,
            'source_hash' => ImageImporter::tracksSources() ? $hash : null,
        ], fn ($v) => $v !== null);
        $now = now()->format('Y-m-d H:i:s');
        if ($existing) {
            DB::table('media')->where('id', $existing->id)->update($row + ['updated_at' => $now]);

            return;
        }
        $row += ['path' => $path, 'created_at' => $now, 'updated_at' => $now];
        if ($attachmentId) {
            $row['wp_id'] = $attachmentId;
            if ($this->upserter->source !== null && Upserter::scoped('media')) {
                $row[Upserter::COLUMN] = $this->upserter->source;
            }
        }
        DB::table('media')->insert($row);
    }

    private static function size(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';
    }
}
