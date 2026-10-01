<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Http\Controllers\Admin\Concerns\AdminIndex;
use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Http\Requests\Admin\Content\ContentBulkRequest;
use Pine\Commerce\Models\Category;
use Pine\Commerce\Models\Media;
use Pine\Commerce\Models\MenuItem;
use Pine\Commerce\Models\Page;
use Pine\Commerce\Models\Post;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\ProductImage;
use Pine\Commerce\Models\ProductVariation;
use Pine\Commerce\Models\Setting;
use Pine\Commerce\Services\Admin\LocalTime;
use Pine\Commerce\Services\Media\ImageGenerator;
use Pine\Commerce\Services\Media\Images;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * Media library page: grid/list of the media table, filters, drag-and-drop upload (through admin.media.upload in core),
 * a detail drawer (alt/title, copy URL, where the image is used, delete) and "Scan uploads folder" to create
 * rows for files that are on disk but not in the library.
 */
class MediaController extends Controller
{
    use AdminIndex;

    public const TYPES = [
        'jpeg' => 'JPG',
        'png' => 'PNG',
        'webp' => 'WebP',
        'gif' => 'GIF',
        'avif' => 'AVIF',
        'svg+xml' => 'SVG',
        'other' => 'Other files',
    ];

    public const SORTS = ['id', 'filename', 'size', 'width'];

    /** Image files the scanner adds (SVG rows exist from the import but are never uploaded through the admin). */
    public const SCAN_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'];

    public const SCAN_LIMIT = 1000;

    public function index(Request $request): View
    {
        $view = $request->query('view') === 'list' ? 'list' : 'grid';
        $q = $this->searchTerm($request);
        $folders = Media::query()->whereNotNull('folder')->distinct()->orderByDesc('folder')->pluck('folder');
        $folder = $this->filterValue($request, 'folder', $folders->flip()->all());
        $type = $this->filterValue($request, 'type', self::TYPES);
        [$sort, $direction] = $this->sorting($request, self::SORTS, 'id', 'desc');

        $media = Media::query()
            ->when($q !== '', fn (Builder $query) => $query->where(fn (Builder $w) => $w
                ->where('filename', 'like', $this->like($q))
                ->orWhere('title', 'like', $this->like($q))
                ->orWhere('alt', 'like', $this->like($q))
                ->orWhere('path', 'like', $this->like($q))))
            ->when($folder, fn (Builder $query) => $query->where('folder', $folder))
            ->when($type && $type !== 'other', fn (Builder $query) => $query->where('mime_type', 'image/'.$type))
            ->when($type === 'other', fn (Builder $query) => $query->where(fn (Builder $w) => $w->whereNull('mime_type')->orWhere('mime_type', 'not like', 'image/%')))
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($this->perPage($request, $view === 'grid' ? 50 : 25))
            ->withQueryString();

        return view('commerce::admin.media.index', [
            'media' => $media,
            'view' => $view,
            'q' => $q,
            'folderOptions' => $folders->mapWithKeys(fn ($f) => [$f => $f])->all(),
            'total' => Media::count(),
            'chips' => array_filter([
                'folder' => $folder ? 'Folder: '.$folder : null,
                'type' => $type ? 'Type: '.self::TYPES[$type] : null,
            ]),
            'isFiltered' => $q !== '' || $folder || $type,
        ]);
    }

    /** JSON for the detail drawer. */
    public function show(Media $media): JsonResponse
    {
        $usage = $this->usage($media);

        return response()->json([
            'id' => $media->id,
            'path' => $media->path,
            'url' => '/storage/'.ltrim($media->path, '/'),
            'absolute_url' => url('storage/'.ltrim($media->path, '/')),
            'filename' => $media->filename,
            'title' => (string) $media->title,
            'alt' => (string) $media->alt,
            'type' => self::typeLabel($media->mime_type),
            'size' => $media->size ? static::bytes((int) $media->size) : null,
            'dimensions' => $media->width && $media->height ? $media->width.' × '.$media->height.' px' : null,
            'folder' => $media->folder,
            'added' => $media->created_at ? LocalTime::format($media->created_at, 'j M Y, H:i') : null,
            'exists' => Storage::disk('public')->exists($media->path),
            'usage' => $usage,
            'usage_count' => count($usage),
            'update_url' => route('admin.media.update', $media),
            'delete_url' => route('admin.media.destroy', $media),
        ]);
    }

