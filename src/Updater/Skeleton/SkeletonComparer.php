<?php

namespace Pine\Commerce\Updater\Skeleton;

use Pine\Commerce\Updater\LineDiff;
use Symfony\Component\Finder\Finder;

/**
 * Compares the project with two versions of the skeleton (its baseline and the target) and classifies every file the
 * skeleton changed between them:
 *
 *   safe (can be applied from the admin)
 *     added      new upstream, not in the project
 *     unchanged  changed upstream, the project still has the baseline content (or content the skeleton delivered)
 *     removed    removed upstream, the project still has the baseline content
 *   needs a developer (never applied automatically – a 3-way summary is shown)
 *     modified         changed upstream and changed in the project
 *     deleted-locally  changed upstream, deleted in the project
 *     exists-locally   new upstream, but the project already has a different file there
 *     removed-modified removed upstream, changed in the project
 *   info
 *     current    the project already has the target content
 *     protected  changed upstream in a file the updater never touches (.env, composer.lock, themes/, README …)
 */
final class SkeletonComparer
{
    public const SAFE = ['added', 'unchanged', 'removed'];

    public const DEVELOPER = ['modified', 'deleted-locally', 'exists-locally', 'removed-modified'];

    public const LABELS = [
        'added' => 'New file',
        'unchanged' => 'Unchanged here – safe to update',
        'removed' => 'Removed from the skeleton',
        'modified' => 'Changed here and upstream – needs a developer',
        'deleted-locally' => 'Deleted here, changed upstream – needs a developer',
        'exists-locally' => 'New upstream, a different file exists here – needs a developer',
        'removed-modified' => 'Removed upstream, changed here – needs a developer',
        'current' => 'Already up to date',
        'protected' => 'Never updated automatically',
    ];

    /** Files the updater never writes, even when unchanged (secrets, lock file, themes, project docs, the baseline). */
    public static function isProtected(string $path): bool
    {
        $path = ltrim($path, '/');
        if (in_array($path, ['.env', 'composer.lock', 'README.md', 'LICENSE', SkeletonBaseline::FILE], true)) {
            return true;
        }
        if (str_starts_with($path, '.env.') && $path !== '.env.example') {
            return true;
        }
        foreach (['themes/', 'vendor/', 'node_modules/', '.git/', 'public/vendor/', 'public/assets/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        // runtime data: only the skeleton's .gitignore placeholders under storage/
        if (str_starts_with($path, 'storage/') && basename($path) !== '.gitignore') {
            return true;
        }

        return false;
    }

    /** Relative paths of every file under $dir (no .git). @return list<string> */
    public static function files(?string $dir): array
    {
        if ($dir === null || ! is_dir($dir)) {
            return [];
        }
        $files = [];
        foreach ((new Finder)->files()->in($dir)->ignoreDotFiles(false)->ignoreVCS(true)->exclude(['.git'])->notName('.updater-complete') as $file) {
            $files[] = str_replace('\\', '/', $file->getRelativePathname());
        }
        sort($files);

        return $files;
    }

    /**
     * @param  array<string,mixed>  $baseline  the project's SkeletonBaseline (name, slug, files hashes)
     * @param  array<string,mixed>  $skeletonNames  name/slug the skeleton repository was rendered with
     * @return array{files: array<string, array{status:string, safe:bool, local_hash:?string, upstream:?array, local:?array, diff:?string}>, counts: array<string,int>}
     */
    public function compare(string $baseDir, string $targetDir, string $projectDir, array $baseline, array $skeletonNames = []): array
    {
        $map = SkeletonBaseline::replacements($skeletonNames, $baseline);
        $delivered = (array) ($baseline['files'] ?? []);

        $paths = array_values(array_unique(array_merge(static::files($baseDir), static::files($targetDir))));
        sort($paths);
        $files = [];
        foreach ($paths as $path) {
            $base = $this->read($baseDir, $path, $map);
            $target = $this->read($targetDir, $path, $map);
            if ($base === $target) {
                continue;
            }
            if (static::isProtected($path)) {
                $files[$path] = $this->entry('protected', null);
                continue;
            }
            $local = is_file($projectDir.'/'.$path) ? (string) file_get_contents($projectDir.'/'.$path) : null;
            $localHash = $local === null ? null : SkeletonBaseline::hash($local);
            // the project still has what the skeleton gave it: the baseline content, or (since creation / last apply)
            // the exact content recorded for this path
            $pristine = $local !== null && ($local === $base || ($delivered[$path] ?? null) === $localHash);

            if ($local !== null && $target !== null && $local === $target) {
                $status = 'current';
            } elseif ($base === null) {
                $status = $local === null ? 'added' : 'exists-locally';
            } elseif ($target === null) {
                $status = $local === null ? 'current' : ($pristine ? 'removed' : 'removed-modified');
            } elseif ($local === null) {
                $status = 'deleted-locally';
            } else {
                $status = $pristine ? 'unchanged' : 'modified';
            }
            if ($status === 'current' && $local === null && $target === null) {
                continue;
            }

            $entry = $this->entry($status, $localHash);
            if (in_array($status, ['unchanged', 'modified', 'added', 'exists-locally', 'removed', 'removed-modified'], true)) {
                $upstream = LineDiff::compare((string) $base, (string) $target);
                $entry['upstream'] = ['added' => $upstream['added'], 'removed' => $upstream['removed']];
                $entry['diff'] = $upstream['diff'];
            }
            if (in_array($status, ['modified', 'removed-modified', 'exists-locally'], true)) {
                $mine = LineDiff::compare((string) $base, (string) $local, 0);
                $entry['local'] = ['added' => $mine['added'], 'removed' => $mine['removed']];
            }
            $files[$path] = $entry;
        }

        $counts = array_fill_keys(array_keys(self::LABELS), 0);
        foreach ($files as $entry) {
            $counts[$entry['status']]++;
        }

        return ['files' => $files, 'counts' => $counts];
    }

    /** Content with the skeleton's placeholders mapped to this project's names (text files only). */
    protected function read(string $dir, string $path, array $map): ?string
    {
        $file = $dir.'/'.$path;
        if (! is_file($file)) {
            return null;
        }
        $content = (string) file_get_contents($file);

        return $map && ! str_contains($content, "\0") ? strtr($content, $map) : $content;
    }

    protected function entry(string $status, ?string $localHash): array
    {
        return ['status' => $status, 'safe' => in_array($status, self::SAFE, true), 'local_hash' => $localHash,
            'upstream' => null, 'local' => null, 'diff' => null];
    }
}
