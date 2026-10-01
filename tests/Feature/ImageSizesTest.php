<?php

namespace Pine\Commerce\Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Pine\Commerce\Services\Media\Drivers\GdDriver;
use Pine\Commerce\Services\Media\Drivers\ImageDriver;
use Pine\Commerce\Services\Media\Drivers\ImagickDriver;
use Pine\Commerce\Services\Media\ImageGenerator;
use Pine\Commerce\Services\Media\Images;
use Pine\Commerce\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Image sizes without a database: size maths (WordPress-compatible), generation with both drivers, EXIF orientation,
 * max-dimension downscale, the safety rules (originals untouched, --missing never replaces), variant lookup with
 * graceful fallback, srcset, <x-media-image> and cleanup. Files go to a faked public disk only.
 */
class ImageSizesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['commerce.images' => Images::DEFAULTS]);
        Images::flush();
    }

    protected function tearDown(): void
    {
        Images::flush();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ helpers

    /** A real JPEG/PNG/GIF/WebP of $w × $h on the fake public disk (left half red, right half blue). */
    protected function makeImage(string $path, int $w, int $h, string $format = 'jpg', ?int $orientation = null): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, intdiv($w, 2), $h, (int) imagecolorallocate($im, 255, 0, 0));
        imagefilledrectangle($im, intdiv($w, 2) + 1, 0, $w, $h, (int) imagecolorallocate($im, 0, 0, 255));
        ob_start();
        match ($format) {
            'png' => imagepng($im),
            'gif' => imagegif($im),
            'webp' => imagewebp($im),
            default => imagejpeg($im, null, 90),
        };
        $data = (string) ob_get_clean();
        imagedestroy($im);
        if ($orientation !== null) {
            $data = $this->withExifOrientation($data, $orientation);
        }
        Storage::disk('public')->put($path, $data);

        return $path;
    }

    /** Insert a minimal EXIF APP1 segment (big-endian TIFF, one IFD entry: Orientation) after the JPEG SOI marker. */
    protected function withExifOrientation(string $jpeg, int $orientation): string
    {
        $tiff = "MM\x00\x2A\x00\x00\x00\x08"                 // header, IFD0 at offset 8
            ."\x00\x01"                                         // one entry
            ."\x01\x12\x00\x03\x00\x00\x00\x01".pack('n', $orientation)."\x00\x00" // Orientation SHORT
            ."\x00\x00\x00\x00";                                // no next IFD
        $app1 = "Exif\x00\x00".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
    }

    /** An empty file with a WordPress-style name (only its name matters for lookups; dimensions come from the name). */
    protected function touchVariant(string $path): void
    {
        Storage::disk('public')->put($path, 'x');
    }

    protected function pixels(string $path): array
    {
        $info = getimagesize(Storage::disk('public')->path($path));

        return [$info[0], $info[1]];
    }

    public static function drivers(): array
    {
        return ['gd' => [GdDriver::class], 'imagick' => [ImagickDriver::class]];
    }

    protected function driverOrSkip(string $class): ImageDriver
    {
        $driver = new $class;
        if (! $driver->available()) {
            $this->markTestSkipped($driver->name().' is not installed.');
        }

        return $driver;
    }

    // ------------------------------------------------------------------ maths

    public function test_target_dimensions_follow_wordpress(): void
    {
        $thumb = ['width' => 150, 'height' => 150, 'crop' => true];
        $card = ['width' => 400, 'height' => 400, 'crop' => false];

        // centre crop of a landscape original
        $this->assertSame(['sx' => 200, 'sy' => 0, 'sw' => 800, 'sh' => 800, 'width' => 150, 'height' => 150], Images::target(1200, 800, $thumb));
        // proportional fit
        $this->assertSame(['sx' => 0, 'sy' => 0, 'sw' => 1200, 'sh' => 800, 'width' => 400, 'height' => 267], Images::target(1200, 800, $card));
        $this->assertSame(533, Images::target(1200, 800, ['width' => 800, 'height' => 800, 'crop' => false])['height']);
        // width-only size
        $this->assertSame([300, 400], array_values(array_slice(Images::target(600, 800, ['width' => 300, 'height' => 0, 'crop' => false]), 4)));
        // never upscales: an original not larger than the size gets no variant
        $this->assertNull(Images::target(400, 300, $card));
        $this->assertNull(Images::target(150, 150, $thumb));
        // a crop narrower than the original on one side only is still made (200×100 => 150×100)
        $this->assertSame([150, 100], array_values(array_slice(Images::target(200, 100, $thumb), 4)));
    }

    public function test_sizes_are_normalised_from_every_config_shape(): void
    {
        config(['commerce.images.sizes' => [
            'a' => 300, 'b' => [640, 480, true], 'c' => ['width' => 1000], 'bad name!' => 100, 'zero' => [0, 0], 'd' => ['width' => 500, 'crop' => true],
        ]]);
        Images::flush();

        $this->assertSame([
            'a' => ['width' => 300, 'height' => 300, 'crop' => false],
            'b' => ['width' => 640, 'height' => 480, 'crop' => true],
            'c' => ['width' => 1000, 'height' => 0, 'crop' => false],
            'd' => ['width' => 500, 'height' => 0, 'crop' => false], // a crop needs both sides
        ], Images::sizes());
    }

    // ------------------------------------------------------------------ generation

    #[DataProvider('drivers')]
    public function test_generates_every_size_and_webp_twins_without_touching_the_original(string $class): void
    {
        $generator = new ImageGenerator($this->driverOrSkip($class));
        $path = $this->makeImage('uploads/2026/09/photo.jpg', 1200, 800);
        $before = md5(Storage::disk('public')->get($path));

        $results = $generator->generate($path);

        $statuses = array_column($results, 'status', 'size');
        $this->assertSame('created', $statuses['thumbnail']);
        $this->assertSame('created', $statuses['card']);
        $this->assertSame('created', $statuses['medium']);
        $this->assertSame('skipped', $statuses['large']); // 1200 px is smaller than 1600
        $this->assertSame('created', $statuses['original:webp']);
        foreach (['photo-150x150.jpg' => [150, 150], 'photo-400x267.jpg' => [400, 267], 'photo-800x533.jpg' => [800, 533],
            'photo-150x150.webp' => [150, 150], 'photo-400x267.webp' => [400, 267], 'photo.webp' => [1200, 800]] as $file => $dims) {
            $this->assertSame($dims, $this->pixels('uploads/2026/09/'.$file), $file);
        }
        $this->assertSame('image/webp', getimagesize(Storage::disk('public')->path('uploads/2026/09/photo-400x267.webp'))['mime']);
        $this->assertSame($before, md5(Storage::disk('public')->get($path)), 'the original is never rewritten');
        $this->assertFalse(Storage::disk('public')->exists('uploads/2026/09/photo-1600x1067.jpg'));
        $this->assertSame([], glob(Storage::disk('public')->path('uploads/2026/09').'/*.tmp-*'), 'no temporary files left');

        // the thumbnail is a centre crop: red on the left, blue on the right
        $thumb = imagecreatefromjpeg(Storage::disk('public')->path('uploads/2026/09/photo-150x150.jpg'));
        $left = imagecolorsforindex($thumb, imagecolorat($thumb, 10, 75));
        $right = imagecolorsforindex($thumb, imagecolorat($thumb, 140, 75));
        $this->assertGreaterThan(200, $left['red']);
        $this->assertGreaterThan(200, $right['blue']);
    }

    #[DataProvider('drivers')]
    public function test_png_with_transparency_and_gif_are_resized_in_their_own_format(string $class): void
    {
        $generator = new ImageGenerator($this->driverOrSkip($class));
        config(['commerce.images.webp' => false]);
        $png = $this->makeImage('uploads/logo.png', 600, 300, 'png');
        $gif = $this->makeImage('uploads/still.gif', 600, 300, 'gif');

        $generator->generate($png, ['sizes' => ['card']]);
        $generator->generate($gif, ['sizes' => ['card']]);

        $this->assertSame('image/png', getimagesize(Storage::disk('public')->path('uploads/logo-400x200.png'))['mime']);
        $this->assertSame('image/gif', getimagesize(Storage::disk('public')->path('uploads/still-400x200.gif'))['mime']);
        $this->assertFalse(Storage::disk('public')->exists('uploads/logo.webp'), 'webp off: no twins');

        // a WebP original gets WebP sizes but no twins
        config(['commerce.images.webp' => true]);
        $webp = $this->makeImage('uploads/modern.webp', 600, 300, 'webp');
        $created = array_filter($generator->generate($webp, ['sizes' => ['card', 'thumbnail']]), fn ($r) => $r['status'] === 'created');
        $this->assertSame(['uploads/modern-150x150.webp', 'uploads/modern-400x200.webp'], array_column($created, 'path'));
        $this->assertSame('image/webp', getimagesize(Storage::disk('public')->path('uploads/modern-400x200.webp'))['mime']);
    }

    public function test_missing_mode_never_replaces_and_dry_run_writes_nothing(): void
    {
        $generator = new ImageGenerator;
        $path = $this->makeImage('uploads/a/photo.jpg', 1000, 1000);
        Storage::disk('public')->put('uploads/a/photo-150x150.jpg', 'KEEP ME');

        $dry = $generator->generate($path, ['missing' => true, 'dry_run' => true]);
        $this->assertSame(['exists'], array_values(array_unique(array_column(array_filter($dry, fn ($r) => $r['size'] === 'thumbnail'), 'status'))));
        $this->assertContains('would-create', array_column($dry, 'status'));
        $this->assertSame(['photo-150x150.jpg', 'photo.jpg'], array_map('basename', Storage::disk('public')->files('uploads/a')));

        $generator->generate($path, ['missing' => true]);
        $this->assertSame('KEEP ME', Storage::disk('public')->get('uploads/a/photo-150x150.jpg'));
        $this->assertTrue(Storage::disk('public')->exists('uploads/a/photo-400x400.jpg'));

        // a second run has nothing left to do
        $again = array_count_values(array_column($generator->generate($path, ['missing' => true]), 'status'));
        $this->assertArrayNotHasKey('created', $again);

        // without "missing" the variant is re-encoded from the original
        $generator->generate($path, ['sizes' => ['thumbnail'], 'webp' => false]);
        $this->assertSame([150, 150], $this->pixels('uploads/a/photo-150x150.jpg'));
    }

    public function test_unreadable_missing_and_animated_files_are_reported_not_fatal(): void
    {
        $generator = new ImageGenerator;
        Storage::disk('public')->put('uploads/broken.jpg', 'not an image');
        $this->assertSame('unsupported', $generator->generate('uploads/broken.jpg')[0]['status']);
        $this->assertSame('missing', $generator->generate('uploads/nothing.jpg')[0]['status']);
        $this->assertSame('unsupported', $generator->generate('https://cdn.example.test/x.jpg')[0]['status']);
        $this->assertSame('unsupported', $generator->generate('../../.env')[0]['status']);

        // a two-frame GIF (built with Imagick) is served as uploaded
        if (! extension_loaded('imagick')) {
            return;
        }
        $anim = new \Imagick;
        foreach (['red', 'blue'] as $colour) {
            $frame = new \Imagick;
            $frame->newImage(300, 300, new \ImagickPixel($colour));
            $frame->setImageFormat('gif');
            $frame->setImageDelay(10);
            $anim->addImage($frame);
        }
        Storage::disk('public')->put('uploads/anim.gif', $anim->getImagesBlob());
        $this->assertTrue($generator->isAnimated(Storage::disk('public')->path('uploads/anim.gif'), 'gif'));
        $this->makeImage('uploads/still.gif', 300, 300, 'gif');
        $this->assertFalse($generator->isAnimated(Storage::disk('public')->path('uploads/still.gif'), 'gif'));
        $this->assertSame('unsupported', $generator->generate('uploads/anim.gif')[0]['status']);
        $this->assertSame([], array_filter(Storage::disk('public')->files('uploads'), fn ($f) => str_starts_with(basename($f), 'anim-')));
    }

    // ------------------------------------------------------------------ uploads: orientation + max dimension

    #[DataProvider('drivers')]
    public function test_upload_is_rotated_by_exif_and_scaled_down(string $class): void
    {
        if (! function_exists('exif_read_data')) {
            $this->markTestSkipped('exif extension missing');
        }
        $generator = new ImageGenerator($this->driverOrSkip($class));
        config(['commerce.images.max_dimension' => 300]);
        // stored 600×400 landscape, EXIF says "rotate 90° clockwise" => a 400×600 portrait, capped to 200×300
        $path = $this->makeImage('uploads/phone.jpg', 600, 400, 'jpg', 6);
        $this->assertSame(6, $generator->orientation(Storage::disk('public')->path($path), 'jpg'));

        $generator->processUpload($path);

        $this->assertSame([200, 300], $this->pixels($path));
        $this->assertSame(1, $generator->orientation(Storage::disk('public')->path($path), 'jpg'), 'orientation applied and reset');
        // rotated clockwise: the red left half is now on top
        $im = imagecreatefromjpeg(Storage::disk('public')->path($path));
        $this->assertGreaterThan(200, imagecolorsforindex($im, imagecolorat($im, 100, 20))['red']);
        $this->assertGreaterThan(200, imagecolorsforindex($im, imagecolorat($im, 100, 280))['blue']);
        // variants were made from the fixed original
        $this->assertSame([150, 150], $this->pixels('uploads/phone-150x150.jpg'));
    }

    public function test_orientation_and_downscale_can_be_switched_off(): void
    {
        config(['commerce.images.max_dimension' => null, 'commerce.images.auto_orient' => false, 'commerce.images.generate_on_upload' => false]);
        $path = $this->makeImage('uploads/raw.jpg', 3000, 1000, 'jpg', 6);
        $before = md5(Storage::disk('public')->get($path));

        $this->assertSame([], (new ImageGenerator)->processUpload($path));

        $this->assertSame($before, md5(Storage::disk('public')->get($path)));
        $this->assertSame(['raw.jpg'], array_map('basename', Storage::disk('public')->files('uploads')));
    }

    // ------------------------------------------------------------------ lookup

    /** Imported WordPress media: original 1200×900 with WordPress's own sizes and ShortPixel WebP twins. */
    protected function wordpressMedia(bool $twins = true): string
    {
        $path = $this->makeImage('uploads/2023/02/camera.jpg', 1200, 900);
        foreach (['150x150', '300x225', '300x300', '768x576', '1024x768'] as $size) {
            $this->touchVariant("uploads/2023/02/camera-{$size}.jpg");
            if ($twins) {
                $this->touchVariant("uploads/2023/02/camera-{$size}.webp");
            }
        }
        if ($twins) {
            $this->touchVariant('uploads/2023/02/camera.webp');
        }
        // a different original in the same folder must not be mistaken for a size
        $this->touchVariant('uploads/2023/02/camera-1-300x225.jpg');

        return $path;
    }

    public function test_best_existing_variant_with_fallback_to_the_original(): void
    {
        $path = $this->wordpressMedia();
        $url = fn ($size) => media_url($path, $size);

        $this->assertSame(asset('storage/uploads/2023/02/camera.jpg'), media_url($path), 'no size: unchanged behaviour');
        $this->assertSame(asset('storage/uploads/2023/02/camera-150x150.jpg'), $url('thumbnail'));
        // "card" (400×300 wanted) was never generated: the smallest proportional size that covers it
        $this->assertSame(asset('storage/uploads/2023/02/camera-768x576.jpg'), $url('card'));
        $this->assertSame(asset('storage/uploads/2023/02/camera-1024x768.jpg'), $url('medium'));
        $this->assertSame(asset('storage/uploads/2023/02/camera.jpg'), $url('large'), 'larger than the original: the original');
        $this->assertSame(asset('storage/uploads/2023/02/camera-300x225.jpg'), $url(240));
        $this->assertSame(asset('storage/uploads/2023/02/camera.jpg'), $url('no-such-size'));

        // once generated, the exact size wins
        $this->touchVariant('uploads/2023/02/camera-400x300.jpg');
        Images::flush();
        $this->assertSame(asset('storage/uploads/2023/02/camera-400x300.jpg'), $url('card'));
        $this->assertSame(['path' => 'uploads/2023/02/camera-400x300.jpg', 'width' => 400, 'height' => 300], Images::resolve($path, 'card'));

        // fallbacks
        $this->assertSame(asset('storage/uploads/missing/file.jpg'), media_url('uploads/missing/file.jpg', 'card'));
        $this->assertSame('https://cdn.example.test/a.jpg', media_url('https://cdn.example.test/a.jpg', 'card'));
        $this->assertSame(asset('images/placeholder.png'), media_url(null, 'card'));
        $this->assertSame(asset('storage/uploads/2023/02/camera-150x150.jpg'), media_url('/uploads/2023/02/camera.jpg', 'thumbnail'));
    }

    public function test_srcset_lists_files_of_the_same_shape(): void
    {
        $path = $this->wordpressMedia();
        $base = asset('storage/uploads/2023/02');

        $this->assertSame("{$base}/camera-300x225.jpg 300w, {$base}/camera-768x576.jpg 768w, {$base}/camera-1024x768.jpg 1024w, {$base}/camera.jpg 1200w", image_srcset($path, 'card'));
        $this->assertSame("{$base}/camera-300x225.webp 300w, {$base}/camera-768x576.webp 768w, {$base}/camera-1024x768.webp 1024w, {$base}/camera.webp 1200w", image_srcset($path, 'card', 'webp'));
        // square crops only for the crop size
        $this->assertSame("{$base}/camera-150x150.jpg 150w, {$base}/camera-300x300.jpg 300w", image_srcset($path, 'thumbnail'));
        $this->assertSame('', image_srcset('https://cdn.example.test/a.jpg'));
        $this->assertSame('', image_srcset(null));
    }

    public function test_media_image_component_renders_responsive_markup(): void
    {
        $path = $this->wordpressMedia();
        $base = asset('storage/uploads/2023/02');

        $html = Blade::render('<x-media-image :path="$p" size="card" sizes="50vw" alt="A camera" class="shot" />', ['p' => $path]);
        $this->assertStringStartsWith('<picture><source type="image/webp" srcset="'.$base.'/camera-300x225.webp 300w', $html);
        $this->assertStringContainsString('sizes="50vw"', $html);
        $this->assertStringContainsString('src="'.$base.'/camera-768x576.jpg"', $html);
        $this->assertStringContainsString('width="768" height="576"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString('decoding="async"', $html);
        $this->assertStringContainsString('class="shot"', $html);
        $this->assertStringContainsString('alt="A camera"', $html);
        $this->assertStringEndsWith('</picture>', trim($html));

        $priority = Blade::render('<x-media-image :path="$p" size="large" :priority="true" :picture="false" />', ['p' => $path]);
        $this->assertStringNotContainsString('<picture>', $priority);
        $this->assertStringContainsString('fetchpriority="high"', $priority);
        $this->assertStringNotContainsString('loading=', $priority);
        $this->assertStringContainsString('alt=""', $priority);

        // fallbacks: URL, theme asset, nothing
        $this->assertStringContainsString('src="https://cdn.example.test/a.jpg"', Blade::render('<x-media-image path="https://cdn.example.test/a.jpg" size="card" />'));
        $this->assertStringContainsString('src="/assets/images/hero.jpg"', Blade::render('<x-media-image path="/assets/images/hero.jpg" size="card" />'));
        $this->assertStringContainsString('src="'.asset('images/placeholder.png').'"', Blade::render('<x-media-image :path="null" size="card" />'));
    }

    public function test_no_webp_source_unless_every_candidate_has_a_twin(): void
    {
        $path = $this->wordpressMedia(twins: false);
        $this->touchVariant('uploads/2023/02/camera.webp'); // only the original has one

        $html = Blade::render('<x-media-image :path="$p" size="card" />', ['p' => $path]);
        $this->assertStringNotContainsString('<picture>', $html);
        $this->assertStringContainsString('srcset="', $html);
    }

    // ------------------------------------------------------------------ cleanup

    public function test_delete_variants_removes_generated_files_only(): void
    {
        $path = $this->wordpressMedia();
        $this->touchVariant('uploads/2023/02/camera.jpg.webp');
        $this->touchVariant('uploads/2023/02/camera-scaled.jpg'); // not one of ours (different stem)
        $this->touchVariant('uploads/2023/02/other.jpg');

        $deleted = (new ImageGenerator)->deleteVariants($path);

        $this->assertContains('uploads/2023/02/camera-150x150.jpg', $deleted);
        $this->assertContains('uploads/2023/02/camera.webp', $deleted);
        $this->assertContains('uploads/2023/02/camera.jpg.webp', $deleted);
        $this->assertSame(['camera-1-300x225.jpg', 'camera-scaled.jpg', 'camera.jpg', 'other.jpg'], array_map('basename', Storage::disk('public')->files('uploads/2023/02')));
        $this->assertSame(asset('storage/uploads/2023/02/camera.jpg'), media_url($path, 'thumbnail'), 'memo forgotten after delete');
    }

    public function test_is_original_recognises_variants_and_twins(): void
    {
        $siblings = array_flip(['a.jpg', 'a-300x200.jpg', 'a.webp', 'a.jpg.webp', 'b.webp', 'c-scaled.png', 'c.png', 'd.avif', 'd.webp']);
        $this->assertTrue(Images::isOriginal('u/a.jpg', $siblings));
        $this->assertFalse(Images::isOriginal('u/a-300x200.jpg', $siblings));
        $this->assertFalse(Images::isOriginal('u/a.webp', $siblings));
        $this->assertFalse(Images::isOriginal('u/a.jpg.webp', $siblings));
        $this->assertTrue(Images::isOriginal('u/b.webp', $siblings));
        $this->assertFalse(Images::isOriginal('u/c.png', $siblings));
        $this->assertFalse(Images::isOriginal('u/readme.txt', $siblings));
        $this->assertTrue(Images::isOriginal('u/d.avif', $siblings));
        $this->assertFalse(Images::isOriginal('u/d.webp', $siblings));
    }
}
