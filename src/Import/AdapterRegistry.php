<?php

namespace Pine\Commerce\Import;

use Pine\Commerce\Import\Adapters;
use Pine\Commerce\Import\Contracts\Adapter;
use Pine\Commerce\Import\Contracts\ContentTransformer;
use Pine\Commerce\Import\Contracts\MenuProvider;
use Pine\Commerce\Import\Contracts\OrderNumberProvider;
use Pine\Commerce\Import\Contracts\PermalinkProvider;
use Pine\Commerce\Import\Contracts\ProductMapper;
use Pine\Commerce\Import\Contracts\RedirectProvider;
use Pine\Commerce\Import\Contracts\RenderedContentProvider;
use Pine\Commerce\Import\Contracts\SeoProvider;
use Pine\Commerce\Import\Contracts\SettingsProvider;
use Pine\Commerce\Import\Contracts\Step;
use Pine\Commerce\Import\Contracts\TermMapper;
use Pine\Commerce\Import\Source\SiteProfile;
use Pine\Commerce\Import\Source\WordPressSource;

/**
 * The importer's adapters: the built-in plugin adapters (CORE) + client adapters (`commerce-import.adapters.extra`),
 * minus `commerce-import.adapters.disable` entries. A disable entry is an adapter key ('rank-math') or
 * 'key:capability' to switch off one capability only ('rank-math:redirects'). `adapters.enable` forces adapters on
 * regardless of detect(). Client adapters are appended after the core ones (ties in priority keep this order).
 */
class AdapterRegistry
{
    /** Built-in adapters, in registration order. */
    public const CORE = [
        Adapters\WooCommerce::class,
        Adapters\RenderedTheme::class,
        Adapters\WpCliPermalinks::class,
        Adapters\PermalinkManager::class,
        Adapters\PremmercePermalinks::class,
        Adapters\RankMath::class,
        Adapters\Yoast::class,
        Adapters\SequentialOrderNumbers::class,
        Adapters\Acf::class,
        Adapters\Redirection::class,
        Adapters\Elementor::class,
        Adapters\WpBakeryCleanup::class,
        Adapters\ProductBrands::class,
        Adapters\ProductTags::class,
        Adapters\CostOfGoods::class,
        Adapters\GoogleTagManager::class,
        Adapters\BackInStockNotifier::class,
        Adapters\Wishlists::class,
        Adapters\ContactForm7Database::class,
    ];

    public const CAPABILITIES = [
        'seo' => SeoProvider::class,
        'redirects' => RedirectProvider::class,
        'permalinks' => PermalinkProvider::class,
        'content' => RenderedContentProvider::class,
        'transform' => ContentTransformer::class,
        'products' => ProductMapper::class,
        'terms' => TermMapper::class,
        'settings' => SettingsProvider::class,
        'menus' => MenuProvider::class,
        'order-numbers' => OrderNumberProvider::class,
    ];

    /** @var array<string,array{adapter:Adapter,active:bool,client:bool,index:int}> */
    private array $adapters = [];

    /** @var array<string,array<string,bool>> key => capability => disabled */
    private array $disabledCapabilities = [];

    /**
     * @param  list<class-string<Adapter>|Adapter>  $core
     * @param  list<class-string<Adapter>|Adapter>  $extra
     * @param  list<string>  $disable
     * @param  list<string>  $enable
     */
    public function __construct(private readonly array $core = self::CORE, private readonly array $extra = [],
        private readonly array $disable = [], private readonly array $enable = []) {}

    public function boot(ImportContext $ctx): void
    {
        $this->adapters = [];
        $disabled = [];
        foreach ($this->disable as $entry) {
            [$key, $capability] = array_pad(explode(':', (string) $entry, 2), 2, null);
            if ($capability) {
                $this->disabledCapabilities[$key][$capability] = true;
            } else {
                $disabled[$key] = true;
            }
        }
        $i = 0;
        foreach ([[$this->core, false], [$this->extra, true]] as [$list, $client]) {
            foreach ($list as $class) {
                $adapter = is_string($class) ? app($class) : $class;
                if (method_exists($adapter, 'boot')) {
                    $adapter->boot($ctx);
                }
                $key = $adapter->key();
                $active = false;
                if (! isset($disabled[$key])) {
                    $active = in_array($key, $this->enable, true) || $this->detect($adapter, $ctx->site, $ctx->wp);
                }
                $this->adapters[$key] = ['adapter' => $adapter, 'active' => $active, 'client' => $client, 'index' => $i++];
            }
        }
    }

    /** Detect an adapter on a profile without booting a full context (used by tests and --detect). */
    public function detect(Adapter $adapter, SiteProfile $site, WordPressSource $wp): bool
    {
        try {
            return $adapter->detect($site, $wp);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string,array{adapter:Adapter,active:bool,client:bool,index:int}> */
    public function all(): array
    {
        return $this->adapters;
    }

    public function isActive(string $key): bool
    {
        return $this->adapters[$key]['active'] ?? false;
    }

    public function get(string $key): ?Adapter
    {
        return $this->adapters[$key]['adapter'] ?? null;
    }

    /** @return list<Adapter> */
    public function active(): array
    {
        return array_values(array_map(fn ($a) => $a['adapter'], array_filter($this->adapters, fn ($a) => $a['active'])));
    }

    /**
     * Active adapters implementing a capability interface, highest priority first (ties: registration order).
     *
     * @template T
     *
     * @param  class-string<T>  $interface
     * @return list<T&Adapter>
     */
    public function providers(string $interface): array
    {
        $capability = array_search($interface, self::CAPABILITIES, true);
        $list = [];
        foreach ($this->adapters as $key => $a) {
            if (! $a['active'] || ! $a['adapter'] instanceof $interface) {
                continue;
            }
            if ($capability && isset($this->disabledCapabilities[$key][$capability])) {
                continue;
            }
            $list[] = $a;
        }
        usort($list, fn ($x, $y) => [$y['adapter']->priority(), $x['index']] <=> [$x['adapter']->priority(), $y['index']]);

        return array_map(fn ($a) => $a['adapter'], $list);
    }

    /** @return list<Step> steps contributed by active adapters (deduplicated by step key) */
    public function steps(): array
    {
        $steps = [];
        foreach ($this->adapters as $key => $a) {
            if (! $a['active'] || isset($this->disabledCapabilities[$key]['steps'])) {
                continue;
            }
            foreach ($a['adapter']->steps() as $step) {
                $step = is_string($step) ? app($step) : $step;
                $steps[$step->key()] ??= $step;
            }
        }

        return array_values($steps);
    }

    /** @return list<string> human readable capability names of an adapter */
    public static function capabilitiesOf(Adapter $adapter): array
    {
        $out = [];
        foreach (self::CAPABILITIES as $name => $interface) {
            if ($adapter instanceof $interface) {
                $out[] = $name;
            }
        }
        if ($adapter->steps()) {
            $out[] = 'steps';
        }

        return $out;
    }
}
