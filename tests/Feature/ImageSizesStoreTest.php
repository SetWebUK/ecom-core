<?php

namespace Pine\Commerce\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Pine\Commerce\Models\Media;
use Pine\Commerce\Services\Media\ImageGenerator;
use Pine\Commerce\Services\Media\Images;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/**
 * Image sizes on a fresh store (package defaults, in-memory SQLite, faked public disk): admin uploads get their
 * variants, library items are never overwritten, deleting media removes its variants, the
 * commerce:images:generate command, and the default theme's responsive markup.
 */
class ImageSizesStoreTest extends TestCase
{
    use InstallsNeutralStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNeutralStore();
        Storage::fake('public');
        Images::flush();
    }

    protected function tearDown(): void
    {
        Images::flush();
        $this->tearDownNeutralStore();
        parent::tearDown();
    }

    protected function jpeg(string $path, int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 30, 120, 200));
        ob_start();
        imagejpeg($im, null, 85);
        Storage::disk('public')->put($path, (string) ob_get_clean());
        imagedestroy($im);

        return $path;
    }

    protected function files(string $dir): array
    {
        return array_map('basename', Storage::disk('public')->files($dir));
    }

    public function test_admin_uploads_get_every_size_and_are_capped(): void
    {
        config(['commerce.images.max_dimension' => 1000]);
        $this->actingAs($this->neutralAdmin());

        $json = $this->postJson(route('admin.media.upload'), ['file' => UploadedFile::fake()->image('Beach Day.jpg', 2000, 1500)])
            ->assertCreated()->json();

        $path = $json['path'];
        $dir = dirname($path);
        $this->assertSame([
            'beach-day-150x150.jpg', 'beach-day-150x150.webp', 'beach-day-400x300.jpg', 'beach-day-400x300.webp',
            'beach-day-800x600.jpg', 'beach-day-800x600.webp', 'beach-day.jpg', 'beach-day.webp',
        ], $this->sorted($dir));
        $media = Media::findOrFail($json['id']);
        $this->assertSame([1000, 750], [(int) $media->width, (int) $media->height], 'the row has the stored (capped) size');
        $this->assertSame('/storage/'.$dir.'/beach-day-150x150.jpg', $json['thumb']);
        $this->assertSame(asset('storage/'.$dir.'/beach-day-400x300.jpg'), $media->sizeUrl('card'));
        $this->assertCount(3, $media->variants());

        // a product photo goes through the same pipeline
        $product = $this->postJson(route('admin.products.images.store'), ['file' => UploadedFile::fake()->image('Shirt.png', 900, 900)])
            ->assertCreated()->json('path');
        $this->assertStringStartsWith('uploads/products/', $product);
        Storage::disk('public')->assertExists(preg_replace('/\.png$/', '-400x400.png', $product));
        Storage::disk('public')->assertExists(preg_replace('/\.png$/', '-400x400.webp', $product));
    }

    protected function sorted(string $dir): array
    {
        $files = $this->files($dir);
        sort($files);

        return $files;
    }

    public function test_upload_names_never_clash_with_generated_files(): void
    {
        $this->actingAs($this->neutralAdmin());
        $first = $this->postJson(route('admin.media.upload'), ['file' => UploadedFile::fake()->image('photo.png', 500, 500)])->json('path');
        // photo.webp is photo.png's WebP twin, so a WebP called "photo" gets another name
        $second = $this->postJson(route('admin.media.upload'), ['file' => UploadedFile::fake()->image('photo.webp', 500, 500)])->json('path');
        $this->assertStringEndsWith('/photo.png', $first);
        $this->assertStringEndsWith('/photo-1.webp', $second);
        // a name that looks like a size variant is changed
        $third = $this->postJson(route('admin.media.upload'), ['file' => UploadedFile::fake()->image('banner-1200x600.jpg', 300, 150)])->json('path');
        $this->assertStringEndsWith('/banner-1200-600.jpg', $third);
    }

    public function test_generation_never_overwrites_another_library_item(): void
    {
        $path = $this->jpeg('uploads/2026/01/shot.jpg', 1200, 900);
        // someone uploaded a real image whose name equals shot.jpg's card size and WebP twin
        Storage::disk('public')->put('uploads/2026/01/shot-400x300.jpg', 'ANOTHER UPLOAD');
        Storage::disk('public')->put('uploads/2026/01/shot.webp', 'ANOTHER WEBP');
        Media::create(['path' => 'uploads/2026/01/shot-400x300.jpg', 'filename' => 'shot-400x300.jpg', 'mime_type' => 'image/jpeg']);
        Media::create(['path' => 'uploads/2026/01/shot.webp', 'filename' => 'shot.webp', 'mime_type' => 'image/webp']);

        $statuses = array_column((new ImageGenerator)->generate($path), 'status', 'path');

        $this->assertSame('protected', $statuses['uploads/2026/01/shot-400x300.jpg']);
        $this->assertSame('protected', $statuses['uploads/2026/01/shot.webp']);
        $this->assertSame('ANOTHER UPLOAD', Storage::disk('public')->get('uploads/2026/01/shot-400x300.jpg'));
        $this->assertSame('ANOTHER WEBP', Storage::disk('public')->get('uploads/2026/01/shot.webp'));
        $this->assertSame('created', $statuses['uploads/2026/01/shot-150x150.jpg']);

        // and deleting shot.jpg keeps them too
        $media = Media::create(['path' => $path, 'filename' => 'shot.jpg', 'mime_type' => 'image/jpeg']);
        $this->actingAs($this->neutralAdmin())->deleteJson(route('admin.media.destroy', $media))->assertOk();
        $this->assertSame(['shot-400x300.jpg', 'shot.webp'], $this->sorted('uploads/2026/01'));
        $this->assertModelMissing($media);
    }

    public function test_generate_command_fills_gaps_safely(): void
    {
        $a = $this->jpeg('uploads/2025/05/a.jpg', 1000, 800);
        $b = $this->jpeg('uploads/2025/06/b.jpg', 600, 600);
        $orphan = $this->jpeg('uploads/2025/06/orphan.jpg', 500, 500); // on disk, no library row
        Storage::disk('public')->put('uploads/2025/05/a-150x150.jpg', 'EXISTING');
        Media::create(['path' => $a, 'filename' => 'a.jpg', 'mime_type' => 'image/jpeg']);
        Media::create(['path' => $b, 'filename' => 'b.jpg', 'mime_type' => 'image/jpeg']);
        Media::create(['path' => 'uploads/2025/06/gone.jpg', 'filename' => 'gone.jpg', 'mime_type' => 'image/jpeg']);
        Media::create(['path' => 'uploads/logo.svg', 'filename' => 'logo.svg', 'mime_type' => 'image/svg+xml']);
        $before = [md5(Storage::disk('public')->get($a)), md5(Storage::disk('public')->get($b))];

        // dry run: lists, writes nothing
        $this->artisan('commerce:images:generate', ['--missing' => true, '--dry-run' => true])
            ->expectsOutputToContain('would-create')->assertSuccessful();
        $this->assertSame(['a-150x150.jpg', 'a.jpg'], $this->sorted('uploads/2025/05'));

        // one size, one folder
        $this->artisan('commerce:images:generate', ['--missing' => true, '--size' => ['card'], '--path' => 'uploads/2025/06', '--no-webp' => true])->assertSuccessful();
        $this->assertSame(['b-400x400.jpg', 'b.jpg', 'orphan.jpg'], $this->sorted('uploads/2025/06'));

        // everything missing
        $this->artisan('commerce:images:generate', ['--missing' => true])->assertSuccessful();
        $this->assertSame('EXISTING', Storage::disk('public')->get('uploads/2025/05/a-150x150.jpg'), '--missing never replaces');
        $this->assertContains('a-400x320.jpg', $this->files('uploads/2025/05'));
        $this->assertContains('a-800x640.webp', $this->files('uploads/2025/05'));
        $this->assertNotContains('orphan-150x150.jpg', $this->files('uploads/2025/06'), 'not in the library: only with --scan');
        $this->assertSame($before, [md5(Storage::disk('public')->get($a)), md5(Storage::disk('public')->get($b))], 'originals untouched');

        // re-run: nothing to create
        $this->artisan('commerce:images:generate', ['--missing' => true, '-v' => true])
            ->expectsOutputToContain('exists        card             uploads/2025/05/a-400x320.jpg')
            ->doesntExpectOutputToContain('created')->assertSuccessful();

        // --scan picks up files without a library row; a single file works too
        $this->artisan('commerce:images:generate', ['--missing' => true, '--scan' => true, '--path' => 'uploads/2025/06'])->assertSuccessful();
        $this->assertContains('orphan-150x150.jpg', $this->files('uploads/2025/06'));
        $this->assertNotContains('orphan-150x150-150x150.jpg', $this->files('uploads/2025/06'), 'variants are not treated as originals');
        $this->artisan('commerce:images:generate', ['--path' => $b, '--size' => ['thumbnail'], '--no-interaction' => true])->assertSuccessful();

        // bad input
        $this->artisan('commerce:images:generate', ['--size' => ['huge']])->assertFailed();
        $this->artisan('commerce:images:generate', ['--path' => '../etc'])->assertFailed();
    }

    public function test_default_theme_renders_responsive_product_and_blog_images(): void
    {
        [$product, $category, , $post] = $this->catalogue();
        $this->jpeg('uploads/products/2026/09/linen.jpg', 1200, 1200);
        (new ImageGenerator)->generate('uploads/products/2026/09/linen.jpg');
        $product->images()->create(['path' => 'uploads/products/2026/09/linen.jpg', 'alt' => 'Linen shirt', 'sort_order' => 0]);
        $product->images()->create(['path' => 'uploads/products/2026/09/missing.jpg', 'sort_order' => 1]);
        $this->jpeg('uploads/2026/09/post.jpg', 1600, 900);
        (new ImageGenerator)->generate('uploads/2026/09/post.jpg');
        $post->forceFill(['featured_image' => 'uploads/2026/09/post.jpg'])->save();
        Images::flush();

        $base = asset('storage/uploads/products/2026/09');
        $shop = $this->get($category->url)->assertOk()->getContent();
        $this->assertStringContainsString('<picture><source type="image/webp" srcset="'.$base.'/linen-150x150.webp 150w, '.$base.'/linen-400x400.webp 400w', $shop);
        $this->assertStringContainsString('src="'.$base.'/linen-400x400.jpg"', $shop);
        $this->assertStringContainsString('width="400" height="400"', $shop);
        $this->assertStringContainsString('class="product-card__media has-alt"', $shop);
        // the second image is missing on disk: a plain <img> of the original, no srcset
        $this->assertStringContainsString('<img src="'.$base.'/missing.jpg" alt="" loading="lazy" decoding="async" class="product-card__alt">', $shop);

        $page = $this->get($product->url)->assertOk()->getContent();
        $this->assertStringContainsString('src="'.$base.'/linen.jpg" srcset="'.$base.'/linen-150x150.jpg 150w', $page); // large > original: the original
        $this->assertStringContainsString('fetchpriority="high"', $page);
        $this->assertStringContainsString('data-gallery-image', $page);
        $this->assertStringContainsString('src="'.$base.'/linen-150x150.jpg"', $page); // gallery thumbnail

        $blog = $this->get($post->url)->assertOk()->getContent();
        $this->assertStringContainsString('srcset="'.asset('storage/uploads/2026/09/post-400x225.webp').' 400w', $blog);
    }
}
