<?php

namespace Pine\Commerce\Services\Admin\Catalogue\ProductCsv;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Pine\Commerce\Models\Media;
use Pine\Commerce\Services\Admin\LocalTime;
use Throwable;

/**
 * Turns the image cells of a product CSV into media-library paths (public disk, e.g. "uploads/2026/10/photo.jpg"):
 *
 *  - this shop's own images ("/storage/uploads/…", "https://this-shop/storage/…" or "uploads/…") are used as they are;
 *  - other http(s) URLs are downloaded into uploads/YYYY/MM/ with a Media row – once: the Media row remembers the
 *    URL (media.source_hash), so importing the same file again reuses the image instead of downloading it twice.
 *
 * Downloads only reach public hosts (no private/reserved addresses, redirects re-checked), accept raster images up to
 * commerce.product_csv.max_image_kb and time out after commerce.product_csv.image_timeout seconds.
 */
class ImageImporter
{
    public const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif'];

    /** sha1(url) => path, for this request */
    protected array $memo = [];

    public int $downloaded = 0;

    public function __construct(protected bool $download = true) {}

    /**
     * Resolve one cell value. Returns [path|null, warning|null]; in a dry run the path of an image that would be
     * downloaded is "(download) {url}".
     *
     * @return array{0:?string, 1:?string}
     */
    public function resolve(string $value, bool $dryRun): array
    {
        $value = trim($value);
        if ($value === '') {
            return [null, null];
        }
        if ($local = $this->localPath($value)) {
            // this shop's own image: on disk, or at least already known to the catalogue (kept as it is)
            $known = Storage::disk('public')->exists($local) || Media::query()->where('path', $local)->exists()
                || \Pine\Commerce\Models\ProductImage::query()->where('path', $local)->exists();

            return $known ? [$local, null] : [null, 'Image not found in the media library: '.Cells::short($value, 80)];
        }
        if (! preg_match('#^https?://#i', $value) || ! filter_var($value, FILTER_VALIDATE_URL)) {
            return [null, 'Not an image URL: '.Cells::short($value, 80)];
        }
        $hash = sha1($value);
        if (isset($this->memo[$hash])) {
            return [$this->memo[$hash], null];
        }
        if ($existing = $this->known($hash)) {
            return [$this->memo[$hash] = $existing, null];
        }
        if (! $this->download) {
            return [null, 'Image not downloaded (downloading images is switched off): '.Cells::short($value, 80)];
        }
        if ($dryRun) {
            return ['(download) '.$value, null];
        }

        try {
            return [$this->memo[$hash] = $this->fetch($value, $hash), null];
        } catch (Throwable $e) {
            return [null, 'Image not imported ('.$e->getMessage().'): '.Cells::short($value, 80)];
        }
    }

    /** Public-disk path of one of this shop's own image URLs/paths, or null. */
    public function localPath(string $value): ?string
    {
        $path = null;
        if (preg_match('#^(https?:)?//#i', $value)) {
            $base = rtrim((string) config('app.url'), '/').'/storage/';
            $parts = parse_url($value);
            $own = parse_url((string) config('app.url'), PHP_URL_HOST);
            if (str_starts_with($value, $base) || (($parts['host'] ?? null) === $own && str_starts_with((string) ($parts['path'] ?? ''), '/storage/'))) {
                $path = substr((string) ($parts['path'] ?? ''), strlen('/storage/'));
            }
        } elseif (str_starts_with($value, '/storage/')) {
            $path = substr($value, strlen('/storage/'));
        } elseif (! str_contains($value, '://') && ! str_starts_with($value, '/')) {
            $path = $value;
        }
        if ($path === null) {
            return null;
        }
        $path = rawurldecode(ltrim($path, '/'));

        return preg_match('/^(?!.*\.\.)[A-Za-z0-9][A-Za-z0-9\/_\-.() ]*\.(jpe?g|png|gif|webp|avif)$/i', $path) ? $path : null;
    }

    protected function known(string $hash): ?string
    {
        if (! static::tracksSources()) {
            return null;
        }
        $path = Media::query()->where('source_hash', $hash)->orderBy('id')->value('path');

        return $path && Storage::disk('public')->exists($path) ? $path : null;
    }

    public static function tracksSources(): bool
    {
        static $has = [];
        try {
            return $has[spl_object_id(\Illuminate\Support\Facades\DB::connection()->getPdo())] ??= Schema::hasColumn('media', 'source_hash');
        } catch (Throwable) {
            return false;
        }
    }

