<?php

namespace Pine\Commerce\Services\Media;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Image sizes – the read side: which size variants exist next to an upload and which one to serve.
 *
 * Variants live next to the original with the WordPress naming scheme, so imported WordPress media and new uploads
 * behave the same:
 *
 *   uploads/2026/05/photo.jpg            original
 *   uploads/2026/05/photo-150x150.jpg    a size variant (real pixel size in the name)
 *   uploads/2026/05/photo-150x150.webp   its WebP twin
 *   uploads/2026/05/photo.webp           WebP twin of the original
 *
 * Sizes are configured in commerce.images.sizes (name => width/height/crop). A size is asked for by name
 * ('thumbnail', 'card' …) or by an integer (fit inside an N×N box). The best EXISTING file is returned – the exact
 * variant when it was generated, otherwise the smallest proportional variant that still covers the requested box,
 * otherwise the original – so nothing ever breaks while `php artisan commerce:images:generate` has not run yet.
 *
 * The public API is the helpers media_url($path, $size), image_srcset($path, $size) and <x-media-image>; see
 * docs/THEMES.md "Images". Directory listings and image headers are memoised per request (flush() in tests).
 */
class Images
{
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

    /** Config used when a client config predates commerce.images (all keys are merged over these). */
    public const DEFAULTS = [
        'generate_on_upload' => true,
        'driver' => 'auto',
        'sizes' => [
            'thumbnail' => ['width' => 150, 'height' => 150, 'crop' => true],
            'card' => ['width' => 400, 'height' => 400, 'crop' => false],
            'medium' => ['width' => 800, 'height' => 800, 'crop' => false],
            'large' => ['width' => 1600, 'height' => 1600, 'crop' => false],
        ],
        'webp' => true,
        'quality' => ['jpg' => 82, 'webp' => 80, 'avif' => 60, 'png' => 8],
        'auto_orient' => true,
        'max_dimension' => 2560,
        'strip_metadata' => true,
        'picture_webp' => true,
    ];

    /** @var array<string,array{sized:array<string,array{0:int,1:int}>,names:array<string,string>}> per folder */
    protected static array $dirs = [];

    /** @var array<string,array{0:int,1:int}|null> */
    protected static array $dimensions = [];

    /** @var array<string,array{width:int,height:int,crop:bool}>|null */
    protected static ?array $sizes = null;

    /** Forget the memoised listing/size of a file's folder (after files were written or deleted). */
    public static function forget(string $path): void
    {
        $dir = dirname($path) === '.' ? '' : dirname($path);
        unset(static::$dirs[$dir]);
        foreach (array_keys(static::$dimensions) as $key) {
            if (str_starts_with($key, $dir === '' ? '' : $dir.'/') && ! str_contains(substr($key, strlen($dir) + 1), '/')) {
                unset(static::$dimensions[$key]);
            }
        }
    }

    public static function flush(): void
    {
        static::$dirs = [];
        static::$dimensions = [];
        static::$sizes = null;
    }

    /** commerce.images.{key} with the package default for a key the client config does not set. */
    public static function config(string $key, mixed $default = null): mixed
    {
        return config('commerce.images.'.$key, data_get(self::DEFAULTS, $key, $default));
    }

    // ------------------------------------------------------------------ sizes

    /**
     * Configured sizes, normalised. Each entry in commerce.images.sizes may be an int (N = fit inside N×N),
     * [w, h, crop] or ['width' => w, 'height' => h, 'crop' => bool]; 0 = unbounded in that direction.
     *
     * @return array<string,array{width:int,height:int,crop:bool}>
     */
    public static function sizes(): array
    {
        if (static::$sizes !== null) {
            return static::$sizes;
        }
        $sizes = [];
        foreach ((array) static::config('sizes', []) as $name => $spec) {
            $size = static::normaliseSize($spec);
            if (is_string($name) && preg_match('/^[a-z0-9_-]+$/i', $name) && $size) {
                $sizes[$name] = $size;
            }
        }

        return static::$sizes = $sizes;
    }

