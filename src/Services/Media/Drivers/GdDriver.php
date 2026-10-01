<?php

namespace Pine\Commerce\Services\Media\Drivers;

use RuntimeException;

/** PHP GD (bundled with almost every PHP build). Metadata is never written, so every output is stripped. */
class GdDriver implements ImageDriver
{
    public function name(): string
    {
        return 'gd';
    }

    public function available(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    public function canRead(string $format): bool
    {
        if (! $this->available()) {
            return false;
        }
        $types = imagetypes();

        return match ($format) {
            'jpg', 'jpeg' => (bool) ($types & IMG_JPG) && function_exists('imagecreatefromjpeg'),
            'png' => (bool) ($types & IMG_PNG) && function_exists('imagecreatefrompng'),
            'gif' => (bool) ($types & IMG_GIF) && function_exists('imagecreatefromgif'),
            'webp' => defined('IMG_WEBP') && (bool) ($types & IMG_WEBP) && function_exists('imagecreatefromwebp'),
            'avif' => defined('IMG_AVIF') && (bool) ($types & IMG_AVIF) && function_exists('imagecreatefromavif'),
            default => false,
        };
    }

    public function canWrite(string $format): bool
    {
        return $this->canRead($format) && match ($format) {
            'jpg', 'jpeg' => function_exists('imagejpeg'),
            'png' => function_exists('imagepng'),
            'gif' => function_exists('imagegif'),
            'webp' => function_exists('imagewebp'),
            'avif' => function_exists('imageavif'),
            default => false,
        };
    }

    public function load(string $file, string $format): object
    {
        $image = match ($format) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($file),
            'png' => @imagecreatefrompng($file),
            'gif' => @imagecreatefromgif($file),
            'webp' => @imagecreatefromwebp($file),
            'avif' => @imagecreatefromavif($file),
            default => false,
        };
        if (! $image) {
            throw new RuntimeException('GD could not decode the image.');
        }
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }

    public function size(object $image): array
    {
        return [imagesx($image), imagesy($image)];
    }

    public function orient(object $image, int $orientation): object
    {
        // EXIF: 2 mirror, 3 rotate 180, 4 flip, 5 transpose, 6 rotate 90 CW, 7 transverse, 8 rotate 90 CCW
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, in_array($orientation, [2, 7], true) ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL);
        }
        $angle = match ($orientation) {
            3 => 180,
            5, 6 => 270, // imagerotate turns counter-clockwise
            7, 8 => 90,
            default => 0,
        };
        if ($angle) {
            $rotated = imagerotate($image, $angle, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
            if (! $rotated) {
                throw new RuntimeException('GD could not rotate the image.');
            }
            imagealphablending($rotated, false);
            imagesavealpha($rotated, true);
            imagedestroy($image);
            $image = $rotated;
        }

        return $image;
    }

    public function resize(object $image, int $sx, int $sy, int $sw, int $sh, int $dw, int $dh): object
    {
        $target = imagecreatetruecolor($dw, $dh);
        if (! $target) {
            throw new RuntimeException('GD could not allocate the resized image.');
        }
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, (int) imagecolorallocatealpha($target, 255, 255, 255, 127));
        if (! imagecopyresampled($target, $image, 0, 0, $sx, $sy, $dw, $dh, $sw, $sh)) {
            throw new RuntimeException('GD could not resample the image.');
        }

        return $target;
    }

    public function save(object $image, string $file, string $format, array $quality, bool $stripMetadata = true): bool
    {
        if ($format === 'jpg' || $format === 'jpeg' || $format === 'gif') {
            // no alpha in JPEG; GIF keeps one transparent colour at most – flatten onto white
            $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
            imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
            imagealphablending($flat, true);
            imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
            $image = $flat;
        }
        if ($format === 'jpg' || $format === 'jpeg') {
            imageinterlace($image, true); // progressive
        }

        try {
            return match ($format) {
                'jpg', 'jpeg' => imagejpeg($image, $file, $this->clamp($quality['jpg'] ?? 82, 0, 100)),
                'png' => imagepng($image, $file, $this->clamp($quality['png'] ?? 8, 0, 9)),
                'gif' => imagegif($image, $file),
                'webp' => imagewebp($image, $file, $this->clamp($quality['webp'] ?? 80, 0, 100)),
                'avif' => imageavif($image, $file, $this->clamp($quality['avif'] ?? 60, 0, 100)),
                default => false,
            };
        } finally {
            if (isset($flat)) {
                imagedestroy($flat);
            }
        }
    }

    public function destroy(object $image): void
    {
        if ($image instanceof \GdImage) {
            imagedestroy($image);
        }
    }

    protected function clamp(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }
}
