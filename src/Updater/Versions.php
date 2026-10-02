<?php

namespace Pine\Commerce\Updater;

use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Throwable;

/**
 * Semantic-version helpers of the updater (composer/semver): tag parsing, comparison and composer constraints.
 * Only stable vMAJOR.MINOR.PATCH tags count as releases.
 */
final class Versions
{
    /** "v1.2.3" / "1.2.3" → "1.2.3"; null for anything else (pre-releases, branches, other tags). */
    public static function fromTag(string $tag): ?string
    {
        return preg_match('/^v?(\d+)\.(\d+)\.(\d+)$/', trim($tag), $m) ? "{$m[1]}.{$m[2]}.{$m[3]}" : null;
    }

    public static function major(string $version): int
    {
        return (int) explode('.', $version)[0];
    }

    public static function greater(string $a, string $b): bool
    {
        return Comparator::greaterThan($a, $b);
    }

    public static function compare(string $a, string $b): int
    {
        return Comparator::greaterThan($a, $b) ? 1 : (Comparator::lessThan($a, $b) ? -1 : 0);
    }

    /** Does $version satisfy the composer constraint? An empty or invalid constraint never does. */
    public static function satisfies(string $version, ?string $constraint): bool
    {
        if ($constraint === null || trim($constraint) === '') {
            return false;
        }
        try {
            return Semver::satisfies($version, $constraint);
        } catch (Throwable) {
            return false;
        }
    }

    public static function validConstraint(?string $constraint): bool
    {
        try {
            (new VersionParser)->parseConstraints((string) $constraint);

            return trim((string) $constraint) !== '';
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Sort tags into what the admin may install and what needs a developer.
     *
     * @param  list<string>  $tags
     * @return array{latest:?string, latest_tag:?string, newer:list<string>, blocked:?array{version:string, tag:string, reason:string}}
     *   latest = newest release above $installed that satisfies $constraint (installable from the admin);
     *   blocked = the newest release above $installed that does not (a new major, or outside the constraint).
     */
    public static function classify(string $installed, ?string $constraint, array $tags): array
    {
        $releases = [];
        foreach ($tags as $tag) {
            if (($version = static::fromTag((string) $tag)) !== null && ! isset($releases[$version])) {
                $releases[$version] = (string) $tag;
            }
        }
        uksort($releases, fn ($a, $b) => static::compare($b, $a)); // newest first

        $latest = null;
        $blocked = null;
        $newer = [];
        foreach ($releases as $version => $tag) {
            if (! static::greater($version, $installed)) {
                continue;
            }
            $newer[] = $version;
            if (static::satisfies($version, $constraint)) {
                $latest ??= $version;
            } elseif ($blocked === null) {
                $reason = static::major($version) > static::major($installed)
                    ? 'A new major version: it may change the public API. A developer must update the composer constraint, follow the upgrade notes and test it first.'
                    : "Outside this project's composer constraint ({$constraint}). A developer must change composer.json to install it.";
                $blocked = ['version' => $version, 'tag' => $tag, 'reason' => $reason];
            }
        }
        // only report a blocked release that is newer than the installable one
        if ($blocked && $latest && ! static::greater($blocked['version'], $latest)) {
            $blocked = null;
        }

        return ['latest' => $latest, 'latest_tag' => $latest ? $releases[$latest] : null, 'newer' => $newer, 'blocked' => $blocked];
    }
}
