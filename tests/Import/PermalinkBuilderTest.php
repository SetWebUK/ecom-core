<?php

namespace Pine\Commerce\Tests\Import;

use PHPUnit\Framework\TestCase;
use Pine\Commerce\Import\Permalinks\PermalinkBuilder;

class PermalinkBuilderTest extends TestCase
{
    /** clothing(10) > linen(12) > oxford(15); clothing > denim(11); accessories(20) */
    private const TREE = [
        10 => ['slug' => 'clothing', 'parent' => 0],
        11 => ['slug' => 'denim', 'parent' => 10],
        12 => ['slug' => 'linen', 'parent' => 10],
        15 => ['slug' => 'oxford', 'parent' => 12],
        20 => ['slug' => 'accessories', 'parent' => 0],
    ];

    public function test_category_paths_use_the_woocommerce_category_base(): void
    {
        $default = new PermalinkBuilder('', '', '', '', self::TREE);
        $this->assertSame('clothing/linen/oxford', $default->categoryTreePath(15));
        $this->assertSame('product-category/clothing/linen/oxford', $default->categoryPath(15));

        $custom = new PermalinkBuilder('', '', '/shop-by/', '', self::TREE);
        $this->assertSame('shop-by/accessories', $custom->categoryPath(20));
        $this->assertNull($custom->categoryPath(999));
    }

    public function test_product_paths_follow_the_product_base(): void
    {
        $this->assertSame('product/shirt-13', (new PermalinkBuilder('', '', '', '', self::TREE))->productPath('shirt-13', [12]));
        $this->assertSame('product/shirt-13', (new PermalinkBuilder('', '/product/', '', '', self::TREE))->productPath('shirt-13', [12]));
        $this->assertSame('shop/shirt-13', (new PermalinkBuilder('', '/shop/', '', '', self::TREE))->productPath('shirt-13', [12]));
    }

    public function test_product_cat_placeholder_picks_the_category_like_woocommerce(): void
    {
        $b = new PermalinkBuilder('', '/product/%product_cat%/', '', '', self::TREE);

        // wc_product_post_type_link: sort by parent DESC, term_id ASC → oxford (parent 12) beats denim (parent 10)
        $this->assertSame('product/clothing/linen/oxford/oxford-01', $b->productPath('oxford-01', [11, 15, 20]));
        // same parent → lowest term id
        $this->assertSame('product/clothing/denim/x', $b->productPath('x', [12, 11]));
        // the SEO plugin's primary term wins when it is one of the product's categories
        $this->assertSame('product/accessories/oxford-01', $b->productPath('oxford-01', [11, 15, 20], 20));
        $this->assertSame('product/clothing/linen/oxford/oxford-01', $b->productPath('oxford-01', [11, 15, 20], 999));
        $this->assertSame('product/uncategorized/oxford-01', $b->productPath('oxford-01', []));
    }

    public function test_deepest_category_fallback(): void
    {
        $b = new PermalinkBuilder('', '', '', '', self::TREE);
        $this->assertSame(15, $b->deepestCategory([10, 15, 11]));
        $this->assertSame(11, $b->deepestCategory([12, 11]));
        $this->assertNull($b->deepestCategory([]));
    }

    public function test_post_paths_from_permalink_structure(): void
    {
        $post = ['slug' => 'hello-world', 'id' => 42, 'date' => '2024-03-07 09:05:01', 'category' => 'news/uk', 'author' => 'anna'];

        $this->assertSame('hello-world', (new PermalinkBuilder('/%postname%/'))->postPath($post));
        $this->assertSame('blog/hello-world', (new PermalinkBuilder('/blog/%postname%/'))->postPath($post));
        $this->assertSame('2024/03/07/hello-world', (new PermalinkBuilder('/%year%/%monthnum%/%day%/%postname%/'))->postPath($post));
        $this->assertSame('archives/42', (new PermalinkBuilder('/archives/%post_id%'))->postPath($post));
        $this->assertSame('news/uk/hello-world', (new PermalinkBuilder('/%category%/%postname%/'))->postPath($post));
        $this->assertSame('anna/hello-world', (new PermalinkBuilder('/%author%/%postname%/'))->postPath($post));
        $this->assertNull((new PermalinkBuilder(''))->postPath($post)); // plain permalinks: ?p=42
    }

    public function test_page_paths_are_hierarchical_and_the_front_page_is_root(): void
    {
        $pages = [1 => ['slug' => 'about', 'parent' => 0], 2 => ['slug' => 'team', 'parent' => 1], 3 => ['slug' => 'home', 'parent' => 0]];

        $this->assertSame('about/team', PermalinkBuilder::pagePath(2, $pages, 3));
        $this->assertSame('', PermalinkBuilder::pagePath(3, $pages, 3));
        $this->assertSame('home', PermalinkBuilder::pagePath(3, $pages, 0));
        $this->assertNull(PermalinkBuilder::pagePath(9, $pages));
    }
}
