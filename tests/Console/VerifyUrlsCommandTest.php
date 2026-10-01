<?php

namespace Pine\Commerce\Tests\Console;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pine\Commerce\Tests\TestCase;

class VerifyUrlsCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/verify-urls-'.uniqid());
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_sitemap_index_urls_must_answer_200_or_301_to_200_on_the_new_site(): void
    {
        Http::fake([
            'https://old.example/sitemap_index.xml' => Http::response('<?xml version="1.0"?><sitemapindex><sitemap><loc>https://old.example/page-sitemap.xml</loc></sitemap></sitemapindex>'),
            'https://old.example/page-sitemap.xml' => Http::response('<urlset><url><loc>https://old.example/</loc></url><url><loc>https://old.example/about/</loc></url>'
                .'<url><loc>https://old.example/old-page/</loc></url><url><loc>https://old.example/gone/</loc></url><url><loc>https://old.example/moved-to-404/</loc></url></urlset>'),
            'https://new.example/' => Http::response('home'),
            'https://new.example/about/' => Http::response('about'),
            'https://new.example/old-page/' => Http::response('', 301, ['Location' => 'https://new.example/about/']),
            'https://new.example/moved-to-404/' => Http::response('', 301, ['Location' => '/nowhere/']),
            'https://new.example/nowhere/' => Http::response('', 404),
            'https://new.example/gone/' => Http::response('', 404),
        ]);
        $report = $this->dir.'/report.csv';

        $this->artisan('commerce:verify-urls', ['source' => 'https://old.example/sitemap_index.xml', '--base' => 'https://new.example', '--report' => $report])
            ->expectsOutputToContain('5 checked: 3 OK (1 via 301/308), 2 failed.')
            ->assertFailed();

        $rows = array_map('str_getcsv', array_slice(file($report, FILE_IGNORE_NEW_LINES), 1));
        $results = array_column($rows, 5, 1);
        ksort($results);
        $this->assertSame(['/' => 'OK', '/about/' => 'OK', '/gone/' => 'FAIL', '/moved-to-404/' => 'FAIL', '/old-page/' => 'OK'], $results);
    }

    public function test_plain_list_file_all_passing(): void
    {
        File::put($this->dir.'/urls.txt', "# old urls\nhttps://old.example/shop/\n/contact/\n\nnot a url\n");
        Http::fake(['https://new.example/*' => Http::response('ok')]);

        $this->artisan('commerce:verify-urls', ['source' => $this->dir.'/urls.txt', '--base' => 'https://new.example/'])
            ->expectsOutputToContain('2 checked: 2 OK')
            ->assertSuccessful();
    }
}
