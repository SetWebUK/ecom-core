<?php

namespace Pine\Commerce\Import\Adapters;

use Pine\Commerce\Import\Contracts\PermalinkProvider;
use Pine\Commerce\Import\Source\SiteProfile;
use Pine\Commerce\Import\Source\WordPressSource;

/** Permalink Manager Lite/Pro: custom URIs stored in the option `permalink-manager-uris` (post id / "tax-{term id}" => uri). */
class PermalinkManager extends AbstractAdapter implements PermalinkProvider
{
    public function key(): string
    {
        return 'permalink-manager';
    }

    public function label(): string
    {
        return 'Permalink Manager';
    }

    public function priority(): int
    {
        return 60;
    }

    public function detect(SiteProfile $site, WordPressSource $wp): bool
    {
        return $site->hasPlugin('permalink-manager/permalink-manager.php', 'permalink-manager-pro/permalink-manager.php');
    }

    public function permalinks(): array
    {
        $uris = (array) $this->ctx->wp->option('permalink-manager-uris', []);
        if (! $uris) {
            return [];
        }
        $types = [];
        $ids = array_filter(array_keys($uris), 'is_numeric');
        foreach (array_chunk($ids, 2000) as $chunk) {
            $types += $this->ctx->wp->table('posts')->whereIn('ID', $chunk)->pluck('post_type', 'ID')->all();
        }
        $cats = $this->ctx->permalinks()->categoryTree();
        $out = ['products' => [], 'categories' => [], 'pages' => [], 'posts' => []];
        foreach ($uris as $key => $uri) {
            $uri = trim(rawurldecode((string) $uri), '/');
            if (str_starts_with((string) $key, 'tax-')) {
                $termId = (int) substr((string) $key, 4);
                if (isset($cats[$termId])) {
                    $out['categories'][$termId] = $uri;
                }
            } elseif (isset($types[$key])) {
                $type = ['product' => 'products', 'page' => 'pages', 'post' => 'posts'][$types[$key]] ?? null;
                if ($type) {
                    $out[$type][(int) $key] = $uri;
                }
            }
        }

        return $out;
    }
}
