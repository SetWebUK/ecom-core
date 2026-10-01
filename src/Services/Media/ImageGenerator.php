<?php

namespace Pine\Commerce\Services\Media;

use Illuminate\Support\Facades\Log;
use Pine\Commerce\Models\Media;
use Pine\Commerce\Services\Media\Drivers\GdDriver;
use Pine\Commerce\Services\Media\Drivers\ImageDriver;
use Pine\Commerce\Services\Media\Drivers\ImagickDriver;
use Throwable;

/**
 * Image sizes – the write side: fixes and downsizes new uploads and writes the size variants of commerce.images.sizes
 * (plus WebP twins) next to an original with PHP GD or Imagick (no external binaries).
 *
 * Safety rules (tests: tests/Feature/ImageSizesTest):
 *  - generate() never writes to the original or to any file that is a media-library item of its own;
 *  - with 'missing' => true an existing file is never touched (re-runs only fill gaps);
 *  - only prepareUpload() rewrites an original, and only for a brand-new upload (EXIF orientation, max dimension).
 */
class ImageGenerator
{
    public function __construct(protected ?ImageDriver $driver = null)
    {
        $this->driver ??= static::makeDriver((string) Images::config('driver', 'auto'));
    }

    /** "imagick", "gd" or "auto" (Imagick when loaded, else GD); null when neither is available. */
    public static function makeDriver(string $name = 'auto'): ?ImageDriver
    {
        $candidates = match (strtolower($name)) {
            'gd' => [new GdDriver],
            'imagick' => [new ImagickDriver],
            default => [new ImagickDriver, new GdDriver],
        };
        foreach ($candidates as $driver) {
            if ($driver->available()) {
                return $driver;
            }
        }

        return null;
    }

    public function driver(): ?ImageDriver
    {
        return $this->driver;
    }

    /**
     * A NEW upload: fix EXIF orientation and scale down past commerce.images.max_dimension (in place), then write the
     * variants when commerce.images.generate_on_upload is on. Never throws – a failure only means fewer variants.
     *
     * @return list<array{size:string,path:string,status:string,message?:string}>
     */
    public function processUpload(string $path): array
    {
        try {
            $this->prepareUpload($path);
        } catch (Throwable $e) {
            Log::info('Image upload preparation skipped for '.$path.': '.$e->getMessage());
        }
        if (! Images::config('generate_on_upload', true)) {
            return [];
        }

        return $this->generate($path);
    }

    /** Rewrite a new upload's original: EXIF orientation (auto_orient) and max_dimension. Returns true when rewritten. */
    public function prepareUpload(string $path): bool
    {
        $max = (int) Images::config('max_dimension', 0);
        $orient = (bool) Images::config('auto_orient', true);
        if ((! $max && ! $orient) || ! $this->driver || ! Images::isLocal($path)) {
            return false;
        }
        $file = Images::disk()->path($path);
        $format = $this->format($file);
        if (! $format || ! $this->driver->canRead($format) || ! $this->driver->canWrite($format) || $this->isAnimated($file, $format)) {
            return false;
        }
        $orientation = $orient ? $this->orientation($file, $format) : 1;
        $size = @getimagesize($file);
        if (! $size) {
            return false;
        }
        [$w, $h] = in_array($orientation, [5, 6, 7, 8], true) ? [$size[1], $size[0]] : [$size[0], $size[1]];
        $scale = $max && max($w, $h) > $max ? Images::constrain($w, $h, $max, $max) : null;
        if ($orientation === 1 && ! $scale) {
            return false;
        }

        $image = $this->driver->load($file, $format);
        try {
            if ($orientation !== 1) {
                $image = $this->driver->orient($image, $orientation);
            }
            if ($scale) {
                [$cw, $ch] = $this->driver->size($image);
                $resized = $this->driver->resize($image, 0, 0, $cw, $ch, $scale[0], $scale[1]);
                $this->driver->destroy($image);
                $image = $resized;
            }
            $tmp = $file.'.tmp-'.bin2hex(random_bytes(4));
            if (! $this->driver->save($image, $tmp, $format, $this->quality(), (bool) Images::config('strip_metadata', true)) || ! @rename($tmp, $file)) {
                @unlink($tmp);

                return false;
            }
        } finally {
            $this->driver->destroy($image);
            Images::forget($path);
        }

        return true;
    }