    /** @return array{width:int,height:int,crop:bool}|null */
    public static function normaliseSize(mixed $spec): ?array
    {
        if (is_int($spec) || (is_string($spec) && ctype_digit($spec))) {
            $spec = ['width' => (int) $spec, 'height' => (int) $spec];
        }
        if (! is_array($spec)) {
            return null;
        }
        $width = max(0, (int) ($spec['width'] ?? $spec[0] ?? 0));
        $height = max(0, (int) ($spec['height'] ?? $spec[1] ?? 0));
        $crop = (bool) ($spec['crop'] ?? $spec[2] ?? false);
        if ($width === 0 && $height === 0) {
            return null;
        }
        if ($crop && ($width === 0 || $height === 0)) {
            $crop = false; // a crop needs both sides
        }

        return ['width' => $width, 'height' => $height, 'crop' => $crop];
    }

    /** A configured size by name, or an ad-hoc N×N fit box for an integer. */
    public static function size(string|int|null $size): ?array
    {
        if ($size === null || $size === '') {
            return null;
        }
        if (is_int($size) || ctype_digit((string) $size)) {
            return static::normaliseSize((int) $size);
        }

        return static::sizes()[$size] ?? null;
    }

    /**
     * WordPress's image_resize_dimensions(): the source rectangle and output size for an original of $ow × $oh,
     * or null when the size would not be smaller than the original (never upscales).
     *
     * @param  array{width:int,height:int,crop:bool}  $size
     * @return array{sx:int,sy:int,sw:int,sh:int,width:int,height:int}|null
     */
    public static function target(int $ow, int $oh, array $size): ?array
    {
        if ($ow <= 0 || $oh <= 0) {
            return null;
        }
        $w = $size['width'];
        $h = $size['height'];

        if ($size['crop']) {
            $aspect = $ow / $oh;
            $nw = min($w, $ow);
            $nh = min($h, $oh);
            $nw = $nw ?: (int) round($nh * $aspect);
            $nh = $nh ?: (int) round($nw / $aspect);
            $ratio = max($nw / $ow, $nh / $oh);
            $sw = (int) round($nw / $ratio);
            $sh = (int) round($nh / $ratio);
            $sx = (int) floor(($ow - $sw) / 2);
            $sy = (int) floor(($oh - $sh) / 2);
        } else {
            [$nw, $nh] = static::constrain($ow, $oh, $w, $h);
            [$sx, $sy, $sw, $sh] = [0, 0, $ow, $oh];
        }

        if ($nw >= $ow && $nh >= $oh) {
            return null;
        }

        return ['sx' => $sx, 'sy' => $sy, 'sw' => min($sw, $ow), 'sh' => min($sh, $oh), 'width' => max(1, $nw), 'height' => max(1, $nh)];
    }

    /** WordPress's wp_constrain_dimensions(): scale $w × $h down to fit $maxW × $maxH (0 = no limit). */
    public static function constrain(int $w, int $h, int $maxW, int $maxH): array
    {
        if (! $maxW && ! $maxH) {
            return [$w, $h];
        }
        $wRatio = $maxW && $w > $maxW ? $maxW / $w : 1.0;
        $hRatio = $maxH && $h > $maxH ? $maxH / $h : 1.0;
        $smaller = min($wRatio, $hRatio);
        $larger = max($wRatio, $hRatio);
        $ratio = (($maxW && (int) round($w * $larger) > $maxW) || ($maxH && (int) round($h * $larger) > $maxH)) ? $smaller : $larger;
        $nw = max(1, (int) round($w * $ratio));
        $nh = max(1, (int) round($h * $ratio));
        // rounding must not break the limit (WordPress does the same correction)
        if ($maxW && $nw > $maxW) {
            $nw = $maxW;
        }
        if ($maxH && $nh > $maxH) {
            $nh = $maxH;
        }

        return [$nw, $nh];
    }

    // ------------------------------------------------------------------ paths

    public static function disk(): Filesystem
    {
        return Storage::disk((string) static::config('disk', 'public'));
    }

    /** A path on the public disk this class can work with (not a URL, root-relative asset, traversal or non-image). */
    public static function isLocal(?string $path): bool
    {
        if (! $path || preg_match('#^(https?:)?//#', $path) || str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, "\0")) {
            return false;
        }

