<?php

namespace Pine\Commerce\Updater\Skeleton;

use Illuminate\Support\Facades\File;
use Pine\Commerce\Models\PlatformUpdate;
use Pine\Commerce\Models\User;
use Pine\Commerce\Updater\ComposerProject;
use Pine\Commerce\Updater\ReleaseSource;
use Pine\Commerce\Updater\Updater;
use Pine\Commerce\Updater\Versions;
use RuntimeException;

/**
 * Skeleton file updates (Admin › Updates › Skeleton files, `commerce:skeleton:*`):
 *
 *  1. target(): the newest skeleton tag that is not newer than the installed pine/commerce (the skeleton is tagged
 *     with the core version it was exported from, so its files fit the core that is installed);
 *  2. plan():   shallow checkouts of the baseline ref and the target into {workPath}/skeleton/, classified by
 *               SkeletonComparer, stored as a "skeleton" audit row (status planned);
 *  3. apply():  writes only the selected SAFE files (re-checked against the plan), keeps a backup of every file it
 *               overwrites or deletes ({workPath}/skeleton-backups/{id}/), records their hashes in the baseline and
 *               moves the baseline to the target once nothing is left to apply or review.
 */
class SkeletonUpdater
{
    public function __construct(protected ReleaseSource $source, protected SkeletonComparer $comparer, protected ComposerProject $project) {}

    public function repository(?array $baseline = null): string
    {
        $baseline ??= SkeletonBaseline::read();

        return (string) ($baseline['repository'] ?? '') ?: (string) config('commerce.updater.skeleton_repository', 'https://github.com/SetWebUK/ecom-skeleton.git');
    }

    /**
     * Newest skeleton release not newer than the installed core.
     *
     * @return array{tag:?string, version:?string, via:string, tags:list<string>}
     */
    public function target(?string $repository = null): array
    {
        $tags = $this->source->tags($repository ?? $this->repository());
        $installed = $this->project->installedVersion();
        $best = null;
        foreach ($tags['tags'] as $tag) {
            $version = Versions::fromTag($tag);
            if ($version === null || ($installed !== null && Versions::greater($version, $installed))) {
                continue;
            }
            if ($best === null || Versions::greater($version, $best[1])) {
                $best = [$tag, $version];
            }
        }

        return ['tag' => $best[0] ?? null, 'version' => $best[1] ?? null, 'via' => $tags['via'], 'tags' => $tags['tags']];
    }

    /** Compare the project with the target skeleton and store the plan. */
    public function plan(?User $user = null, string $via = 'admin'): PlatformUpdate
    {
        $baseline = SkeletonBaseline::read();
        if ($baseline === null || empty($baseline['ref'])) {
            throw new RuntimeException('This project has no skeleton baseline (.commerce-skeleton.json). Set one with `php artisan commerce:skeleton:baseline {ref}`.');
        }
        if (! $baseline['updates']) {
            throw new RuntimeException('Skeleton file updates are disabled for this project'.($baseline['note'] ? ': '.$baseline['note'] : '.'));
        }
        $repository = $this->repository($baseline);
        $target = $this->target($repository);
        if ($target['tag'] === null) {
            throw new RuntimeException('No skeleton release found in '.$repository.'.');
        }

        $baseDir = $this->source->checkout($repository, (string) ($baseline['commit'] ?: $baseline['ref']), 'skeleton');
        $targetDir = $this->source->checkout($repository, $target['tag'], 'skeleton');
        $plan = $this->comparer->compare($baseDir, $targetDir, Updater::projectPath(), $baseline, $this->skeletonNames($targetDir));

        return PlatformUpdate::create([
            'type' => PlatformUpdate::TYPE_SKELETON, 'status' => 'planned', 'via' => $via,
            'user_id' => $user?->getKey(), 'user_email' => $user?->email ?? ($via === 'cli' ? 'CLI' : null),
            'from_version' => (string) $baseline['ref'], 'to_version' => $target['tag'],
            'result' => $plan + ['repository' => $repository, 'target_commit' => ReleaseSource::checkoutCommit($targetDir),
                'skeleton_names' => $this->skeletonNames($targetDir), 'compared_at' => now()->toIso8601String()],
            'finished_at' => now(),
        ]);
    }

