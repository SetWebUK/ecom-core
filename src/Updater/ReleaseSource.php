<?php

namespace Pine\Commerce\Updater;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Where releases come from: the tags of a git repository and single files at a tag.
 *
 *  - GitHub repositories: the public REST API (`/repos/{owner}/{repo}/tags`) and raw.githubusercontent.com, with an
 *    optional token (config commerce.updater.github_token) for private repositories or the rate limit;
 *  - fallback and every other host: `git ls-remote --tags` and a shallow `git fetch` of the tag.
 */
class ReleaseSource
{
    public function __construct(protected ProcessRunner $runner, protected Environment $environment) {}

    /** "owner/repo" of a GitHub URL (https, ssh or scp-like), or null. */
    public static function github(?string $url): ?string
    {
        if ($url && preg_match('~^(?:https?://|ssh://git@|git@|git://)(?:www\.)?github\.com[:/]([\w.-]+)/([\w.-]+?)(?:\.git)?/?$~i', trim($url), $m)) {
            return $m[1].'/'.$m[2];
        }

        return null;
    }

    /**
     * Tag names of the repository.
     *
     * @return array{tags:list<string>, via:string, notes:list<string>}
     */
    public function tags(string $url): array
    {
        $notes = [];
        if ($repo = static::github($url)) {
            try {
                $response = $this->http()->get("https://api.github.com/repos/{$repo}/tags", ['per_page' => 100]);
                if ($response->successful() && is_array($response->json())) {
                    return ['tags' => array_values(array_filter(array_map(fn ($t) => is_array($t) ? (string) ($t['name'] ?? '') : '', $response->json()))),
                        'via' => 'GitHub API', 'notes' => $notes];
                }
                $notes[] = 'GitHub API answered '.$response->status().' – using git ls-remote.';
            } catch (Throwable $e) {
                $notes[] = 'GitHub API not reachable ('.$e->getMessage().') – using git ls-remote.';
            }
        }

        $result = $this->runner->run([$this->environment->git(), 'ls-remote', '--tags', '--refs', $url], null,
            $this->environment->variables(), null, (int) config('commerce.updater.git_timeout', 60));
        if (! $result->ok()) {
            throw new RuntimeException('Could not list the tags of '.$url.': '.($result->lastLine() ?: 'git ls-remote failed'));
        }
        $tags = [];
        foreach (explode("\n", $result->output) as $line) {
            if (preg_match('~\srefs/tags/(\S+)$~', trim($line), $m)) {
                $tags[] = $m[1];
            }
        }

        return ['tags' => $tags, 'via' => 'git ls-remote', 'notes' => $notes];
    }

    /** One file of the repository at $ref (e.g. CHANGELOG.md at v1.3.0). */
    public function file(string $url, string $ref, string $path): string
    {
        if ($repo = static::github($url)) {
            try {
                $response = $this->http()->get('https://raw.githubusercontent.com/'.$repo.'/'.rawurlencode($ref).'/'.$path);
                if ($response->successful()) {
                    return $response->body();
                }
            } catch (Throwable) {
                // fall through to git
            }
        }

        $dir = $this->checkout($url, $ref, 'files');
        try {
            if (! is_file($dir.'/'.$path)) {
                throw new RuntimeException("{$path} does not exist at {$ref}.");
            }

            return (string) file_get_contents($dir.'/'.$path);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * Shallow checkout of $ref (tag, branch or commit) into {workPath}/{area}/{ref} – reused when it is a tag or a
     * commit (they never change), fetched again for a branch.
     */
    public function checkout(string $url, string $ref, string $area): string
    {
        if (! preg_match('/^[\w.\/-]+$/', $ref) || str_contains($ref, '..')) {
            throw new RuntimeException("Not a git reference: {$ref}");
        }
        $dir = Updater::ensureDirectory(Updater::workPath($area)).'/'.preg_replace('/[^\w.-]/', '_', $ref);
        $immutable = Versions::fromTag($ref) !== null || preg_match('/^[0-9a-f]{40}$/', $ref);
        if ($immutable && is_file($dir.'/.updater-complete')) {
            return $dir;
        }
        File::deleteDirectory($dir);
        $git = $this->environment->git();
        $env = $this->environment->variables();
        $timeout = (int) config('commerce.updater.git_timeout', 120);
        foreach ([
            [$git, 'init', '--quiet', $dir],
            [$git, '-C', $dir, 'fetch', '--quiet', '--depth', '1', $url, $ref],
            [$git, '-C', $dir, '-c', 'advice.detachedHead=false', 'checkout', '--quiet', 'FETCH_HEAD'],
        ] as $command) {
            $result = $this->runner->run($command, null, $env, null, $timeout);
            if (! $result->ok()) {
                File::deleteDirectory($dir);
                throw new RuntimeException("Could not fetch {$ref} from {$url}: ".($result->lastLine() ?: 'git failed'));
            }
        }
        $commit = $this->runner->run([$git, '-C', $dir, 'rev-parse', 'HEAD'], null, $env, null, 30);
        file_put_contents($dir.'/.updater-complete', trim($commit->output));

        return $dir;
    }

    /** The commit a checkout is at (written by checkout()). */
    public static function checkoutCommit(string $dir): ?string
    {
        $commit = is_file($dir.'/.updater-complete') ? trim((string) file_get_contents($dir.'/.updater-complete')) : '';

        return preg_match('/^[0-9a-f]{40}$/', $commit) ? $commit : null;
    }

    protected function http(): \Illuminate\Http\Client\PendingRequest
    {
        $request = Http::timeout((int) config('commerce.updater.http_timeout', 10))
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'User-Agent' => 'pine-commerce-updater']);
        $token = trim((string) config('commerce.updater.github_token'));

        return $token !== '' ? $request->withToken($token) : $request;
    }
}
