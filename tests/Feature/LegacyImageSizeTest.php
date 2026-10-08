<?php

namespace Pine\Commerce\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Pine\Commerce\Services\Media\Images;
use Pine\Commerce\Tests\TestCase;

/**
 * Imported WordPress content links sizes WordPress generated (sale-450x206.jpg) that the import never downloads:
 * /storage/uploads/…-WxH.ext 301s to the nearest file the media library has (LegacyImageController).
 */
class LegacyImageSizeTest extends TestCase
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

    private function image(string $path, int $w, int $h): void
    {
        $im = imagecreatetruecolor($w, $h);
        ob_start();
        imagejpeg($im);
        Storage::disk('public')->put($path, (string) ob_get_clean());
        imagedestroy($im);
    }

    public function test_a_missing_wordpress_size_redirects_to_the_nearest_file(): void
    {
        $this->image('uploads/2025/01/sale.jpg', 480, 220);
        $this->image('uploads/2025/01/sale-400x183.jpg', 400, 183);
        $this->image('uploads/2025/01/sale-150x150.jpg', 150, 150);
        $url = fn (string $file) => asset('storage/uploads/2025/01/'.$file);

        // a live category banner: wider than any variant, so the original
        $this->get('/storage/uploads/2025/01/sale-450x206.jpg')->assertRedirect($url('sale.jpg'))->assertStatus(301);
        // narrower: the smallest variant of the same shape that covers it
        $this->get('/storage/uploads/2025/01/sale-300x137.jpg')->assertRedirect($url('sale-400x183.jpg'));
        // square thumbnails stay square
        $this->get('/storage/uploads/2025/01/sale-100x100.jpg')->assertRedirect($url('sale-150x150.jpg'));
        $this->get('/storage/uploads/2025/01/sale-300x300.jpg')->assertRedirect($url('sale-400x183.jpg'), 'no square that big');
        // WordPress's big-image original
        $this->image('uploads/2025/01/hero-scaled.jpg', 800, 400);
        $this->get('/storage/uploads/2025/01/hero-1024x512.jpg')->assertRedirect($url('hero-scaled.jpg'));
    }

    public function test_anything_else_stays_a_404(): void
    {
        $this->image('uploads/2025/01/sale.jpg', 480, 220);

        $this->get('/storage/uploads/2025/01/gone-450x206.jpg')->assertNotFound();           // no original
        $this->get('/storage/uploads/2025/01/sale-450x206.php')->assertNotFound();           // not an image
        $this->get('/storage/uploads/2025/01/sale.jpg.missing')->assertNotFound();
        $this->get('/storage/uploads/../.env-450x206.jpg')->assertNotFound();

        config(['commerce.features.legacy_content' => false]);
        $this->get('/storage/uploads/2025/01/sale-450x206.jpg')->assertNotFound();
    }
}
