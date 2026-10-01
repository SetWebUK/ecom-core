<?php

namespace Pine\Commerce\Import\Adapters;

use Pine\Commerce\Import\Contracts\SettingsProvider;
use Pine\Commerce\Import\ImportContext;
use Pine\Commerce\Import\Source\SiteProfile;
use Pine\Commerce\Import\Source\WordPressSource;

/** GTM4WP (option gtm4wp-options) → settings tracking.gtm_id. */
class GoogleTagManager extends AbstractAdapter implements SettingsProvider
{
    public function key(): string
    {
        return 'gtm4wp';
    }

    public function label(): string
    {
        return 'Google Tag Manager for WordPress';
    }

    public function detect(SiteProfile $site, WordPressSource $wp): bool
    {
        return $site->hasPlugin('duracelltomi-google-tag-manager/*') || $wp->option('gtm4wp-options') !== null;
    }

    public function settings(ImportContext $ctx): array
    {
        $gtm = (array) $ctx->wp->option('gtm4wp-options', []);

        return ! empty($gtm['gtm-code']) ? ['tracking.gtm_id' => $gtm['gtm-code']] : [];
    }
}
