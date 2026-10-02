<?php

namespace Pine\Commerce\Updater\Skeleton;

use Pine\Commerce\Updater\Updater;
use RuntimeException;

/**
 * The project's skeleton baseline: `.commerce-skeleton.json` in the project root records which version of the
 * base system (ecom-skeleton) the project was created from, so skeleton updates can tell files the project changed
 * from files it never touched.
 *
 *   {
 *     "repository": "https://github.com/SetWebUK/ecom-skeleton.git",
 *     "ref": "v1.3.0",                  tag (or commit) of the skeleton repository the files match
 *     "commit": "…",                    resolved commit, when known
 *     "name": "Acme Tools", "slug": "acme",        this project's name/slug (skeleton placeholders)
 *     "updates": true,                  false = skeleton file updates are disabled for this project ("note" says why)
 *     "files": {"path": "sha1", …},    content hashes of files as the skeleton delivered them
 *     "created_by": "commerce:new-client", "updated_at": "…"
 *   }
 *
 * Written by `commerce:new-client` (and so present in ecom-skeleton itself), `commerce:skeleton:baseline` and the
 * updater after applying files.
 */
final class SkeletonBaseline
{
    public const FILE = '.commerce-skeleton.json';

    public const DEFAULT_NAME = 'Commerce Skeleton';

    public const DEFAULT_SLUG = 'commerce-skeleton';

    public static function path(?string $project = null): string
    {
        return rtrim($project ?? Updater::projectPath(), '/').'/'.self::FILE;
    }

    /** @return array<string,mixed>|null */
    public static function read(?string $project = null): ?array
    {
        $file = static::path($project);
        if (! is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? static::normalise($data) : null;
    }

    /** @param array<string,mixed> $data */
    public static function write(array $data, ?string $project = null): void
    {
        $data = static::normalise($data);
        ksort($data['files']);
        $data['files'] = (object) $data['files']; // {} rather than [] when empty
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        if (file_put_contents(static::path($project), $json) === false) {
            throw new RuntimeException('Cannot write '.static::path($project).'.');
        }
    }

    /** @return array<string,mixed> */
    public static function normalise(array $data): array
    {
        return array_replace([
            'repository' => (string) config('commerce.updater.skeleton_repository', 'https://github.com/SetWebUK/ecom-skeleton.git'),
            'ref' => null,
            'commit' => null,
            'name' => self::DEFAULT_NAME,
            'slug' => self::DEFAULT_SLUG,
            'updates' => true,
            'note' => null,
            'created_by' => null,
            'updated_at' => null,
            'files' => [],
        ], $data, ['files' => array_filter((array) ($data['files'] ?? []), 'is_string')]);
    }

    public static function hash(string $content): string
    {
        return sha1(str_replace("\r\n", "\n", $content));
    }

    /** Placeholder replacements from the skeleton's names to the project's names. @return array<string,string> */
    public static function replacements(array $skeleton, array $project): array
    {
        $pairs = [];
        $fromName = (string) ($skeleton['name'] ?? self::DEFAULT_NAME);
        $fromSlug = (string) ($skeleton['slug'] ?? self::DEFAULT_SLUG);
        $toName = (string) ($project['name'] ?? self::DEFAULT_NAME);
        $toSlug = (string) ($project['slug'] ?? self::DEFAULT_SLUG);
        if ($fromName !== $toName && $fromName !== '') {
            $pairs[$fromName] = $toName;
        }
        if ($fromSlug !== $toSlug && $fromSlug !== '') {
            $pairs[$fromSlug] = $toSlug;
        }

        return $pairs;
    }
}
