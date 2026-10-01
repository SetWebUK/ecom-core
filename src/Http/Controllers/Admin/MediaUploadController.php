<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Http\Controllers\Admin\Concerns\AdminIndex;
use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Models\Media;
use Pine\Commerce\Services\Media\ImageGenerator;
use Pine\Commerce\Services\Media\Images;
use Pine\Commerce\Services\Admin\LocalTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Image uploads + media browser used by <x-admin.image-picker> and <x-admin.rich-editor> (TinyMCE).
 *
 *   POST admin/media/upload   admin.media.upload   field "file" -> {id, path, url, location, thumb, filename, alt, width, height}
 *   GET  admin/api/media      admin.api.media      ?q=&page= -> paginated images, newest first
 *
 * Files go to the public disk (public/storage) under uploads/YYYY/MM/ with a sanitised, unique name and a
 * Media row is created. Only raster images are accepted (no SVG – it can carry scripts). Each upload is oriented,
 * scaled down and given its size variants per commerce.images (Services\Media\ImageGenerator).
 */
class MediaUploadController extends Controller
{
    use AdminIndex;

    public const MAX_KB = 10240;

    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'image', 'mimes:'.implode(',', self::EXTENSIONS), 'max:'.self::MAX_KB],
            'alt' => ['nullable', 'string', 'max:255'],
        ], [
            'file.image' => 'That file isn’t an image. Upload a JPG, PNG, GIF, WebP or AVIF.',
            'file.mimes' => 'Upload a JPG, PNG, GIF, WebP or AVIF image.',
            'file.max' => 'Images can be up to '.(self::MAX_KB / 1024).' MB.',
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $size = @getimagesize($file->getRealPath());
        if (! $size) {
            return response()->json(['message' => 'That image could not be read. Try saving it again as a JPG or PNG.'], 422);
        }

        $extension = strtolower($file->guessExtension() ?: $file->extension());
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;
        if (! in_array($extension, self::EXTENSIONS, true)) {
            return response()->json(['message' => 'Upload a JPG, PNG, GIF, WebP or AVIF image.'], 422);
        }

        $folder = LocalTime::now()->format('Y/m');
        $path = $this->storeImage($file, "uploads/{$folder}", $extension, 'image');
        if (! $path) {
            return response()->json(['message' => 'The image could not be saved. Please try again.'], 500);
        }

        $media = Media::create([
            'path' => $path,
            'filename' => basename($path),
            'title' => Str::of(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))->replace(['-', '_'], ' ')->squish()->limit(190, '')->value() ?: pathinfo($path, PATHINFO_FILENAME),
            'alt' => $request->input('alt'),
            ...$this->fileDetails($path, $file),
            'folder' => $folder,
        ]);

        return response()->json(static::present($media), 201);
    }

    public function index(Request $request): JsonResponse
    {
        $q = $this->searchTerm($request);

        $media = Media::query()
            ->where('mime_type', 'like', 'image/%')
            ->where('mime_type', '!=', 'image/svg+xml')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('filename', 'like', $this->like($q))
                ->orWhere('title', 'like', $this->like($q))
                ->orWhere('alt', 'like', $this->like($q))))
            ->latest('id')
            ->paginate(40, ['id', 'path', 'filename', 'title', 'alt', 'width', 'height', 'mime_type']);

        return response()->json([
            'data' => collect($media->items())->map(fn (Media $m) => static::present($m))->values(),
            'current_page' => $media->currentPage(),
            'last_page' => $media->lastPage(),
            'total' => $media->total(),
        ]);
    }

    /** JSON shape for one media item. URLs are root-relative so saved content works on any domain. */
    public static function present(Media $media): array
    {
        $url = '/storage/'.ltrim($media->path, '/');
        // the media browser's tile: the "thumbnail" size (WordPress's 150×150 crop) when it exists
        $thumbPath = Images::isLocal($relative = ltrim((string) $media->path, '/'))
            ? Images::resolve($relative, isset(Images::sizes()['thumbnail']) ? 'thumbnail' : 150)['path'] : null;

        return [
            'id' => $media->id,
            'path' => $media->path,
            'url' => $url,
            'location' => $url, // TinyMCE images_upload_handler
            'thumb' => $thumbPath && $thumbPath !== $relative ? '/storage/'.$thumbPath : $url,
            'filename' => $media->filename,
            'alt' => (string) $media->alt,
            'width' => $media->width,
            'height' => $media->height,
        ];
    }

    /**
     * Save an upload under $folder with a sanitised, unique name, then fix/downsize it and write its size variants
     * (Services\Media\ImageGenerator – commerce.images). Returns the public-disk path, or null when it was not saved.
     *
     * A name is taken when the file or a media row exists, or when it would clash with another original's generated
     * files (photo.webp is the WebP twin of photo.jpg; "photo-300x200" looks like a size variant, so it becomes
     * "photo-300-200").
     */
    protected function storeImage(UploadedFile $file, string $folder, string $extension, string $fallback): ?string
    {
        $base = Str::limit(Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)), 80, '') ?: $fallback;
        $base = preg_replace('/-(\d+)x(\d+)$/', '-$1-$2', $base);
        $disk = Storage::disk('public');
        $taken = function (string $stem) use ($disk, $folder, $extension): bool {
            $siblings = $extension === 'webp' ? ['webp', 'jpg', 'jpeg', 'png', 'gif', 'avif'] : [$extension, 'webp'];
            foreach ($siblings as $ext) {
                if ($disk->exists("{$folder}/{$stem}.{$ext}")) {
                    return true;
                }
            }

            return Media::where('path', "{$folder}/{$stem}.{$extension}")->exists();
        };
        $stem = $base;
        for ($i = 1; $taken($stem); $i++) {
            $stem = $base.'-'.$i;
        }

        $path = $file->storeAs($folder, $stem.'.'.$extension, 'public');
        if (! $path) {
            return null;
        }
        (new ImageGenerator)->processUpload($path);

        return $path;
    }

    /** mime_type, size, width, height of a stored upload (after it was oriented / scaled down). */
    protected function fileDetails(string $path, UploadedFile $file): array
    {
        $absolute = Storage::disk('public')->path($path);
        clearstatcache(true, $absolute);
        $size = @getimagesize($absolute);

        return [
            'mime_type' => $size['mime'] ?? $file->getMimeType(),
            'size' => @filesize($absolute) ?: $file->getSize(),
            'width' => $size[0] ?? null,
            'height' => $size[1] ?? null,
        ];
    }
}
