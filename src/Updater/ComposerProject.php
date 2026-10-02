<?php

namespace Pine\Commerce\Updater;

/**
 * Reads the client project's composer files (never the cached InstalledVersions class: the update run replaces
 * vendor/ under the running process, so the files are read fresh every time).
 *
 *  - constraint():   composer.json require."pine/commerce"
 *  - repository():   the composer repository pine/commerce comes from (vcs / git / github / path), or the configured
 *                    override commerce.updater.repository, or the package's public repository as a fallback
 *  - installed():    vendor/composer/installed.json version of pine/commerce
 */
class ComposerProject
{
    public function __construct(protected ?string $path = null) {}

    public function path(string $file = ''): string
    {
        $base = rtrim($this->path ?? Updater::projectPath(), '/');

        return $file === '' ? $base : $base.'/'.$file;
    }

    /** @return array<string,mixed> */
    public function composerJson(): array
    {
        $data = is_file($this->path('composer.json')) ? json_decode((string) file_get_contents($this->path('composer.json')), true) : null;

        return is_array($data) ? $data : [];
    }

    public function constraint(): ?string
    {
        $json = $this->composerJson();
        $constraint = $json['require'][Updater::PACKAGE] ?? $json['require-dev'][Updater::PACKAGE] ?? null;

        return is_string($constraint) && trim($constraint) !== '' ? trim($constraint) : null;
    }

    /**
     * @return array{url:?string, type:string, source:string}
     *   type: vcs | git | github | path | none; source: config | composer.json | default
     */
    public function repository(): array
    {
        $configured = trim((string) config('commerce.updater.repository'));
        if ($configured !== '') {
            return ['url' => $configured, 'type' => 'vcs', 'source' => 'config'];
        }

        $candidates = [];
        foreach ((array) ($this->composerJson()['repositories'] ?? []) as $repository) {
            if (! is_array($repository) || empty($repository['url']) || ! is_string($repository['url'])) {
                continue;
            }
            $type = strtolower((string) ($repository['type'] ?? ''));
            if (! in_array($type, ['vcs', 'git', 'github', 'path'], true)) {
                continue;
            }
            $only = (array) ($repository['only'] ?? []);
            if ($only && ! in_array(Updater::PACKAGE, $only, true)) {
                continue;
            }
            $score = ($only ? 4 : 0) + (str_contains(strtolower($repository['url']), 'commerce') ? 2 : 0) + ($type === 'path' ? 0 : 1);
            $candidates[] = [$score, ['url' => $repository['url'], 'type' => $type, 'source' => 'composer.json']];
        }
        if ($candidates) {
            usort($candidates, fn ($a, $b) => $b[0] <=> $a[0]);

            return $candidates[0][1];
        }
        $default = trim((string) config('commerce.updater.default_repository'));

        return ['url' => $default !== '' ? $default : null, 'type' => $default !== '' ? 'vcs' : 'none', 'source' => 'default'];
    }

    /** @return array<string,mixed>|null the installed.json entry of pine/commerce */
    public function installedPackage(): ?array
    {
        $file = $this->path('vendor/composer/installed.json');
        clearstatcache(true, $file);
        $key = $file.'@'.(is_file($file) ? hash_file('crc32b', $file) : 'none');
        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $packages = is_array($data) ? ($data['packages'] ?? $data) : [];
        $found = null;
        foreach ((array) $packages as $package) {
            if (is_array($package) && ($package['name'] ?? null) === Updater::PACKAGE) {
                $found = $package;
                break;
            }
        }

        return self::$memo[$key] = $found;
    }

    /** installed.json reads, keyed by path + checksum (the sidebar asks on every admin page). */
    protected static array $memo = [];

    /** "1.2.1" (stable release), or null for a dev branch / path checkout / not installed. */
    public function installedVersion(): ?string
    {
        $package = $this->installedPackage();

        return $package ? Versions::fromTag((string) ($package['version'] ?? '')) : null;
    }

    /** As composer shows it ("v1.2.1", "dev-main"). */
    public function installedPrettyVersion(): ?string
    {
        $package = $this->installedPackage();

        return $package ? (string) ($package['version'] ?? '') : null;
    }

    /** Were the dev requirements installed (composer install without --no-dev)? */
    public function devInstalled(): bool
    {
        $file = $this->path('vendor/composer/installed.json');
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return ! is_array($data) || ! array_key_exists('dev', $data) || (bool) $data['dev'];
    }

    /** config.preferred-install of composer.json, for display ("dist", "source", or the per-package map). */
    public function preferredInstall(): string
    {
        $value = $this->composerJson()['config']['preferred-install'] ?? 'dist (default)';
        if (is_array($value)) {
            return (string) ($value[Updater::PACKAGE] ?? $value['*'] ?? 'dist (default)');
        }

        return (string) $value;
    }
}