    /**
     * Write the size variants (and WebP twins) of one original.
     *
     * Options: sizes (list of size names, default all), missing (bool: keep existing files), dry_run (bool),
     * webp (bool, default commerce.images.webp).
     *
     * Status per file: created | replaced | exists | would-create | would-replace | skipped (not smaller than the
     * original) | protected (a library item of its own) | unsupported | missing (no original on disk) | failed.
     *
     * @return list<array{size:string,path:string,status:string,message?:string}>
     */
    public function generate(string $path, array $options = []): array
    {
        $missing = (bool) ($options['missing'] ?? false);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $webp = (bool) ($options['webp'] ?? Images::config('webp', true));
        $sizes = Images::sizes();
        if (! empty($options['sizes'])) {
            $sizes = array_intersect_key($sizes, array_flip((array) $options['sizes']));
        }

        if (! Images::isLocal($path)) {
            return [['size' => '*', 'path' => $path, 'status' => 'unsupported', 'message' => 'Not an image path on the public disk.']];
        }
        if (! is_file($file = Images::disk()->path($path))) {
            return [['size' => '*', 'path' => $path, 'status' => 'missing', 'message' => 'The original file is not on disk.']];
        }
        $format = $this->format($file);
        if (! $format || ! $this->driver || ! $this->driver->canRead($format)) {
            return [['size' => '*', 'path' => $path, 'status' => 'unsupported', 'message' => $this->driver ? "The {$this->driver->name()} driver cannot read this format." : 'Neither GD nor Imagick is available.']];
        }
        if ($this->isAnimated($file, $format)) {
            return [['size' => '*', 'path' => $path, 'status' => 'unsupported', 'message' => 'Animated GIF – served as uploaded.']];
        }
        $info = @getimagesize($file);
        if (! $info || $info[0] < 1 || $info[1] < 1) {
            return [['size' => '*', 'path' => $path, 'status' => 'failed', 'message' => 'The image could not be read.']];
        }
        $orientation = Images::config('auto_orient', true) ? $this->orientation($file, $format) : 1;
        [$ow, $oh] = in_array($orientation, [5, 6, 7, 8], true) ? [(int) $info[1], (int) $info[0]] : [(int) $info[0], (int) $info[1]];
        // WebP twins for JPEG/PNG/GIF only (a WebP or AVIF original is already a modern format)
        $webp = $webp && in_array($format, ['jpg', 'png', 'gif'], true) && $this->driver->canWrite('webp');

        // every file to write: [size name, relative path, format, target|null (null = full size)]
        $jobs = [];
        foreach ($sizes as $name => $size) {
            $target = Images::target($ow, $oh, $size);
            if (! $target) {
                $jobs[] = [$name, $path, null, null];

                continue;
            }
            $jobs[] = [$name, Images::variantPath($path, $target['width'], $target['height']), $format, $target];
            if ($webp) {
                $jobs[] = [$name.':webp', Images::variantPath($path, $target['width'], $target['height'], 'webp'), 'webp', $target];
            }
        }
        if ($webp) {
            $jobs[] = ['original:webp', Images::webpPath($path), 'webp', null];
        }

        $results = [];
        $image = null;
        $disk = Images::disk();
        $protected = $this->protectedPaths($path, array_column($jobs, 1));
        try {
            foreach ($jobs as [$name, $target, $outFormat, $box]) {
                if ($outFormat === null) {
                    $results[] = ['size' => $name, 'path' => $target, 'status' => 'skipped', 'message' => 'The original is not larger than this size.'];

                    continue;
                }
                if ($target === $path || isset($protected[$target])) {
                    $results[] = ['size' => $name, 'path' => $target, 'status' => 'protected', 'message' => 'Another original has this name – left alone.'];

                    continue;
                }
                $exists = $disk->exists($target);
                if ($exists && $missing) {
                    $results[] = ['size' => $name, 'path' => $target, 'status' => 'exists'];

                    continue;
                }
                if (! $this->driver->canWrite($outFormat)) {
                    $results[] = ['size' => $name, 'path' => $target, 'status' => 'unsupported', 'message' => "The {$this->driver->name()} driver cannot write {$outFormat}."];

                    continue;
                }
                if ($dryRun) {
                    $results[] = ['size' => $name, 'path' => $target, 'status' => $exists ? 'would-replace' : 'would-create'];

                    continue;
                }
                try {
                    if (! $image) {
                        $image = $this->driver->load($file, $format);
                        if ($orientation !== 1) {
                            $image = $this->driver->orient($image, $orientation);
                        }
                    }
                    $this->write($image, $disk->path($target), $outFormat, $box);
                    $results[] = ['size' => $name, 'path' => $target, 'status' => $exists ? 'replaced' : 'created'];
                } catch (Throwable $e) {
                    $results[] = ['size' => $name, 'path' => $target, 'status' => 'failed', 'message' => $e->getMessage()];
                }
            }
        } finally {
            if ($image) {
                $this->driver->destroy($image);
            }
            Images::forget($path);
        }

        return $results;
    }