    public function update(Request $request, Media $media): JsonResponse
    {
        $data = $request->validate([
            'alt' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
        ], [], ['alt' => 'alt text']);

        $media->update([
            'alt' => filled($data['alt'] ?? null) ? trim($data['alt']) : null,
            'title' => filled($data['title'] ?? null) ? trim($data['title']) : null,
        ]);

        return response()->json(['message' => 'Image details saved', 'alt' => (string) $media->alt, 'title' => (string) $media->title]);
    }

    public function destroy(Request $request, Media $media): JsonResponse|RedirectResponse
    {
        $name = $media->filename;
        $this->deleteMedia($media);

        if ($request->expectsJson()) {
            return response()->json(['message' => "{$name} deleted"]);
        }

        return redirect()->route('admin.media.index')->with('success', "{$name} deleted.");
    }

    public function bulk(ContentBulkRequest $request): RedirectResponse
    {
        $count = 0;
        foreach (Media::whereIn('id', $request->ids())->get() as $media) {
            $this->deleteMedia($media);
            $count++;
        }

        return back()->with('success', $count.' '.Str::plural('file', $count).' deleted.');
    }

    /** Create library rows for image files in public/storage/uploads/YYYY/MM/ that have none. */
    public function scan(): RedirectResponse
    {
        $disk = Storage::disk('public');
        $known = Media::query()->pluck('path')->flip();
        $added = 0;
        $remaining = 0;

        foreach ($disk->directories('uploads') as $year) {
            if (! preg_match('#^uploads/\d{4}$#', $year)) {
                continue;
            }
            foreach ($disk->directories($year) as $month) {
                if (! preg_match('#^uploads/\d{4}/\d{2}$#', $month)) {
                    continue;
                }
                $files = collect($disk->files($month));
                $names = $files->map(fn ($f) => basename($f))->flip();
                foreach ($files as $path) {
                    if (isset($known[$path]) || ! static::isOriginal($path, $names)) {
                        continue;
                    }
                    if ($added >= self::SCAN_LIMIT) {
                        $remaining++;

                        continue;
                    }
                    $absolute = $disk->path($path);
                    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                    $size = $extension === 'svg' ? null : @getimagesize($absolute);
                    if ($extension !== 'svg' && ! $size) {
                        continue; // not a readable image
                    }
                    $mime = $extension === 'svg' ? 'image/svg+xml' : ($size['mime'] ?? null);
                    try {
                        Media::create([
                            'path' => $path,
                            'filename' => basename($path),
                            'title' => Str::of(pathinfo($path, PATHINFO_FILENAME))->replace(['-', '_'], ' ')->squish()->limit(190, '')->value(),
                            'mime_type' => $mime,
                            'size' => @filesize($absolute) ?: null,
                            'width' => $size[0] ?? null,
                            'height' => $size[1] ?? null,
                            'folder' => substr($month, strlen('uploads/')),
                        ]);
                        $known[$path] = true;
                        $added++;
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            }
        }

        if ($added === 0) {
            return back()->with('info', 'Nothing new – every image in the uploads folder is already in the library.');
        }
        $message = $added.' '.Str::plural('image', $added).' added to the library.';
        if ($remaining > 0) {
            return back()->with('warning', $message.' '.$remaining.' more to go – click “Scan uploads folder” again.');
        }

        return back()->with('success', $message);
    }

    /**
     * Is this file an original upload (not a WordPress resized copy, WebP/AVIF twin or the unscaled original of a -scaled image)?
     *
     * @param  \Illuminate\Support\Collection<string,int>  $siblings  file names in the same folder (flipped)
     */
    public static function isOriginal(string $path, $siblings): bool
    {
        return Images::isOriginal($path, $siblings, self::SCAN_EXTENSIONS);
    }

    /**
     * Where an image is referenced: product images, variations, categories, pages (content + blocks), posts, menus, settings.
     *
     * @return list<array{type:string, label:string, url:?string}>
     */
    protected function usage(Media $media): array
    {
        $path = ltrim($media->path, '/');
        $stem = preg_replace('/\.[a-z0-9]+$/i', '', $path);           // uploads/2024/05/photo
        $needle = '%'.addcslashes($stem, '%_\\').'%';                    // also finds photo-300x200.jpg in HTML
        $exact = '#'.preg_quote($stem, '#').'(-\d+x\d+)?(-scaled)?\.[a-z0-9]+#i';
        $admin = fn (string $route, $model) => Route::has($route) ? route($route, $model) : null;
        $found = [];

        foreach (ProductImage::query()->where('path', $path)->with('product:id,name')->limit(20)->get(['id', 'product_id']) as $image) {
            if ($image->product) {
                $found['p'.$image->product_id] = ['type' => 'Product image', 'label' => $image->product->name, 'url' => $admin('admin.products.edit', $image->product_id)];
            }
        }
        foreach (ProductVariation::query()->where('image', $path)->with('product:id,name')->limit(20)->get(['id', 'product_id']) as $variation) {
            if ($variation->product) {
                $found['p'.$variation->product_id] ??= ['type' => 'Product variation', 'label' => $variation->product->name, 'url' => $admin('admin.products.edit', $variation->product_id)];
            }
        }
        foreach (Product::query()->where(fn ($w) => $w->where('description', 'like', $needle)->orWhere('short_description', 'like', $needle))->limit(20)->get(['id', 'name', 'description', 'short_description']) as $product) {
            if (preg_match($exact, $product->description.' '.$product->short_description)) {
                $found['p'.$product->id] ??= ['type' => 'Product description', 'label' => $product->name, 'url' => $admin('admin.products.edit', $product->id)];
            }
        }
        foreach (Category::query()->where(fn ($w) => $w->where('image', $path)->orWhere('description', 'like', $needle)->orWhere('extra_content', 'like', $needle))->limit(20)->get(['id', 'name', 'image', 'description', 'extra_content']) as $category) {
            if ($category->image === $path || preg_match($exact, $category->description.' '.$category->extra_content)) {
                $found['c'.$category->id] = ['type' => 'Category', 'label' => $category->name, 'url' => $admin('admin.categories.edit', $category->id)];
            }
        }
        foreach (Page::query()->where(fn ($w) => $w->where('content', 'like', $needle)->orWhere('blocks', 'like', '%'.addcslashes(str_replace('/', '\\\\/', $stem), '%_').'%')->orWhere('blocks', 'like', $needle))->limit(20)->get(['id', 'title', 'content', 'blocks']) as $page) {
            $haystack = $page->content.' '.str_replace('\\/', '/', json_encode($page->blocks));
            if (preg_match($exact, $haystack)) {
                $found['pg'.$page->id] = ['type' => 'Page', 'label' => $page->title, 'url' => route('admin.pages.edit', $page)];
            }
        }
        foreach (Post::query()->where(fn ($w) => $w->where('featured_image', $path)->orWhere('content', 'like', $needle))->limit(20)->get(['id', 'title', 'featured_image', 'content']) as $post) {
            if ($post->featured_image === $path || preg_match($exact, (string) $post->content)) {
                $found['po'.$post->id] = ['type' => $post->featured_image === $path ? 'Blog post image' : 'Blog post', 'label' => $post->title, 'url' => route('admin.posts.edit', $post)];
            }
        }
        foreach (MenuItem::query()->where(fn ($w) => $w->where('icon', $path)->orWhere('icon', 'like', '%'.addcslashes($path, '%_\\')))->with('menu:id,name')->limit(20)->get(['id', 'menu_id', 'label']) as $item) {
            $found['m'.$item->menu_id] ??= ['type' => 'Menu', 'label' => ($item->menu?->name ?? 'Menu').' – '.strip_tags($item->label), 'url' => route('admin.menus.edit', $item->menu_id)];
        }
        foreach (Setting::query()->where('value', 'like', $needle)->limit(20)->get(['key', 'value']) as $setting) {
            if (preg_match($exact, (string) $setting->value)) {
                $group = explode('.', $setting->key)[0];
                $found['s'.$setting->key] = ['type' => 'Setting', 'label' => $setting->key, 'url' => $group === 'seo' ? route('admin.settings.edit', 'seo') : route('admin.settings.edit', 'general')];
            }
        }

        return array_values($found);
    }

    /** Delete the row, the file and its generated sizes (photo-150x150.jpg, photo.webp, photo.jpg.webp …). */
    protected function deleteMedia(Media $media): void
    {
        $path = ltrim($media->path, '/');
        if (str_starts_with($path, 'uploads/') && ! str_contains($path, '..') && Media::where('path', $path)->whereKeyNot($media->id)->doesntExist()) {
            // files that are library items of their own are kept (ImageGenerator::deleteVariants)
            (new ImageGenerator)->deleteVariants($path, withOriginal: true);
        }
        $media->delete();
    }

    public static function typeLabel(?string $mime): string
    {
        if (! $mime) {
            return 'File';
        }
        $sub = str_starts_with($mime, 'image/') ? substr($mime, 6) : null;

        return $sub && isset(self::TYPES[$sub]) ? self::TYPES[$sub] : strtoupper((string) Str::afterLast($mime, '/'));
    }

    public static function bytes(int $bytes): string
    {
        return match (true) {
            $bytes >= 1048576 => number_format($bytes / 1048576, 1).' MB',
            $bytes >= 1024 => number_format($bytes / 1024).' KB',
            default => $bytes.' bytes',
        };
    }
}
