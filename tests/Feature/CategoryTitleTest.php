<?php

namespace Pine\Commerce\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Pine\Commerce\Models\Setting;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/** Settings › SEO › Category page title: the default title of category pages without their own SEO title. */
class CategoryTitleTest extends TestCase
{
    use InstallsNeutralStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNeutralStore();
    }

    protected function tearDown(): void
    {
        $this->tearDownNeutralStore();
        parent::tearDown();
    }

    private function title(string $url): string
    {
        preg_match('#<title>(.*?)</title>#s', $this->get($url)->assertOk()->getContent(), $m);

        return html_entity_decode(trim($m[1] ?? ''), ENT_QUOTES);
    }

    public function test_the_default_follows_wordpress_and_a_rank_math_shop_can_drop_archives(): void
    {
        [, $category] = $this->catalogue();
        $suffix = setting('seo.title_suffix');

        $this->assertSame($category->name.' Archives '.$suffix, $this->title($category->url));

        Setting::set('seo.category_title', '%name%', 'seo');
        Cache::forget('settings.all');
        Setting::flushMemo();
        $this->assertSame($category->name.' '.$suffix, $this->title($category->url));

        $category->forceFill(['meta_title' => 'Linen Shirts for Summer | Shop'])->save();
        \Pine\Commerce\Services\Catalog\Categories::flush();
        $this->assertSame('Linen Shirts for Summer | Shop', $this->title($category->url), 'a category\'s own SEO title wins');
    }
}
