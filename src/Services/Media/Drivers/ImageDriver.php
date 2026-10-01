<?php

namespace Pine\Commerce\Services\Media\Drivers;

/**
 * One image library (GD or Imagick) behind the image-size generator (Services\Media\ImageGenerator).
 *
 * Handles are opaque (\GdImage or \Imagick). Formats are file extensions: jpg, png, gif, webp, avif.
 * Every method may throw; the generator reports the failure per file and carries on.
 */
interface ImageDriver
{
    /** "gd" or "imagick". */
    public function name(): string;

    /** Is the PHP extension loaded? */
    public function available(): bool;

    public function canRead(string $format): bool;

    public function canWrite(string $format): bool;

    /** Decode the first frame of a file. */
    public function load(string $file, string $format): object;

    /** @return array{0:int,1:int} width, height */
    public function size(object $image): array;

    /** Apply an EXIF orientation (1–8) to the pixels; returns the (possibly new) handle. */
    public function orient(object $image, int $orientation): object;

    /** A NEW image: the source rectangle (sx, sy, sw, sh) scaled to dw × dh. The input handle is left untouched. */
    public function resize(object $image, int $sx, int $sy, int $sw, int $sh, int $dw, int $dh): object;

    /**
     * Encode to a file.
     *
     * @param  array{jpg?:int,webp?:int,avif?:int,png?:int}  $quality  jpg/webp/avif 0–100, png compression level 0–9
     */
    public function save(object $image, string $file, string $format, array $quality, bool $stripMetadata = true): bool;

    public function destroy(object $image): void;
}
