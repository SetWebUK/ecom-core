<?php

namespace Pine\Commerce\Console;

use GuzzleHttp\Promise\Utils;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * URL parity check for a migration: every URL of the OLD site (its sitemap, or a list) must answer 200, or 301/308 to
 * a page that answers 200, on the NEW site. Run it before the DNS switch (--base / --resolve) and again after go-live.
 *
 *   php artisan commerce:verify-urls https://www.client.co.uk/sitemap_index.xml
 *   php artisan commerce:verify-urls storage/app/old-sitemap.xml --base=https://staging.example.com
 *   php artisan commerce:verify-urls urls.txt --base=https://www.client.co.uk --resolve=203.0.113.10   (new server, before DNS)
 *
 * Sources: a sitemap or sitemap index (URL or file, .xml or .xml.gz – nested sitemaps are followed), or a text file
 * with one URL or path per line. Only the path + query of each old URL is used; it is requested on --base
 * (default APP_URL) without following redirects. Exit code 1 when any URL fails.
 */
class VerifyUrlsCommand extends Command
{
    protected $signature = 'commerce:verify-urls
        {source : Old sitemap URL, sitemap file, or text file with one URL/path per line}
        {--base= : Base URL of the NEW site (default: APP_URL)}
        {--resolve= : Connect to this IP for the --base host (test the new server before DNS points at it)}
        {--no-follow : Do not check that a 301 target answers 200}
        {--allow-302 : Accept temporary redirects (302/307) as passing}
        {--concurrency=8 : Parallel requests}
        {--timeout=20 : Seconds per request}
        {--limit=0 : Check only the first N URLs (0 = all)}
        {--insecure : Do not verify TLS certificates}
        {--report= : Write a CSV report (url, path, status, location, final_status, result) to this file}';

    protected $description = 'Check that every URL of the old site (sitemap or list) answers 200 or 301→200 on the new site';

    protected Http $http;

