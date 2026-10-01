<?php

namespace Pine\Commerce\Import\Adapters;

use Pine\Commerce\Import\Contracts\RenderedContentProvider;
use Pine\Commerce\Import\Data\ContentItem;
use Pine\Commerce\Import\Source\SiteProfile;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Import\Support\Formatter;

/** WPBakery (vc_*) / Impreza (us_*) shortcode content flattened to semantic HTML (Formatter::pageBuilderToHtml). */
class WpBakeryCleanup extends AbstractAdapter implements RenderedContentProvider
{
    public function key(): string
    {
        return 'wpbakery';
    }

    public function label(): string
    {
        return 'WPBakery / Impreza shortcodes';
    }

    public function priority(): int
    {
        return 50;
    }

    public function detect(SiteProfile $site, WordPressSource $wp): bool
    {
        return $site->hasPlugin('js_composer/js_composer.php', 'js_composer*/js_composer.php')
            || $wp->table('posts')->whereIn('post_type', ['page', 'post'])
                ->where(fn ($q) => $q->where('post_content', 'like', '%[vc\_%')->orWhere('post_content', 'like', '%[us\_%'))->exists();
    }

    public function renderedHtml(ContentItem $item): ?string
    {
        return Formatter::hasPageBuilderShortcodes($item->html) ? Formatter::pageBuilderToHtml($item->html) : null;
    }
}
