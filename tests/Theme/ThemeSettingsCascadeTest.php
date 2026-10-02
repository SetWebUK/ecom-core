<?php

namespace Pine\Commerce\Tests\Theme;

use Pine\Commerce\Models\Setting;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/**
 * Admin › Settings › Theme values are CSS custom properties printed inline. They must come AFTER css/app.css (which
 * declares the defaults on :root) or the defaults win the cascade and the settings never show on the storefront.
 */
class ThemeSettingsCascadeTest extends TestCase
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

    public function test_theme_setting_variables_are_printed_after_the_theme_stylesheet(): void
    {
        Setting::set(theme()->settingKey('primary_color'), '#0f766e');

        foreach (['/', '/shop/'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $css = strpos($html, 'css/app.css');
            $vars = strpos($html, '--c-primary:#0f766e');
            $this->assertNotFalse($css, "$url loads css/app.css");
            $this->assertNotFalse($vars, "$url prints the primary colour setting");
            $this->assertGreaterThan($css, $vars, "$url: theme settings must come after css/app.css");
        }
    }
}
