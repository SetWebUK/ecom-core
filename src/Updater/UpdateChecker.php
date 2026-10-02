<?php

namespace Pine\Commerce\Updater;

use Pine\Commerce\Commerce;
use Pine\Commerce\Models\PlatformUpdate;
use Pine\Commerce\Models\User;
use Pine\Commerce\Updater\Skeleton\SkeletonBaseline;
use Pine\Commerce\Updater\Skeleton\SkeletonUpdater;
use Throwable;

/**
 * Update check (Admin › Updates › Check now, `commerce:update:check`, the daily scheduled task "updates.check"):
 * finds the newest pine/commerce release the project may install (semver + its composer constraint), the newer
 * majors that need a developer, the CHANGELOG sections in between (with their "Client actions required") and
 * whether a newer skeleton release exists. Never installs anything. Every check is stored as a "check" audit row;
 * the newest one is what the admin shows (the cached result).
 */
class UpdateChecker
{
    public function __construct(
        protected ReleaseSource $source,
        protected ComposerProject $project,
        protected SkeletonUpdater $skeleton,
    ) {}

    public function check(?User $user = null, string $via = 'admin'): PlatformUpdate
    {
        $result = $this->result();

        return PlatformUpdate::create([
            'type' => PlatformUpdate::TYPE_CHECK,
            'status' => $result['error'] ? 'failed' : 'checked',
            'via' => $via,
            'user_id' => $user?->getKey(),
            'user_email' => $user?->email ?? ($via === 'cli' ? 'CLI' : null),
            'from_version' => $result['installed'],
            'to_version' => $result['latest'],
            'result' => $result,
            'error' => $result['error'],
            'finished_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    public function result(): array
    {
        $repository = $this->project->repository();
        $installed = $this->project->installedVersion();
        $result = [
            'checked_at' => now()->toIso8601String(),
            'installed' => $installed,
            'installed_pretty' => $this->project->installedPrettyVersion() ?? Commerce::VERSION,
            'constraint' => $this->project->constraint(),
            'repository' => $repository['url'],
            'repository_type' => $repository['type'],
            'repository_source' => $repository['source'],
            'preferred_install' => $this->project->preferredInstall(),
            'via' => null,
            'notes' => [],
            'latest' => null,
            'latest_tag' => null,
            'update_available' => false,
            'blocked' => null,
            'changelog' => [],
            'actions_required' => false,
            'changelog_error' => null,
            'skeleton' => null,
            'error' => null,
        ];

        if ($repository['type'] === 'path') {
            $result['error'] = 'pine/commerce comes from a path repository (a development checkout): update it with git and composer, not from the admin.';

            return $result;
        }
        if ($installed === null) {
            $result['error'] = 'The installed pine/commerce is not a release ('.($result['installed_pretty'] ?: 'not found in vendor/composer/installed.json').'): only tagged releases can be updated from the admin.';

            return $result;
        }
        if (! Versions::validConstraint($result['constraint'])) {
            $result['error'] = 'composer.json has no valid version constraint for pine/commerce.';

            return $result;
        }
        if (! $repository['url']) {
            $result['error'] = 'No repository for pine/commerce: set commerce.updater.repository.';

            return $result;
        }

        try {
            $tags = $this->source->tags($repository['url']);
            $result['via'] = $tags['via'];
            $result['notes'] = $tags['notes'];
            $classified = Versions::classify($installed, $result['constraint'], $tags['tags']);
            $result['latest'] = $classified['latest'];
            $result['latest_tag'] = $classified['latest_tag'];
            $result['update_available'] = $classified['latest'] !== null;
            $result['blocked'] = $classified['blocked'];
        } catch (Throwable $e) {
            $result['error'] = 'Could not read the releases: '.$e->getMessage();

            return $result;
        }

        $newest = $result['latest'] ?? $result['blocked']['version'] ?? null;
        $newestTag = $result['latest_tag'] ?? $result['blocked']['tag'] ?? null;
        if ($newest && $newestTag) {
            try {
                $markdown = $this->source->file($repository['url'], $newestTag, 'CHANGELOG.md');
                $result['changelog'] = Changelog::between($markdown, $installed, $newest);
                $result['actions_required'] = (bool) array_filter($result['changelog'], fn ($s) => $s['actions_required']);
            } catch (Throwable $e) {
                $result['changelog_error'] = $e->getMessage();
            }
        }

        $result['skeleton'] = $this->skeletonStatus();

        return $result;
    }

    /** @return array<string,mixed> */
    protected function skeletonStatus(): array
    {
        $baseline = SkeletonBaseline::read();
        $status = ['baseline' => $baseline['ref'] ?? null, 'enabled' => (bool) ($baseline['updates'] ?? false),
            'note' => $baseline['note'] ?? null, 'latest' => null, 'update_available' => false, 'error' => null];
        if ($baseline === null) {
            $status['note'] = 'No .commerce-skeleton.json: set a baseline with `php artisan commerce:skeleton:baseline`.';

            return $status;
        }
        try {
            $target = $this->skeleton->target();
            $status['latest'] = $target['tag'];
            $baseVersion = Versions::fromTag((string) $baseline['ref']);
            $targetVersion = $target['version'];
            $status['update_available'] = $status['enabled'] && $targetVersion !== null
                && ($baseVersion === null ? $target['tag'] !== $baseline['ref'] : Versions::greater($targetVersion, $baseVersion));
        } catch (Throwable $e) {
            $status['error'] = $e->getMessage();
        }

        return $status;
    }

    /**
     * What the admin shows: the newest check, adjusted to the version installed now (a check made before an update
     * still says "1.3.0 available" until the next check otherwise).
     *
     * @return array{check:?PlatformUpdate, available:bool, latest:?string, installed:?string}
     */
    public static function status(): array
    {
        $check = PlatformUpdate::latestCheck();
        $installed = app(ComposerProject::class)->installedVersion();
        $latest = $check?->result['latest'] ?? null;
        $available = $latest !== null && $installed !== null && Versions::greater($latest, $installed);

        return ['check' => $check, 'available' => $available, 'latest' => $latest, 'installed' => $installed];
    }
}