    public function handle(Http $http): int
    {
        $this->http = $http;
        $base = rtrim((string) ($this->option('base') ?: config('app.url')), '/');
        if (! preg_match('#^https?://[^/]+#', $base)) {
            $this->error("Invalid --base URL '{$base}'.");

            return self::FAILURE;
        }

        try {
            $urls = $this->collect((string) $this->argument('source'));
        } catch (Throwable $e) {
            $this->error('Could not read the source: '.$e->getMessage());

            return self::FAILURE;
        }
        $paths = [];
        foreach ($urls as $url) {
            $path = $this->pathOf($url);
            if ($path !== null) {
                $paths[$path] = $url;
            }
        }
        if (($limit = (int) $this->option('limit')) > 0) {
            $paths = array_slice($paths, 0, $limit, true);
        }
        if (! $paths) {
            $this->error('No URLs found in the source.');

            return self::FAILURE;
        }

        $this->components->info(count($paths)." URL(s) to check on {$base}".($this->option('resolve') ? ' (via '.$this->option('resolve').')' : ''));

        $results = [];
        $bar = $this->output->createProgressBar(count($paths));
        foreach (array_chunk($paths, max(1, (int) $this->option('concurrency')), true) as $chunk) {
            $promises = [];
            foreach ($chunk as $path => $old) {
                $promises[$path] = $this->request($base.$path);
            }
            foreach (Utils::settle($promises)->wait() as $path => $outcome) {
                $results[$path] = $this->classify($base, $path, $chunk[$path], $outcome['state'] === 'fulfilled' ? $outcome['value'] : $outcome['reason']);
                $bar->advance();
            }
        }
        $bar->finish();
        $this->newLine(2);

        $failed = array_filter($results, fn ($r) => $r['result'] === 'FAIL');
        $redirects = array_filter($results, fn ($r) => $r['result'] === 'OK' && in_array($r['status'], [301, 308], true));
        if ($failed) {
            $this->table(['Old URL path', 'Status', 'Redirects to', 'Target status'], array_map(fn ($r) => [
                mb_strimwidth($r['path'], 0, 70, '…'), $r['status'] ?: $r['error'], mb_strimwidth((string) $r['location'], 0, 60, '…'), $r['final_status'] ?? '',
            ], array_slice(array_values($failed), 0, 200)));
            if (count($failed) > 200) {
                $this->line('… '.(count($failed) - 200).' more (see --report).');
            }
        }

        if ($report = $this->option('report')) {
            $fh = fopen($report, 'w');
            fputcsv($fh, ['url', 'path', 'status', 'location', 'final_status', 'result', 'error'], escape: '');
            foreach ($results as $r) {
                fputcsv($fh, [$r['url'], $r['path'], $r['status'], $r['location'], $r['final_status'], $r['result'], $r['error']], escape: '');
            }
            fclose($fh);
            $this->line("Report written to {$report}");
        }

        $summary = sprintf('%d checked: %d OK (%d via 301/308), %d failed.', count($results), count($results) - count($failed), count($redirects), count($failed));
        $failed ? $this->components->error($summary) : $this->components->info($summary);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<string> URLs (or paths) from a sitemap / sitemap index / list, URL or file */
    protected function collect(string $source, int $depth = 0): array
    {
        if ($depth > 3) {
            return [];
        }
        $body = $this->read($source);
        if (str_starts_with($body, "\x1f\x8b")) {
            $body = (string) gzdecode($body);
        }

        if (preg_match('/<(sitemapindex|urlset)\b/i', $body, $root)) {
            preg_match_all('#<loc>\s*(?:<!\[CDATA\[)?\s*(.*?)\s*(?:\]\]>)?\s*</loc>#is', $body, $m);
            $locs = array_map(fn ($l) => html_entity_decode(trim($l), ENT_QUOTES | ENT_XML1), $m[1]);
            if (strtolower($root[1]) === 'urlset') {
                return $locs;
            }
            $urls = [];
            foreach ($locs as $sitemap) {
                try {
                    array_push($urls, ...$this->collect($sitemap, $depth + 1));
                } catch (Throwable $e) {
                    $this->warn("Skipped sitemap {$sitemap}: ".$e->getMessage());
                }
            }

            return $urls;
        }

        $lines = preg_split('/\R/', $body) ?: [];

        return array_values(array_filter(array_map('trim', $lines), fn ($l) => $l !== '' && ! str_starts_with($l, '#') && (str_starts_with($l, '/') || preg_match('#^https?://#i', $l))));
    }

    protected function read(string $source): string
    {
        if (preg_match('#^https?://#i', $source)) {
            $response = $this->http->withOptions(['verify' => ! $this->option('insecure')])
                ->withHeaders(['User-Agent' => 'pine-commerce-verify-urls/1.0'])
                ->timeout((int) $this->option('timeout'))->get($source);
            if (! $response->successful()) {
                throw new \RuntimeException("{$source} answered HTTP {$response->status()}");
            }

            return $response->body();
        }
        if (! is_file($source) || ! is_readable($source)) {
            throw new \RuntimeException("{$source} is not a readable file or http(s) URL");
        }

        return (string) file_get_contents($source);
    }

    /** Path + query of an old URL ('/' for the home page); null for non-page lines. */
    protected function pathOf(string $url): ?string
    {
        if (str_starts_with($url, '/')) {
            return $url;
        }
        $parts = parse_url($url);
        if (! $parts || empty($parts['host'])) {
            return null;
        }

        return ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    protected function request(string $url)
    {
        $options = ['allow_redirects' => false, 'verify' => ! $this->option('insecure'), 'http_errors' => false];
        if ($ip = $this->option('resolve')) {
            $parts = parse_url($url);
            $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
            $options['curl'] = [CURLOPT_RESOLVE => ["{$parts['host']}:{$port}:{$ip}"]];
        }

        return $this->http->async()->withOptions($options)
            ->withHeaders(['User-Agent' => 'pine-commerce-verify-urls/1.0'])
            ->timeout((int) $this->option('timeout'))->get($url);
    }

    protected function classify(string $base, string $path, string $old, mixed $outcome): array
    {
        $row = ['url' => $old, 'path' => $path, 'status' => 0, 'location' => null, 'final_status' => null, 'result' => 'FAIL', 'error' => null];
        if (! $outcome instanceof Response) {
            $row['error'] = $outcome instanceof Throwable ? mb_strimwidth($outcome->getMessage(), 0, 120, '…') : 'request failed';

            return $row;
        }
        $row['status'] = $outcome->status();
        $row['location'] = $outcome->header('Location') ?: null;

        if ($row['status'] === 200) {
            $row['result'] = 'OK';
        } elseif (in_array($row['status'], [301, 308], true)) {
            $row['result'] = 'OK';
            if (! $this->option('no-follow') && $row['location']) {
                $target = str_starts_with($row['location'], '/') ? $base.$row['location'] : $row['location'];
                try {
                    $final = $this->request($this->onBase($base, $target))->wait();
                    $row['final_status'] = $final instanceof Response ? $final->status() : 0;
                    // one more hop is fine (e.g. no-slash → slash → canonical), a chain that ends in 200 passes
                    if ($final instanceof Response && in_array($final->status(), [301, 308], true) && $final->header('Location')) {
                        $loc = $final->header('Location');
                        $again = $this->request($this->onBase($base, str_starts_with($loc, '/') ? $base.$loc : $loc))->wait();
                        $row['final_status'] = $again instanceof Response ? $again->status() : 0;
                    }
                } catch (Throwable $e) {
                    $row['final_status'] = 0;
                    $row['error'] = mb_strimwidth($e->getMessage(), 0, 120, '…');
                }
                if ($row['final_status'] !== 200) {
                    $row['result'] = 'FAIL';
                }
            }
        } elseif (in_array($row['status'], [302, 307], true) && $this->option('allow-302')) {
            $row['result'] = 'OK';
        }

        return $row;
    }

    /** Redirect targets on the old host (absolute links written by the old site) are checked on the new base. */
    protected function onBase(string $base, string $url): string
    {
        $baseHost = parse_url($base, PHP_URL_HOST);
        $host = parse_url($url, PHP_URL_HOST);
        if ($host && $host !== $baseHost && in_array(preg_replace('/^www\./', '', (string) $host), [preg_replace('/^www\./', '', (string) $baseHost)], true)) {
            return $base.($this->pathOf($url) ?? '/');
        }

        return $url;
    }
}
