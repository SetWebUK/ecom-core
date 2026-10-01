<?php

namespace Pine\Commerce\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;
use Pine\Commerce\Services\Media\Images;

/**
 * <x-media-image> – a responsive <img> (in a <picture> with a WebP <source> when every candidate has a WebP twin)
 * for an upload on the public disk:
 *
 *   <x-media-image :path="$image->path" size="card" sizes="(min-width: 1100px) 25vw, 50vw" :alt="$image->alt" class="…" />
 *
 * src = the best existing variant of `size` (media_url($path, $size)), srcset = every existing file of that shape,
 * width/height = the real pixel size of src (no layout shift), loading="lazy" + decoding="async" unless :priority
 * (then fetchpriority="high"). URLs, root-relative paths and missing files degrade to a plain <img> of the original
 * (or the placeholder / `fallback` URL when there is no path). Extra attributes (class, style, data-*) go on the <img>.
 */
class MediaImage extends Component
{
    public function __construct(
        public ?string $path = null,
        public string|int|null $size = null,
        public ?string $sizes = null,
        public string $alt = '',
        public bool $lazy = true,
        public bool $priority = false,
        public bool $picture = true,
        public ?int $width = null,
        public ?int $height = null,
        public ?string $fallback = null,
    ) {}

    /** @return array{src:string, srcset:string, webp:string, width:?int, height:?int} */
    public function image(): array
    {
        $path = $this->path !== null ? trim($this->path) : null;
        if (! $path) {
            return ['src' => $this->fallback ?? media_url(null), 'srcset' => '', 'webp' => '', 'width' => $this->width, 'height' => $this->height];
        }
        if (str_starts_with($path, '/') && ! str_starts_with($path, '//')) {
            return ['src' => $path, 'srcset' => '', 'webp' => '', 'width' => $this->width, 'height' => $this->height]; // theme asset
        }
        $relative = ltrim($path, '/');
        if (! Images::isLocal($relative)) {
            return ['src' => media_url($path), 'srcset' => '', 'webp' => '', 'width' => $this->width, 'height' => $this->height];
        }

        $resolved = Images::resolve($relative, $this->size);
        $srcset = Images::srcset($relative, $this->size);
        $webp = '';
        if ($this->picture && Images::config('picture_webp', true) && ! in_array(Images::extension($relative), ['webp', 'avif'], true)) {
            $candidates = Images::candidates($relative, $this->size);
            $twins = array_filter(array_map(fn ($file) => Images::webpTwin($file['path']), $candidates));
            if ($candidates && count($twins) === count($candidates)) {
                $webp = Images::srcset($relative, $this->size, 'webp');
            }
        }

        return [
            'src' => media_url($resolved['path']),
            'srcset' => $srcset,
            'webp' => $webp,
            'width' => $this->width ?? $resolved['width'],
            'height' => $this->height ?? $resolved['height'],
        ];
    }

    /** Attributes of the <img> (without the caller's extras). */
    public function imgAttributes(array $image): array
    {
        return array_filter([
            'src' => $image['src'],
            'srcset' => $image['srcset'] ?: null,
            'sizes' => $image['srcset'] && $this->sizes ? $this->sizes : null,
            'alt' => $this->alt,
            'width' => $image['width'],
            'height' => $image['height'],
            'loading' => $this->priority || ! $this->lazy ? null : 'lazy',
            'fetchpriority' => $this->priority ? 'high' : null,
            'decoding' => 'async',
        ], fn ($value) => $value !== null);
    }

    public function render(): View
    {
        return view('commerce::media.image');
    }
}