        return in_array(static::extension($path), self::EXTENSIONS, true);
    }

    public static function extension(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    /** uploads/2026/05/photo.jpg + 300×200 => uploads/2026/05/photo-300x200.jpg ($extension swaps the extension). */
    public static function variantPath(string $path, int $width, int $height, ?string $extension = null): string
    {
        $info = pathinfo($path);
        $dir = ($info['dirname'] ?? '.') === '.' ? '' : $info['dirname'].'/';

        return $dir.$info['filename'].'-'.$width.'x'.$height.'.'.($extension ?? ($info['extension'] ?? 'jpg'));
    }

    /** uploads/2026/05/photo.jpg => uploads/2026/05/photo.webp (null for a WebP original). */
    public static function webpPath(string $path): ?string
    {
        if (static::extension($path) === 'webp') {
            return null;
        }

        return preg_replace('/\.[a-z0-9]+$/i', '.webp', $path);
    }

    /**
     * Is this file an original upload – not a size variant (photo-300x200.jpg), a converted twin (photo.jpg.webp,
     * photo.webp next to photo.jpg) or WordPress's unscaled original next to photo-scaled.jpg?
     *
     * @param  iterable<string,mixed>|\ArrayAccess<string,mixed>  $siblings  file names in the same folder (as keys)
     */
    public static function isOriginal(string $path, $siblings, array $extensions = self::EXTENSIONS): bool
    {
        $name = basename($path);
        $extension = static::extension($name);
        if (! in_array($extension, $extensions, true)) {
            return false;
        }
        $stem = pathinfo($name, PATHINFO_FILENAME);
        if (preg_match('/-\d+x\d+$/', $stem)) {
            return false; // photo-300x200.jpg
        }
        if (preg_match('/\.(jpe?g|png|gif)$/i', $stem)) {
            return false; // photo.jpg.webp (converted twin)
        }
        if (in_array($extension, ['webp', 'avif'], true)) {
            foreach ($extension === 'webp' ? ['jpg', 'jpeg', 'png', 'gif', 'avif'] : ['jpg', 'jpeg', 'png', 'gif'] as $original) {
                if (isset($siblings[$stem.'.'.$original])) {
                    return false; // photo.webp next to photo.jpg (or photo.avif)
                }
            }
        }
        if (isset($siblings[$stem.'-scaled.'.$extension])) {
            return false; // WordPress keeps the big original next to photo-scaled.jpg
        }

        return true;
    }

    // ------------------------------------------------------------------ lookup

    /** Pixel size of a file on the disk (header read, memoised), null when missing/unreadable. */
    public static function dimensions(string $path): ?array
    {
        if (array_key_exists($path, static::$dimensions)) {
            return static::$dimensions[$path];
        }
        $size = null;
        if (static::isLocal($path)) {
            $file = static::disk()->path($path);
            $info = is_file($file) ? @getimagesize($file) : false;
            $size = $info && $info[0] > 0 && $info[1] > 0 ? [(int) $info[0], (int) $info[1]] : null;
        }

        return static::$dimensions[$path] = $size;
    }

    /**
     * Existing size variants of an original in its own format, smallest first.
     *
     * @return list<array{path:string,width:int,height:int}>
     */
    public static function variants(string $path): array
    {
        if (! static::isLocal($path)) {
            return [];
        }
        $info = pathinfo($path);
        $dir = ($info['dirname'] ?? '.') === '.' ? '' : $info['dirname'];
        $prefix = strtolower($info['filename']).'-';
        $suffix = '.'.strtolower($info['extension'] ?? '');
        $found = [];
        foreach (static::listing($dir) as $name => [$w, $h]) {
            if (str_starts_with($name, $prefix) && str_ends_with($name, $suffix) && strlen($name) === strlen($prefix) + strlen($w.'x'.$h) + strlen($suffix)
                && substr($name, strlen($prefix), -strlen($suffix)) === $w.'x'.$h) {
                $found[] = ['path' => ($dir === '' ? '' : $dir.'/').static::listing($dir, true)[$name], 'width' => $w, 'height' => $h];
            }
        }
        usort($found, fn ($a, $b) => [$a['width'], $a['height']] <=> [$b['width'], $b['height']]);

        return $found;
    }

    /**
     * The best existing file for a size: ['path', 'width', 'height']. Falls back to the original (its dimensions
     * may be null when the file is missing).
     *
     * @return array{path:string,width:?int,height:?int}
     */
    public static function resolve(string $path, string|int|null $size): array
    {
        $original = static::dimensions($path);
        $fallback = ['path' => $path, 'width' => $original[0] ?? null, 'height' => $original[1] ?? null];
        $spec = static::size($size);
        if (! $spec || ! $original) {
            return $fallback;
        }
        $target = static::target($original[0], $original[1], $spec);
        if (! $target) {
            return $fallback; // the original is already small enough
        }
        $variants = static::variants($path);
        foreach ($variants as $variant) {
            if ($variant['width'] === $target['width'] && $variant['height'] === $target['height']) {
                return $variant; // exactly the configured size
            }
        }
        // otherwise the smallest variant with the target's shape that still covers it (e.g. a WordPress size)
        $ratio = $target['width'] / $target['height'];
        foreach ($variants as $variant) {
            if (static::sameShape($variant['width'], $variant['height'], $ratio) && $variant['width'] >= $target['width'] && $variant['height'] >= $target['height']) {
                return $variant;
            }
        }

        return $fallback;
    }

    /** Public URL of the best file for $size (media_url() with a size). */
    public static function url(?string $path, string|int|null $size = null): string
    {
        if (! static::isLocal($path) || $size === null) {
            return media_url($path);
        }

        return media_url(static::resolve($path, $size)['path']);
    }

    /** The WebP twin of a file when it exists: photo-300x200.webp, or photo-300x200.jpg.webp (EWWW / WebP Express). */
    public static function webpTwin(string $path): ?string
    {
        if (static::extension($path) === 'webp') {
            return $path;
        }
        $dir = dirname($path) === '.' ? '' : dirname($path);
        $files = static::listing($dir, true);
        $stem = pathinfo($path, PATHINFO_FILENAME);
        foreach ([$stem.'.webp', basename($path).'.webp'] as $name) {
            if (isset($files[strtolower($name)])) {
                return ($dir === '' ? '' : $dir.'/').$files[strtolower($name)];
            }
        }

        return null;
    }

    /**
     * Files of the same shape as the resolved size (the original included), widest last, for a srcset.
     *
     * @return list<array{path:string,width:int,height:int}>
     */
    public static function candidates(string $path, string|int|null $size = null): array
    {
        $original = static::dimensions($path);
        if (! $original) {
            return [];
        }
        $resolved = static::resolve($path, $size);
        $ratio = ($resolved['width'] ?: $original[0]) / ($resolved['height'] ?: $original[1]);
        $list = [];
        foreach ([...static::variants($path), ['path' => $path, 'width' => $original[0], 'height' => $original[1]]] as $file) {
            if (static::sameShape($file['width'], $file['height'], $ratio)) {
                $list[$file['width']] ??= $file; // one file per width
            }
        }
        ksort($list);

        return array_values($list);
    }

    /**
     * "url 150w, url 300w, …" of the existing files with the shape of $size (all proportional sizes when $size is
     * null); $format = 'webp' lists their WebP twins instead (only those that exist). '' when there is nothing to choose.
     */
    public static function srcset(?string $path, string|int|null $size = null, ?string $format = null): string
    {
        if (! static::isLocal($path)) {
            return '';
        }
        $parts = [];
        foreach (static::candidates($path, $size) as $file) {
            $src = $format === 'webp' ? static::webpTwin($file['path']) : $file['path'];
            if ($src) {
                $parts[] = media_url($src).' '.$file['width'].'w';
            }
        }

        return count($parts) > 1 || ($format === 'webp' && $parts) ? implode(', ', $parts) : '';
    }

    /** Same aspect ratio within WordPress's ±1px rounding. */
    public static function sameShape(int $width, int $height, float $ratio): bool
    {
        if ($width <= 0 || $height <= 0 || $ratio <= 0) {
            return false;
        }

        return abs($width / $ratio - $height) <= 1.01 || abs($height * $ratio - $width) <= 1.01;
    }

    /**
     * Every "*-WxH.*" / "*.webp" file of a folder: lower-case name => [w, h] (WxH files) – or, with $all, lower-case
     * name => real name of every file. One scandir per folder per request.
     */
    protected static function listing(string $dir, bool $all = false): array
    {
        if (! isset(static::$dirs[$dir])) {
            $sized = [];
            $names = [];
            $absolute = static::disk()->path($dir === '' ? '' : $dir);
            foreach (is_dir($absolute) ? (@scandir($absolute) ?: []) : [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $lower = strtolower($name);
                $names[$lower] = $name;
                if (preg_match('/-(\d+)x(\d+)\.[a-z0-9]+$/', $lower, $m)) {
                    $sized[$lower] = [(int) $m[1], (int) $m[2]];
                }
            }
            static::$dirs[$dir] = ['sized' => $sized, 'names' => $names];
        }

        return static::$dirs[$dir][$all ? 'names' : 'sized'];
    }
}
