<?php

namespace Pine\Commerce\Services\Media\Drivers;

use Imagick;
use RuntimeException;

/** PHP Imagick (ImageMagick): better resampling and lower PHP memory use than GD for big photos. */
class ImagickDriver implements ImageDriver
{
    protected const FORMATS = ['jpg' => 'JPEG', 'jpeg' => 'JPEG', 'png' => 'PNG', 'gif' => 'GIF', 'webp' => 'WEBP', 'avif' => 'AVIF'];

    /** @var array<string,bool>|null */
    protected static ?array $supported = null;

    public function name(): string
    {
        return 'imagick';
    }

    public function available(): bool
    {
        return extension_loaded('imagick') && class_exists(Imagick::class);
    }

    public function canRead(string $format): bool
    {
        if (! $this->available() || ! isset(self::FORMATS[$format])) {
            return false;
        }
        static::$supported ??= array_fill_keys(Imagick::queryFormats(), true);

        return isset(static::$supported[self::FORMATS[$format]]);
    }

    public function canWrite(string $format): bool
    {
        return $this->canRead($format);
    }

    public function load(string $file, string $format): object
    {
        $image = new Imagick;
        $image->readImage($file);
        if ($image->getNumberImages() > 1) {
            $image->setIteratorIndex(0);
            $first = $image->getImage();
            $image->clear();
            $image = $first;
        }
        $image->setImagePage(0, 0, 0, 0);

        return $image;
    }

    public function size(object $image): array
    {
        return [$image->getImageWidth(), $image->getImageHeight()];
    }

    public function orient(object $image, int $orientation): object
    {
        match ($orientation) {
            2 => $image->flopImage(),
            3 => $image->rotateImage('none', 180),
            4 => $image->flipImage(),
            5 => $image->transposeImage(),
            6 => $image->rotateImage('none', 90),
            7 => $image->transverseImage(),
            8 => $image->rotateImage('none', -90),
            default => null,
        };
        $image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
        $image->setImagePage(0, 0, 0, 0);

        return $image;
    }

    public function resize(object $image, int $sx, int $sy, int $sw, int $sh, int $dw, int $dh): object
    {
        $copy = clone $image;
        [$w, $h] = $this->size($copy);
        if ($sx !== 0 || $sy !== 0 || $sw !== $w || $sh !== $h) {
            $copy->cropImage($sw, $sh, $sx, $sy);
            $copy->setImagePage(0, 0, 0, 0);
        }
        if (! $copy->resizeImage($dw, $dh, Imagick::FILTER_LANCZOS, 1)) {
            throw new RuntimeException('Imagick could not resize the image.');
        }

        return $copy;
    }

    public function save(object $image, string $file, string $format, array $quality, bool $stripMetadata = true): bool
    {
        $out = clone $image;
        try {
            if ($stripMetadata) {
                // drop EXIF/XMP/comments but keep the colour profile (colours shift without it)
                $profiles = $out->getImageProfiles('icc', true);
                $out->stripImage();
                if (! empty($profiles['icc'])) {
                    $out->profileImage('icc', $profiles['icc']);
                }
            }
            if ($format === 'jpg' || $format === 'jpeg') {
                if ($out->getImageAlphaChannel()) {
                    $out->setImageBackgroundColor('white');
                    $out = $out->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                }
                $out->setImageCompressionQuality(max(0, min(100, (int) ($quality['jpg'] ?? 82))));
                $out->setInterlaceScheme(Imagick::INTERLACE_PLANE);
            } elseif ($format === 'webp' || $format === 'avif') {
                $out->setImageCompressionQuality(max(0, min(100, (int) ($quality[$format] ?? ($format === 'webp' ? 80 : 60)))));
            } elseif ($format === 'png') {
                // ImageMagick PNG "quality" = zlib level × 10 + filter (5 = adaptive)
                $out->setImageCompressionQuality(max(0, min(9, (int) ($quality['png'] ?? 8))) * 10 + 5);
            }
            $out->setImageFormat(self::FORMATS[$format] ?? strtoupper($format));

            return $out->writeImage($format.':'.$file) !== false;
        } finally {
            $out->clear();
        }
    }

    public function destroy(object $image): void
    {
        if ($image instanceof Imagick) {
            $image->clear();
        }
    }
}
