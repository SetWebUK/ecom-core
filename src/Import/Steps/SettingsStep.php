<?php

namespace Pine\Commerce\Import\Steps;

use Pine\Commerce\Import\Contracts\OrderNumberProvider;
use Pine\Commerce\Import\Contracts\SeoProvider;
use Pine\Commerce\Import\Contracts\SettingsProvider;
use Pine\Commerce\Import\Support\Formatter;
use Pine\Commerce\Models\Setting;

/**
 * Store settings. Precedence (later wins): `settings.defaults` (config) → SettingsProvider chain (WooCommerce options
 * first, then plugin/client adapters) → SEO defaults (seo.* only where still unset, from the SEO plugin) →
 * orders.starting_number (after the highest number issued) → `settings.seed` (config, wins last).
 */
class SettingsStep extends AbstractStep
{
    public function section(): string
    {
        return 'settings';
    }

    protected function import(): void
    {
        $filled = fn ($v) => $v !== null && $v !== '' && $v !== [];
        $settings = array_filter((array) $this->ctx->config('settings.defaults', []), $filled);
        foreach ($this->ctx->adapters->providers(SettingsProvider::class) as $provider) {
            $settings = array_merge($settings, array_filter($provider->settings($this->ctx), $filled));
        }

        $seo = [];
        foreach ($this->ctx->adapters->providers(SeoProvider::class) as $provider) {
            $seo += $provider->siteSettings();
        }
        $siteName = $seo['site_name'] ?? $settings['store.name'] ?? (Formatter::decode($this->ctx->site->blogName) ?: config('app.name'));
        $sep = $seo['title_separator'] ?? '|';
        $settings += [
            'seo.site_name' => $siteName,
            'seo.title_separator' => $sep,
            'seo.title_suffix' => $sep.' '.$siteName,
            'seo.default_image' => $seo['default_image'] ?? null,
            'seo.organization_logo' => $seo['organization_logo'] ?? null,
        ];

        $last = null;
        foreach ($this->ctx->adapters->providers(OrderNumberProvider::class) as $provider) {
            $last = $provider->lastIssuedNumber();
            if ($last !== null) {
                break;
            }
        }
        if ($last === null && $this->ctx->adapters->isActive('woocommerce')) {
            $last = $this->ctx->site->ordersStorage === 'hpos'
                ? (int) $this->wp->table('wc_orders')->where('type', 'shop_order')->max('id')
                : (int) $this->wp->table('posts')->where('post_type', 'shop_order')->max('ID');
        }
        if ($last !== null) {
            $settings['orders.starting_number'] = (string) ($last + 1);
        }

        $settings = array_merge($settings, (array) $this->ctx->config('settings.seed', []));

        $written = 0;
        foreach ($settings as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            Setting::set($key, $value);
            $written++;
        }

        $this->ctx->count('Settings', '—', count($settings), $written.' written: store/seo/tracking/orders keys');
    }
}
