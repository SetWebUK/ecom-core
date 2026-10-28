<?php

namespace Pine\Commerce\Tests\Feature;

use Pine\Commerce\Models\Setting;
use Pine\Commerce\Services\Admin\AdminBrand;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/**
 * 1.8.0: the back office in the shop's own colours and logo (Services\Admin\AdminBrand) and the sidebar's sections,
 * shop link and Create menu.
 */
class AdminBrandTest extends TestCase
{
    use InstallsNeutralStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNeutralStore();
        AdminBrand::flush();
    }

    protected function tearDown(): void
    {
        AdminBrand::flush();
        $this->tearDownNeutralStore();
        parent::tearDown();
    }

    public function test_the_theme_colours_become_a_readable_sidebar_and_the_button_colour(): void
    {
        Setting::set('theme.default.primary_color', '#1e3756', 'theme');
        Setting::set('theme.default.accent_color', '#993130', 'theme');
        Setting::flushMemo();

        $p = AdminBrand::palette();

        $this->assertSame('#1e3756', $p['shell'], 'dark enough already');
        $this->assertSame('#1e3756', $p['primary']);
        $this->assertGreaterThanOrEqual(3.0, AdminBrand::contrast($p['accent'], $p['shell']), 'the red marker shows on the navy');
        $this->assertStringStartsWith('#c', $p['accent'], 'lightened, still red');
        $this->assertStringContainsString('--primary:#1e3756;', AdminBrand::css());
        $this->assertMatchesRegularExpression('/^:root\{(--[a-z-]+:#[0-9a-f]{6};)+\}$/', AdminBrand::css(), 'hex values only');
    }

    public function test_a_light_brand_colour_still_gives_white_text_and_keeps_the_default_buttons(): void
    {
        config(['commerce.admin.brand.color' => '#ffd400', 'commerce.admin.brand.accent' => null]);

        $p = AdminBrand::palette();

        $this->assertGreaterThanOrEqual(10.0, AdminBrand::contrast($p['shell'], '#ffffff'));
        $this->assertNull($p['primary'], 'white text on yellow buttons would be unreadable');
        $this->assertStringNotContainsString('--primary:', AdminBrand::css());
    }

    public function test_bad_colours_are_ignored_and_false_keeps_the_neutral_look(): void
    {
        config(['commerce.admin.brand.color' => 'red;}</style><script>', 'commerce.admin.brand.accent' => 'nope']);
        Setting::set('theme.default.primary_color', 'javascript:alert(1)', 'theme');
        Setting::flushMemo();
        $this->assertSame(AdminBrand::NEUTRAL, AdminBrand::palette()['shell']);

        AdminBrand::flush();
        config(['commerce.admin.brand.color' => false]);
        Setting::set('theme.default.primary_color', '#1e3756', 'theme');
        Setting::flushMemo();
        $this->assertSame(AdminBrand::NEUTRAL, AdminBrand::palette()['shell']);
        $this->assertNull(AdminBrand::palette()['primary']);
    }

    public function test_the_sidebar_shows_the_shops_logo_sections_and_create_menu(): void
    {
        Setting::set('store.logo', 'uploads/brand/logo.png', 'store');
        Setting::flushMemo();

        $html = $this->actingAs($this->neutralAdmin())->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<img src="[^"]*uploads/brand/logo\.png" alt="Acme Store" class="brand-tile__logo">#', $html);
        $this->assertStringContainsString('<li class="nav__group-label">Website</li>', $html);
        $this->assertLessThan(strpos($html, 'nav__group-label'), strpos($html, '<span>Analytics</span>'), 'Analytics sits with the shop work, above Website');
        $this->assertStringContainsString('class="sidebar__create"', $html);
        foreach (['admin.orders.create', 'admin.products.create', 'admin.coupons.create', 'admin.pages.create'] as $route) {
            $this->assertStringContainsString('href="'.route($route).'"', $html);
        }
        $this->assertStringContainsString('<style>:root{--shell:', $html);

        auth()->logout();
        $this->get(route('admin.login'))->assertOk()->assertSee('class="auth__logo"', false);
    }

    public function test_without_a_logo_the_tile_shows_the_shops_initials_and_name(): void
    {
        $html = $this->actingAs($this->neutralAdmin())->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('<span class="brand-tile__initials" aria-hidden="true">AS</span>', $html);
        $this->assertStringContainsString('<span class="brand-tile__name">Acme Store</span>', $html);
    }
}
