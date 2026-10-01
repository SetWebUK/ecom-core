<?php

namespace Pine\Commerce\Tests\Import;

use Pine\Commerce\Import\AdapterRegistry;
use Pine\Commerce\Import\ImportContext;
use Pine\Commerce\Import\RenderedSite\RenderedSource;
use Pine\Commerce\Import\Source\SiteProfile;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Tests\TestCase;

/** Source-site URLs from settings (WooCommerce bases) and permalink plugins (Premmerce, Permalink Manager). */
class SourcePermalinksTest extends TestCase
{
    private function seedSite(): void
    {
        WpFixture::boot();
        $clothing = WpFixture::term(10, 'product_cat', 'Clothing', 'clothing');
        $linen = WpFixture::term(12, 'product_cat', 'Linen', 'linen', 10);
        $sale = WpFixture::term(14, 'product_cat', 'Sale', 'sale', 10);
        $acc = WpFixture::term(20, 'product_cat', 'Accessories', 'accessories');
        $p1 = WpFixture::post(['ID' => 100, 'post_type' => 'product', 'post_name' => 'shirt', 'post_title' => 'Shirt']);
        WpFixture::relate($p1, $linen);
        WpFixture::relate($p1, $sale);
        $p2 = WpFixture::post(['ID' => 101, 'post_type' => 'product', 'post_name' => 'bag', 'post_title' => 'Bag'], ['rank_math_primary_product_cat' => '20']);
        WpFixture::relate($p2, $clothing);
        WpFixture::relate($p2, $acc);
        WpFixture::post(['ID' => 102, 'post_type' => 'product', 'post_name' => 'draft', 'post_status' => 'draft']);
        WpFixture::post(['ID' => 5, 'post_type' => 'page', 'post_name' => 'about']);
        WpFixture::post(['ID' => 6, 'post_type' => 'page', 'post_name' => 'team', 'post_parent' => 5]);
        WpFixture::post(['ID' => 7, 'post_type' => 'post', 'post_name' => 'news', 'post_date' => '2024-05-06 10:00:00']);
    }

    private function permalinks(array $plugins, array $options = [], string $structure = '/%year%/%postname%/', array $woo = []): array
    {
        foreach ($options as $k => $v) {
            WpFixture::option($k, $v);
        }
        $site = new SiteProfile(activePlugins: $plugins, permalinkStructure: $structure, pageOnFront: 0,
            woo: ['permalinks' => $woo + ['product_base' => '', 'category_base' => '', 'tag_base' => '', 'attribute_base' => '']]);
        $ctx = (new ImportContext(null, new WordPressSource(WpFixture::CONNECTION), $site, new AdapterRegistry, new RenderedSource([])))->boot();

        return $ctx->permalinks()->all();
    }

    public function test_woocommerce_defaults(): void
    {
        $this->seedSite();
        $links = $this->permalinks(['woocommerce/woocommerce.php']);

        $this->assertSame('product-category/clothing/linen', $links['categories'][12]);
        $this->assertSame('product/shirt', $links['products'][100]);
        $this->assertArrayNotHasKey(102, $links['products']); // drafts have no pretty URL
        $this->assertSame('about/team', $links['pages'][6]);
        $this->assertSame('2024/news', $links['posts'][7]);
    }

    public function test_product_cat_base_with_rank_math_primary_term(): void
    {
        $this->seedSite();
        $links = $this->permalinks(['woocommerce/woocommerce.php', 'seo-by-rank-math/rank-math.php'], [], '/%postname%/',
            ['product_base' => '/shop/%product_cat%/', 'category_base' => 'range']);

        $this->assertSame('shop/clothing/linen/shirt', $links['products'][100]); // same parent → lowest term id
        $this->assertSame('shop/accessories/bag', $links['products'][101]); // Rank Math primary
        $this->assertSame('range/clothing/sale', $links['categories'][14]);
    }

    public function test_premmerce_removes_bases_and_uses_the_highest_term_id(): void
    {
        $this->seedSite();
        $links = $this->permalinks(['woocommerce/woocommerce.php', 'seo-by-rank-math/rank-math.php', 'woo-permalink-manager/premmerce-url-manager.php'],
            ['premmerce_permalink_manager' => ['category' => 'hierarchical', 'product' => 'hierarchical', 'use_primary_category' => 'on']]);

        $this->assertSame('clothing/sale/shirt', $links['products'][100]); // highest term id (14)
        $this->assertSame('accessories/bag', $links['products'][101]);   // SEO primary
        $this->assertSame('clothing/linen', $links['categories'][12]);

        $this->seedSite();
        $links = $this->permalinks(['woocommerce/woocommerce.php', 'woo-permalink-manager/premmerce-url-manager.php'],
            ['premmerce_permalink_manager' => ['category' => 'slug', 'product' => 'slug']]);
        $this->assertSame('shirt', $links['products'][100]);
        $this->assertSame('linen', $links['categories'][12]);
    }

    public function test_permalink_manager_custom_uris_win(): void
    {
        $this->seedSite();
        $links = $this->permalinks(['woocommerce/woocommerce.php', 'permalink-manager/permalink-manager.php', 'woo-permalink-manager/premmerce-url-manager.php'],
            ['permalink-manager-uris' => ['100' => 'deals/shirt-13', 'tax-12' => 'brands/linen', '5' => 'company'],
                'premmerce_permalink_manager' => ['category' => 'hierarchical', 'product' => 'hierarchical']]);

        $this->assertSame('deals/shirt-13', $links['products'][100]);
        $this->assertSame('accessories/bag', $links['products'][101]); // Premmerce fills the rest (no SEO plugin → highest term id 20)
        $this->assertSame('brands/linen', $links['categories'][12]);
        $this->assertSame('company', $links['pages'][5]);
    }
}
