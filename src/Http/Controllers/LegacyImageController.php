<?php

namespace Pine\Commerce\Http\Controllers;

use Pine\Commerce\Services\Media\Images;
use Pine\Commerce\Support\Features;

/**
 * A WordPress image size that is not on disk: GET /storage/uploads/{path}-{W}x{H}.{ext}. The web server serves every
 * file that exists, so only missing ones get here. Imported WordPress content links the sizes WordPress generated
 * (a category banner as sale-450x206.jpg), but imports download only the original and the media library makes its own
 * sizes. 301 to the nearest file that exists: a square size for a square request (the thumbnail crops), else the
 * smallest variant of the original's shape that covers the width, else the original itself (or WordPress's
 * "-scaled" original). 404 without an original. Feature switch legacy_content.
 */
class LegacyImageController extends Controller
{
    public function show(string $path)
    {
        abort_unless(Features::enabled('legacy_content', true), 404);
        $path = 'uploads/'.$path;
        if (! Images::isLocal($path) || ! preg_match('/^(.+)-(\d{1,5})x(\d{1,5})\.([a-z0-9]{3,4})$/i', $path, $m)
            || ! in_array(strtolower($m[4]), Images::EXTENSIONS, true)) {
            abort(404);
        }
        [, $stem, $width, $height, $extension] = $m;
        [$width, $height] = [(int) $width, (int) $height];
        $disk = Images::disk();
        if ($disk->exists($path)) {
            return response()->file($disk->path($path)); // only without a web server in front (php artisan serve)
        }
        foreach ([$stem.'.'.$extension, $stem.'-scaled.'.$extension] as $original) {
            if (! $disk->exists($original)) {
                continue;
            }
            $target = null;
            if ($width === $height) {
                foreach (Images::variants($original) as $variant) {
                    if ($variant['width'] === $variant['height'] && $variant['width'] >= $width) {
                        $target = $variant['path'];
                        break;
                    }
                }
            }
            $target ??= Images::resolve($original, $width)['path'];

            return redirect(media_url($target), 301);
        }
        abort(404);
    }
}
