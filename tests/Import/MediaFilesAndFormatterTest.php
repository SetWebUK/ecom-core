<?php

namespace Pine\Commerce\Tests\Import;

use Illuminate\Support\Facades\File;
use Pine\Commerce\Import\AdapterRegistry;
use Pine\Commerce\Import\ImportContext;
use Pine\Commerce\Import\RenderedSite\RenderedSource;
use Pine\Commerce\Import\Source\SiteProfile;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Import\Steps\MediaFilesStep;
use Pine\Commerce\Import\Support\Formatter;
use Pine\Commerce\Tests\TestCase;

class MediaFilesAndFormatterTest extends TestCase
{
    private string $tmp;

    protected function tearDown(): void
    {
        if (isset($this->tmp)) {
            File::deleteDirectory($this->tmp);
        }
        Formatter::configure(['hosts' => [], 'upload_bases' => [], 'replace' => [], 'components' => ['wp_sitemap_page' => 'sitemap'], 'site_name' => '']);
        parent::tearDown();
    }

    public function test_copy_uploads_copies_media_verifies_existing_files_and_quarantines_executables(): void
    {
        $this->tmp = sys_get_temp_dir().'/pine-media-'.uniqid();
        $src = $this->tmp.'/wp/wp-content/uploads';
        foreach (['2024/05/a.jpg' => 'jpg', '2024/05/b.pdf' => 'pdf', '2024/05/shell.php' => '<?php', 'cache/x.css' => 'c',
            'woocommerce_uploads/secret.zip' => 'z', '.htaccess' => 'deny'] as $file => $content) {
            File::ensureDirectoryExists(dirname($src.'/'.$file));
            file_put_contents($src.'/'.$file, $content);
        }
        config(['filesystems.disks.import_test' => ['driver' => 'local', 'root' => $this->tmp.'/public']]);
        File::ensureDirectoryExists($this->tmp.'/public/uploads/2024/05');
        file_put_contents($this->tmp.'/public/uploads/2024/05/a.jpg', 'jpg'); // already there, same size

        WpFixture::boot();
        $ctx = (new ImportContext(null, new WordPressSource(WpFixture::CONNECTION), new SiteProfile, new AdapterRegistry([]), new RenderedSource([]),
            ['media' => ['disk' => 'import_test', 'quarantine_path' => $this->tmp.'/quarantine', 'private_path' => $this->tmp.'/private']]))->boot();
        $ctx->options = ['copy_uploads' => true, 'uploads_path' => $src];
        $step = new MediaFilesStep;
        $this->assertTrue($step->shouldRun($ctx));
        $step->run($ctx);

        $this->assertFileExists($this->tmp.'/public/uploads/2024/05/b.pdf');
        $this->assertFileDoesNotExist($this->tmp.'/public/uploads/2024/05/shell.php');
        $this->assertFileDoesNotExist($this->tmp.'/public/uploads/.htaccess');
        $this->assertFileDoesNotExist($this->tmp.'/public/uploads/cache/x.css');
        $this->assertFileDoesNotExist($this->tmp.'/public/uploads/woocommerce_uploads/secret.zip');
        $this->assertFileExists($this->tmp.'/quarantine/2024/05/shell.php');
        $this->assertFileExists($this->tmp.'/quarantine/.htaccess');
        $this->assertFileExists($this->tmp.'/private/woocommerce_uploads/secret.zip');
        $this->assertStringContainsString('verified 1', $ctx->summary['Upload files']['note']);
        $this->assertFalse(is_link($this->tmp.'/public/uploads/2024/05/b.pdf'));

    }

    public function test_formatter_is_configured_per_site(): void
    {
        Formatter::configure([
            'hosts' => ['old.example.com', 'example.com'],
            'upload_bases' => ['https://cdn.example.net/media'],
            'replace' => ['staging.example.com' => 'example.com'],
            'components' => ['my_form' => 'contact-form'],
            'site_name' => 'Example Shop',
        ]);

        $html = '<a href="https://old.example.com/about/">A</a><img src="https://example.com/wp-content/uploads/2024/a.jpg">'
            .'<img src="https://cdn.example.net/media/2024/b.jpg"><p>[my_form id="3"]</p><a href="https://other.org/x">x</a> mail@staging.example.com';
        $clean = Formatter::clean($html);

        $this->assertStringContainsString('href="/about/"', $clean);
        $this->assertStringContainsString('src="/storage/uploads/2024/a.jpg"', $clean);
        $this->assertStringContainsString('src="/storage/uploads/2024/b.jpg"', $clean);
        $this->assertStringContainsString('<div data-component="contact-form"></div>', $clean);
        $this->assertStringContainsString('https://other.org/x', $clean);
        $this->assertStringContainsString('mail@example.com', $clean);
        $this->assertSame('uploads/2024/b.jpg', Formatter::uploadPath('https://cdn.example.net/media/2024/b.jpg'));
        $this->assertSame('Shoes | Example Shop', Formatter::seo('%title% %sep% %sitename%', ['title' => 'Shoes']));
        $this->assertSame('<p>Hi  there</p>', Formatter::stripShortcodes('<p>Hi [vc_row][gallery ids="1"] there[/vc_row]</p>'));
        $this->assertSame('[my_form]', Formatter::stripShortcodes('[my_form]'));
    }
}
