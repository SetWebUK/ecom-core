<?php

namespace Pine\Commerce\Tests\Import;

use Pine\Commerce\Import\Adapters;
use Pine\Commerce\Import\AdapterRegistry;
use Pine\Commerce\Import\Contracts\PermalinkProvider;
use Pine\Commerce\Import\Contracts\RedirectProvider;
use Pine\Commerce\Import\Contracts\SeoProvider;
use Pine\Commerce\Import\ImportContext;
use Pine\Commerce\Import\Pipeline;
use Pine\Commerce\Import\RenderedSite\RenderedSource;
use Pine\Commerce\Import\Source\SiteProfile;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Import\Steps\AbstractStep;
use Pine\Commerce\Tests\TestCase;

class AdapterRegistryTest extends TestCase
{
    private function context(SiteProfile $site, AdapterRegistry $registry, array $config = []): ImportContext
    {
        return (new ImportContext(null, new WordPressSource(WpFixture::CONNECTION), $site, $registry, new RenderedSource([]), $config))->boot();
    }

    public function test_plugin_adapters_are_detected_from_a_fake_site_profile(): void
    {
        WpFixture::boot();
        $wp = new WordPressSource(WpFixture::CONNECTION);
        $site = new SiteProfile(siteUrl: 'https://shop.test', activePlugins: [
            'woocommerce/woocommerce.php', 'seo-by-rank-math/rank-math.php', 'woo-permalink-manager-premium/premmerce-url-manager.php',
            'advanced-custom-fields-pro/acf.php', 'elementor/elementor.php',
        ], wooVersion: '9.0');
        $registry = new AdapterRegistry;
        $this->context($site, $registry);

        $active = array_map(fn ($a) => $a->key(), $registry->active());
        foreach (['woocommerce', 'rank-math', 'premmerce-permalinks', 'acf', 'elementor', 'rendered-theme'] as $key) {
            $this->assertContains($key, $active);
        }
        foreach (['yoast', 'redirection', 'wishlists', 'cfdb7', 'wp-cli', 'permalink-manager', 'sequential-order-numbers', 'product-brands', 'cost-of-goods'] as $key) {
            $this->assertNotContains($key, $active, $key.' should not be detected');
        }

        $yoast = new Adapters\Yoast;
        $this->assertTrue($registry->detect($yoast, new SiteProfile(activePlugins: ['wordpress-seo/wp-seo.php']), $wp));
        $this->assertTrue($registry->detect(new Adapters\PermalinkManager, new SiteProfile(activePlugins: ['permalink-manager-pro/permalink-manager.php']), $wp));
    }

    public function test_table_and_meta_based_detection(): void
    {
        $db = WpFixture::boot();
        $db->statement('CREATE TABLE "wp_redirection_items" (id integer primary key)');
        $db->statement('CREATE TABLE "wp_tinvwl_items" (ID integer primary key)');
        WpFixture::post(['post_type' => 'shop_order', 'post_status' => 'wc-completed'], ['_order_number_formatted' => 'A-1']);
        WpFixture::term(5, 'product_brand', 'Linen', 'linen');
        $wp = new WordPressSource(WpFixture::CONNECTION);
        $site = new SiteProfile(activePlugins: []);
        $registry = new AdapterRegistry;

        $this->assertTrue($registry->detect(new Adapters\Redirection, $site, $wp));
        $this->assertTrue($registry->detect(new Adapters\Wishlists, $site, $wp));
        $this->assertTrue($registry->detect(new Adapters\SequentialOrderNumbers, $site, $wp));
        $this->assertTrue($registry->detect(new Adapters\ProductBrands, $site, $wp));
        $this->assertFalse($registry->detect(new Adapters\ProductTags, $site, $wp));
        $this->assertFalse($registry->detect(new Adapters\ContactForm7Database, $site, $wp));
    }

    public function test_disable_entries_switch_off_adapters_or_single_capabilities_and_priority_orders_providers(): void
    {
        WpFixture::boot();
        $site = new SiteProfile(activePlugins: ['woocommerce/woocommerce.php', 'seo-by-rank-math/rank-math.php', 'wordpress-seo/wp-seo.php',
            'woo-permalink-manager/premmerce-url-manager.php', 'permalink-manager/permalink-manager.php']);

        $registry = new AdapterRegistry(AdapterRegistry::CORE, [], ['rank-math:redirects']);
        $this->context($site, $registry);
        $this->assertSame(['rank-math', 'yoast'], array_map(fn ($a) => $a->key(), $registry->providers(SeoProvider::class)));
        $this->assertSame(['yoast'], array_map(fn ($a) => $a->key(), $registry->providers(RedirectProvider::class)));
        $this->assertSame(['permalink-manager', 'premmerce-permalinks'], array_map(fn ($a) => $a->key(), $registry->providers(PermalinkProvider::class)));

        $registry = new AdapterRegistry(AdapterRegistry::CORE, [], ['rank-math', 'yoast']);
        $this->context($site, $registry);
        $this->assertSame([], $registry->providers(SeoProvider::class));
    }

    public function test_client_adapters_and_their_steps_join_the_pipeline_in_dependency_order(): void
    {
        WpFixture::boot();
        $site = new SiteProfile(activePlugins: ['woocommerce/woocommerce.php']);
        $client = new class extends Adapters\AbstractAdapter
        {
            public function key(): string
            {
                return 'client-x';
            }

            public function detect(SiteProfile $site, WordPressSource $wp): bool
            {
                return true;
            }

            public function priority(): int
            {
                return -10;
            }

            public function steps(): array
            {
                return [new class extends AbstractStep
                {
                    public function key(): string
                    {
                        return 'extras.shipping.client';
                    }

                    public function section(): string
                    {
                        return 'extras';
                    }

                    public function after(): array
                    {
                        return ['extras.shipping'];
                    }

                    protected function import(): void {}
                }, new class extends AbstractStep
                {
                    public function key(): string
                    {
                        return 'client.late';
                    }

                    public function section(): string
                    {
                        return 'custom';
                    }

                    public function after(): array
                    {
                        return ['redirects'];
                    }

                    protected function import(): void {}
                }];
            }
        };
        $registry = new AdapterRegistry(AdapterRegistry::CORE, [$client]);
        $this->context($site, $registry);
        $this->assertTrue($registry->all()['client-x']['client']);

        $keys = array_map(fn ($s) => $s->key(), (new Pipeline($registry->steps()))->steps());
        $this->assertSame('settings', $keys[0]);
        $this->assertGreaterThan(array_search('extras.shipping', $keys), array_search('extras.shipping.client', $keys));
        $this->assertLessThan(array_search('content.pages', $keys), array_search('extras.shipping.client', $keys));
        $this->assertSame('client.late', end($keys));
        foreach ([['users', 'orders'], ['catalog.products', 'catalog.variations'], ['catalog.variations', 'orders'], ['content.pages', 'menus'], ['menus', 'redirects']] as [$a, $b]) {
            $this->assertLessThan(array_search($b, $keys), array_search($a, $keys), "$a before $b");
        }

        $pipeline = new Pipeline($registry->steps());
        $this->assertSame(['catalog.products', 'catalog.variations'], array_map(fn ($s) => $s->key(), $pipeline->select('products', 'categories,attributes')));
        $this->assertSame(['extras.shipping', 'extras.shipping.client'], array_map(fn ($s) => $s->key(), $pipeline->select('shipping', null)));
        $this->expectException(\InvalidArgumentException::class);
        $pipeline->select('nonsense', null);
    }
}
