<?php

namespace Pine\Commerce\Import\Adapters;

use Illuminate\Support\Facades\Process;
use Pine\Commerce\Import\Contracts\PermalinkProvider;
use Pine\Commerce\Import\Source\SiteProfile;
use Pine\Commerce\Import\Source\WordPressSource;

/**
 * Canonical permalinks exactly as WordPress generates them (every permalink plugin applied), via wp-cli. Needs the
 * `wp` binary and --wp-path; wp-cli runs from the system temp dir (a plugin's relative require would otherwise load
 * the Laravel app sharing the web root). Results are cached to storage/app/import/wp-urls-{db}-{prefix}.json and the
 * cache is used when wp-cli fails. Disable with --no-wp-cli.
 */
class WpCliPermalinks extends AbstractAdapter implements PermalinkProvider
{
    public function key(): string
    {
        return 'wp-cli';
    }

    public function label(): string
    {
        return 'wp-cli permalinks';
    }

    public function priority(): int
    {
        return 100;
    }

    public function detect(SiteProfile $site, WordPressSource $wp): bool
    {
        if (! $this->ctx || ! empty($this->ctx->options['no_wp_cli'])) {
            return false;
        }
        $path = $this->ctx->options['wp_path'] ?? null;
        if (! $path || ! is_file(rtrim($path, '/').'/wp-settings.php')) {
            return false;
        }

        return is_file($this->cacheFile()) || $this->binary() !== null;
    }

    public function permalinks(): array
    {
        $cache = $this->cacheFile();
        $urls = ['products' => [], 'categories' => [], 'pages' => [], 'posts' => []];
        $commands = [
            'products' => ['post', 'list', '--post_type=product', '--post_status=any', '--fields=ID,url', '--format=json'],
            'pages' => ['post', 'list', '--post_type=page', '--post_status=publish', '--fields=ID,url', '--format=json'],
            'posts' => ['post', 'list', '--post_type=post', '--post_status=publish', '--fields=ID,url', '--format=json'],
            'categories' => ['term', 'list', 'product_cat', '--fields=term_id,url', '--format=json'],
        ];
        $ok = $this->binary() !== null;
        foreach ($ok ? $commands : [] as $type => $args) {
            try {
                $result = Process::path(sys_get_temp_dir())->timeout(300)
                    ->run(array_merge([$this->binary(), '--path='.$this->ctx->options['wp_path'], '--quiet'], $args));
                $rows = $result->successful() ? json_decode($result->output(), true) : null;
            } catch (\Throwable) {
                $rows = null;
            }
            if (! is_array($rows)) {
                $ok = false;
                break;
            }
            foreach ($rows as $row) {
                $id = (int) ($row['ID'] ?? $row['term_id'] ?? 0);
                $path = trim((string) parse_url($row['url'] ?? '', PHP_URL_PATH), '/');
                if ($id && ! str_contains((string) ($row['url'] ?? ''), '?')) {
                    $urls[$type][$id] = rawurldecode($path);
                }
            }
        }

        if ($ok && ($urls['products'] || $urls['pages'])) {
            @mkdir(dirname($cache), 0775, true);
            file_put_contents($cache, json_encode($urls));
        } elseif (is_file($cache)) {
            $urls = json_decode(file_get_contents($cache), true);
            $this->ctx->info('wp-cli unavailable – using cached permalinks from '.str_replace(base_path().'/', '', $cache));
        } else {
            $this->ctx->warn('Could not read WordPress permalinks via wp-cli; URLs derived from the permalink settings.');

            return [];
        }

        return $urls;
    }

    private function cacheFile(): string
    {
        $site = $this->ctx->site;

        return storage_path('app/import/wp-urls-'.preg_replace('/[^A-Za-z0-9_-]/', '', $site->database.'-'.$site->prefix).'.json');
    }

    private function binary(): ?string
    {
        static $found = false;
        if ($found === false) {
            $found = null;
            foreach ([getenv('WP_CLI_BIN') ?: null, '/usr/local/bin/wp', '/usr/bin/wp'] as $candidate) {
                if ($candidate && is_executable($candidate)) {
                    $found = $candidate;
                    break;
                }
            }
        }

        return $found;
    }
}