    /**
     * Delete the generated files of an original: size variants (photo-300x200.jpg), WebP/AVIF twins (photo.webp,
     * photo-300x200.webp, photo.jpg.webp). Files that are library items of their own are kept. The original itself
     * is only deleted with $withOriginal. Returns the deleted paths.
     *
     * @return list<string>
     */
    public function deleteVariants(string $path, bool $withOriginal = false): array
    {
        $path = ltrim($path, '/');
        if (! Images::isLocal($path) && ! ($withOriginal && $path !== '' && ! str_contains($path, '..'))) {
            return [];
        }
        $disk = Images::disk();
        $dir = dirname($path) === '.' ? '' : dirname($path);
        $stem = pathinfo($path, PATHINFO_FILENAME);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $pattern = '/^'.preg_quote($stem, '/').'(-\d+x\d+)?\.('.preg_quote($extension, '/').'|webp|avif)(\.webp|\.avif)?$/i';
        $deleted = [];
        try {
            $files = $disk->files($dir);
            $protected = $this->protectedPaths($path, $files);
            foreach ($files as $file) {
                if ($file === $path) {
                    if ($withOriginal && $disk->delete($file)) {
                        $deleted[] = $file;
                    }

                    continue;
                }
                if (preg_match($pattern, basename($file)) && ! isset($protected[$file]) && $disk->delete($file)) {
                    $deleted[] = $file;
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
        Images::forget($path);

        return $deleted;
    }

    // ------------------------------------------------------------------ internals

    /** @param  array{sx:int,sy:int,sw:int,sh:int,width:int,height:int}|null  $box */
    protected function write(object $image, string $file, string $format, ?array $box): void
    {
        $out = $image;
        if ($box) {
            $out = $this->driver->resize($image, $box['sx'], $box['sy'], $box['sw'], $box['sh'], $box['width'], $box['height']);
        }
        $tmp = $file.'.tmp-'.bin2hex(random_bytes(4));
        try {
            if (! $this->driver->save($out, $tmp, $format, $this->quality(), (bool) Images::config('strip_metadata', true)) || ! is_file($tmp) || ! filesize($tmp)) {
                throw new \RuntimeException('The image could not be encoded.');
            }
            if (! @rename($tmp, $file)) {
                throw new \RuntimeException('The file could not be written.');
            }
            @chmod($file, 0664);
        } finally {
            @unlink($tmp);
            if ($out !== $image) {
                $this->driver->destroy($out);
            }
        }
    }

    /** Candidate paths that are media-library items other than $path itself: path => true. */
    protected function protectedPaths(string $path, array $candidates): array
    {
        $candidates = array_values(array_filter(array_unique($candidates), fn ($c) => is_string($c) && $c !== $path));
        if (! $candidates) {
            return [];
        }
        try {
            $found = [];
            foreach (array_chunk($candidates, 500) as $chunk) {
                foreach (Media::query()->whereIn('path', $chunk)->pluck('path') as $p) {
                    $found[$p] = true;
                }
            }

            return $found;
        } catch (Throwable) {
            return []; // no media table (e.g. before migrations) – nothing registered to protect
        }
    }

    /** @return array{jpg:int,webp:int,avif:int,png:int} */
    protected function quality(): array
    {
        return array_replace(Images::DEFAULTS['quality'], array_map('intval', (array) Images::config('quality', [])));
    }

    /** jpg|png|gif|webp|avif from the file's content (not its name). */
    public function format(string $file): ?string
    {
        $info = @getimagesize($file);

        return match ($info[2] ?? null) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_GIF => 'gif',
            IMAGETYPE_WEBP => 'webp',
            defined('IMAGETYPE_AVIF') ? IMAGETYPE_AVIF : -1 => 'avif',
            default => null,
        };
    }

    /** EXIF orientation 1–8 of a JPEG (1 when unknown). */
    public function orientation(string $file, string $format): int
    {
        if ($format !== 'jpg' || ! function_exists('exif_read_data')) {
            return 1;
        }
        $exif = @exif_read_data($file);
        $value = (int) ($exif['Orientation'] ?? 1);

        return $value >= 1 && $value <= 8 ? $value : 1;
    }

    /** More than one frame in a GIF (animated GIFs are served as uploaded). */
    public function isAnimated(string $file, string $format): bool
    {
        if ($format !== 'gif') {
            return false;
        }
        $handle = @fopen($file, 'rb');
        if (! $handle) {
            return false;
        }
        $frames = 0;
        $tail = '';
        while (! feof($handle) && $frames < 2) {
            $chunk = $tail.fread($handle, 1024 * 100);
            $frames += preg_match_all('#\x00\x21\xF9\x04.{4}\x00[\x2C\x21]#s', $chunk);
            $tail = substr($chunk, -20);
        }
        fclose($handle);

        return $frames > 1;
    }
}