    /**
     * Apply the selected safe files of a plan.
     *
     * @param  list<string>  $paths
     * @return array{applied:list<string>, skipped:array<string,string>, baseline_moved:bool}
     */
    public function apply(PlatformUpdate $plan, array $paths, ?User $user = null): array
    {
        if ($plan->type !== PlatformUpdate::TYPE_SKELETON || $plan->status !== 'planned') {
            throw new RuntimeException('Only a skeleton comparison that has not been applied yet can be applied.');
        }
        $baseline = SkeletonBaseline::read();
        if ($baseline === null || ! $baseline['updates']) {
            throw new RuntimeException('Skeleton file updates are disabled for this project.');
        }
        if ((string) $baseline['ref'] !== (string) $plan->from_version) {
            throw new RuntimeException('The baseline changed since this comparison was made. Compare again.');
        }
        $files = (array) ($plan->result['files'] ?? []);
        $repository = (string) ($plan->result['repository'] ?? $this->repository($baseline));
        $targetDir = $this->source->checkout($repository, (string) $plan->to_version, 'skeleton');
        $map = SkeletonBaseline::replacements((array) ($plan->result['skeleton_names'] ?? []), $baseline);
        $project = Updater::projectPath();
        $backup = Updater::workPath('skeleton-backups/'.$plan->getKey());

        $applied = [];
        $skipped = [];
        foreach (array_unique($paths) as $path) {
            $entry = $files[$path] ?? null;
            if (! is_array($entry) || empty($entry['safe']) || SkeletonComparer::isProtected($path) || str_contains($path, '..')) {
                $skipped[$path] = 'not a safe file of this comparison';
                continue;
            }
            $file = $project.'/'.$path;
            $current = is_file($file) ? SkeletonBaseline::hash((string) file_get_contents($file)) : null;
            if ($current !== ($entry['local_hash'] ?? null)) {
                $skipped[$path] = 'changed since the comparison';
                continue;
            }
            if (is_file($file)) {
                File::ensureDirectoryExists(dirname($backup.'/'.$path));
                File::copy($file, $backup.'/'.$path);
            }
            if ($entry['status'] === 'removed') {
                File::delete($file);
                unset($baseline['files'][$path]);
            } else {
                $content = (string) file_get_contents($targetDir.'/'.$path);
                if ($map && ! str_contains($content, "\0")) {
                    $content = strtr($content, $map);
                }
                File::ensureDirectoryExists(dirname($file));
                File::put($file, $content);
                if (is_executable($targetDir.'/'.$path)) {
                    @chmod($file, 0755);
                }
                $baseline['files'][$path] = SkeletonBaseline::hash($content);
            }
            $applied[] = $path;
        }

        // nothing left to apply or review → the project matches the target: move the baseline there
        $remaining = array_filter($files, fn ($e, $p) => ! in_array($p, $applied, true) && ! in_array($e['status'], ['current', 'protected'], true), ARRAY_FILTER_USE_BOTH);
        $moved = $remaining === [];
        if ($moved) {
            $baseline['ref'] = (string) $plan->to_version;
            $baseline['commit'] = $plan->result['target_commit'] ?? null;
        }
        $baseline['updated_at'] = now()->toIso8601String();
        SkeletonBaseline::write($baseline);

        $plan->forceFill([
            'status' => 'applied', 'files' => $applied, 'finished_at' => now(),
            'user_id' => $user?->getKey() ?? $plan->user_id, 'user_email' => $user?->email ?? $plan->user_email,
            'meta' => array_replace($plan->meta ?? [], ['skipped' => $skipped, 'backup' => $applied ? $backup : null, 'baseline_moved' => $moved]),
            'log' => trim(($plan->log ? $plan->log."\n" : '').implode("\n", array_merge(
                array_map(fn ($p) => 'applied '.$p, $applied),
                array_map(fn ($p, $why) => "skipped {$p}: {$why}", array_keys($skipped), $skipped),
                [$moved ? 'baseline moved to '.$plan->to_version : 'baseline kept at '.$plan->from_version.' (files left to apply or review)'],
            ))),
        ])->save();

        return ['applied' => $applied, 'skipped' => $skipped, 'baseline_moved' => $moved];
    }

    /**
     * Which skeleton release does the project match best? For projects created before .commerce-skeleton.json
     * existed. Compares every non-protected file of the newest $limit releases with the project.
     *
     * @return list<array{tag:string, matching:int, total:int, ratio:float}>  best first
     */
    public function detect(int $limit = 10, ?array $names = null): array
    {
        $repository = $this->repository();
        $tags = array_values(array_filter($this->source->tags($repository)['tags'], fn ($t) => Versions::fromTag($t) !== null));
        usort($tags, fn ($a, $b) => Versions::compare(Versions::fromTag($b), Versions::fromTag($a)));
        $results = [];
        foreach (array_slice($tags, 0, $limit) as $tag) {
            $dir = $this->source->checkout($repository, $tag, 'skeleton');
            $map = SkeletonBaseline::replacements($this->skeletonNames($dir), $names ?? (SkeletonBaseline::read() ?? []));
            $matching = 0;
            $total = 0;
            foreach (SkeletonComparer::files($dir) as $path) {
                if (SkeletonComparer::isProtected($path)) {
                    continue;
                }
                $total++;
                $local = Updater::projectPath().'/'.$path;
                $content = (string) file_get_contents($dir.'/'.$path);
                if (is_file($local) && strtr($content, $map) === (string) file_get_contents($local)) {
                    $matching++;
                }
            }
            $results[] = ['tag' => $tag, 'matching' => $matching, 'total' => $total, 'ratio' => $total ? round($matching / $total, 3) : 0.0];
        }
        usort($results, fn ($a, $b) => ($b['matching'] <=> $a['matching']) ?: Versions::compare((string) Versions::fromTag($b['tag']), (string) Versions::fromTag($a['tag'])));

        return $results;
    }

    /** Name/slug the skeleton repository was rendered with (its own .commerce-skeleton.json). */
    protected function skeletonNames(string $dir): array
    {
        $own = SkeletonBaseline::read($dir);

        return ['name' => $own['name'] ?? SkeletonBaseline::DEFAULT_NAME, 'slug' => $own['slug'] ?? SkeletonBaseline::DEFAULT_SLUG];
    }
}
