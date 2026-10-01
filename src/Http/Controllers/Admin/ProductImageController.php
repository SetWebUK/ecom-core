<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Models\Media;
use Pine\Commerce\Services\Admin\LocalTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Product photo uploads for the product editor's Media card.
 *
 *   POST admin/products/images   admin.products.images.store   field "file" -> same JSON as admin.media.upload
 *
 * Same rules as the shared media upload (raster images only, max 10 MB, sanitised unique name, Media row so the
 * photo shows in the media library, sizes per commerce.images), but stored under uploads/products/YYYY/MM/ on the public disk. The editor
 * keeps the order, main image and alt text client-side and saves them with the product (ProductSaver::syncImages).
 */
class ProductImageController extends MediaUploadController
{
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'image', 'mimes:'.implode(',', self::EXTENSIONS), 'max:'.self::MAX_KB],
            'alt' => ['nullable', 'string', 'max:255'],
        ], [
            'file.required' => 'Choose an image to upload.',
            'file.image' => 'That file isn’t an image. Upload a JPG, PNG, GIF, WebP or AVIF.',
            'file.mimes' => 'Upload a JPG, PNG, GIF, WebP or AVIF image.',
            'file.max' => 'Images can be up to '.(self::MAX_KB / 1024).' MB.',
            'file.uploaded' => 'The upload failed – the image may be too large.',
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

        $month = LocalTime::now()->format('Y/m');
        $path = $this->storeImage($file, 'uploads/products/'.$month, $extension, 'product');
        if (! $path) {
            return response()->json(['message' => 'The image could not be saved. Please try again.'], 500);
        }

        $media = Media::create([
            'path' => $path,
            'filename' => basename($path),
            'title' => Str::of(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))->replace(['-', '_'], ' ')->squish()->limit(190, '')->value() ?: pathinfo($path, PATHINFO_FILENAME),
            'alt' => $request->input('alt'),
            ...$this->fileDetails($path, $file),
            'folder' => 'products/'.$month,
        ]);

        return response()->json(static::present($media), 201);
    }
}