    /** Download, check and store one image; returns its public-disk path. */
    protected function fetch(string $url, string $hash): string
    {
        $maxBytes = max(1, (int) config('commerce.product_csv.max_image_kb', 10240)) * 1024;
        $response = null;
        for ($hop = 0; $hop < 4; $hop++) {
            $this->guard($url);
            $response = Http::timeout(max(1, (int) config('commerce.product_csv.image_timeout', 15)))
                ->withOptions(['allow_redirects' => false])
                ->withHeaders(['User-Agent' => 'PineCommerce-ProductImport/1.0', 'Accept' => 'image/*'])
                ->get($url);
            if ($response->redirect() && ($location = $response->header('Location'))) {
                $url = str_starts_with($location, 'http') ? $location : (string) \GuzzleHttp\Psr7\UriResolver::resolve(new \GuzzleHttp\Psr7\Uri($url), new \GuzzleHttp\Psr7\Uri($location));

                continue;
            }
            break;
        }
        if (! $response || ! $response->successful()) {
            throw new \RuntimeException('HTTP '.($response?->status() ?? 'error'));
        }
        $body = $response->body();
        if (strlen($body) > $maxBytes) {
            throw new \RuntimeException('larger than '.round($maxBytes / 1048576, 1).' MB');
        }
        $size = @getimagesizefromstring($body);
        $mime = $size['mime'] ?? null;
        if (! $size || ! isset(self::EXTENSIONS[$mime])) {
            throw new \RuntimeException('not a JPG, PNG, GIF, WebP or AVIF image');
        }
        $extension = self::EXTENSIONS[$mime];

        $folder = LocalTime::now()->format('Y/m');
        $original = pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME);
        $base = Str::limit(Str::slug(rawurldecode($original)), 80, '') ?: 'image';
        $disk = Storage::disk('public');
        $name = $base.'.'.$extension;
        for ($i = 1; $disk->exists("uploads/{$folder}/{$name}") || Media::query()->where('path', "uploads/{$folder}/{$name}")->exists(); $i++) {
            $name = $base.'-'.$i.'.'.$extension;
        }
        $path = "uploads/{$folder}/{$name}";
        if (! $disk->put($path, $body)) {
            throw new \RuntimeException('could not be saved');
        }
        static::optimise($path, $extension);
        $absolute = $disk->path($path);
        clearstatcache(true, $absolute);
        $stored = @getimagesize($absolute) ?: $size; // the image helper may have scaled the original down

        Media::query()->forceCreate(array_filter([ // internal values only
            'path' => $path,
            'filename' => $name,
            'title' => Str::of(rawurldecode($original))->replace(['-', '_'], ' ')->squish()->limit(190, '')->value() ?: $base,
            'mime_type' => $stored['mime'] ?? $mime,
            'size' => @filesize($absolute) ?: strlen($body),
            'width' => $stored[0] ?? null,
            'height' => $stored[1] ?? null,
            'folder' => $folder,
            'source_hash' => static::tracksSources() ? $hash : null,
        ], fn ($v) => $v !== null));
        $this->downloaded++;

        return $path;
    }

    /** Only public internet hosts (no localhost / private / reserved ranges), unless commerce.product_csv.url_guard is off. */
    protected function guard(string $url): void
    {
        $parts = parse_url($url);
        if (! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) {
            throw new \RuntimeException('not an http(s) URL');
        }
        if (! config('commerce.product_csv.url_guard', true)) {
            return;
        }
        $host = trim($parts['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (! $ips) {
            throw new \RuntimeException('host not found');
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \RuntimeException('private address not allowed');
            }
        }
    }

    /**
     * After an image is stored (public-disk path): the core image helper when this core version has one – the same
     * processing as an admin upload (orientation, max size, size variants) – otherwise the media browser's 150×150
     * thumbnail next to the original, as admin uploads made before image sizes.
     */
    public static function optimise(string $path, string $extension): void
    {
        $generator = 'Pine\\Commerce\\Services\\Media\\ImageGenerator';
        if (class_exists($generator) && method_exists($generator, 'processUpload')) {
            try {
                (new $generator)->processUpload($path);
            } catch (Throwable $e) {
                Log::info('Product import image sizes skipped for '.$path.': '.$e->getMessage());
            }

            return;
        }
        $absolutePath = Storage::disk('public')->path($path);
        if (! in_array($extension, ['jpg', 'png', 'webp'], true) || ! extension_loaded('gd')) {
            return;
        }
        try {
            $source = match ($extension) {
                'jpg' => @imagecreatefromjpeg($absolutePath),
                'png' => @imagecreatefrompng($absolutePath),
                'webp' => @imagecreatefromwebp($absolutePath),
            };
            if (! $source) {
                return;
            }
            $w = imagesx($source);
            $h = imagesy($source);
            if ($w <= 150 && $h <= 150) {
                return;
            }
            $side = min($w, $h);
            $thumb = imagecreatetruecolor(150, 150);
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            imagefill($thumb, 0, 0, imagecolorallocatealpha($thumb, 255, 255, 255, 127));
            imagecopyresampled($thumb, $source, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2), 150, 150, $side, $side);
            $target = preg_replace('/\.(\w+)$/', '-150x150.$1', $absolutePath);
            match ($extension) {
                'jpg' => imagejpeg($thumb, $target, 82),
                'png' => imagepng($thumb, $target, 8),
                'webp' => imagewebp($thumb, $target, 80),
            };
        } catch (Throwable $e) {
            Log::info('Product import thumbnail skipped for '.$absolutePath.': '.$e->getMessage());
        }
    }
}
