<?php

namespace Pine\Commerce\Import\Contracts;

use Pine\Commerce\Import\Source\SiteProfile;
use Pine\Commerce\Import\Source\WordPressSource;

/**
 * A plugin (or client) adapter of the WordPress importer (docs/ARCHITECTURE.md §12.3). An adapter is active
 * when detect() is true; it contributes capabilities by implementing any of the provider interfaces in this namespace
 * (SeoProvider, PermalinkProvider, ProductMapper …) and extra steps via steps().
 *
 * Priority: providers of the same capability are consulted in priority order (highest first). For "first answer
 * wins" capabilities (SEO, permalinks, rendered content, order numbers) the highest priority wins; for chained
 * capabilities (ProductMapper, TermMapper, SettingsProvider, ContentTransformer) every provider runs in that order, so
 * a LOWER priority runs later and has the last word. Client adapters therefore use a priority below 0.
 */
interface Adapter
{
    /** Stable key, e.g. 'rank-math' (used by --detect, `adapters.disable` and the 'key:capability' disable form). */
    public function key(): string;

    public function label(): string;

    public function detect(SiteProfile $site, WordPressSource $wp): bool;

    public function priority(): int;

    /** @return array<class-string<Step>|Step> extra steps */
    public function steps(): array;
}
