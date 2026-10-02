<?php

namespace Pine\Commerce\Console;

use Illuminate\Console\Command;
use Pine\Commerce\Updater\Skeleton\SkeletonBaseline;
use Pine\Commerce\Updater\Skeleton\SkeletonUpdater;
use Pine\Commerce\Updater\Versions;

/**
 * Record which skeleton (ecom-skeleton) release this project was created from – `.commerce-skeleton.json`, written
 * automatically by `commerce:new-client`; this command is for projects created before 1.3 or by hand.
 *
 *   php artisan commerce:skeleton:baseline v1.3.0 --name="Acme Tools" --slug=acme
 *   php artisan commerce:skeleton:baseline --detect                 compare the newest releases with the project
 *   php artisan commerce:skeleton:baseline v1.3.0 --disabled --note="Not created from the skeleton"
 *   php artisan commerce:skeleton:baseline                          show the current baseline
 */
class SkeletonBaselineCommand extends Command
{
    protected $signature = 'commerce:skeleton:baseline
        {ref? : Skeleton tag or commit the project matches (e.g. v1.3.0)}
        {--detect : Find the skeleton release the project matches best (and use it with --write)}
        {--write : With --detect: record the best match}
        {--name= : The project name used for the skeleton placeholders (default: kept, or APP_NAME)}
        {--slug= : The project slug used for the skeleton placeholders (default: kept, or from the name)}
        {--repository= : Skeleton repository (default: config commerce.updater.skeleton_repository)}
        {--disabled : Record the baseline but switch skeleton file updates off for this project}
        {--enabled : Switch skeleton file updates on again}
        {--note= : Why (shown in Admin › Updates)}';

    protected $description = 'Show or set the skeleton release this project was created from (.commerce-skeleton.json)';

    public function handle(SkeletonUpdater $skeleton): int
    {
        $current = SkeletonBaseline::read();
        $ref = $this->argument('ref');
        $names = [
            'name' => $this->option('name') ?: ($current['name'] ?? (string) config('app.name', SkeletonBaseline::DEFAULT_NAME)),
            'slug' => $this->option('slug') ?: ($current['slug'] ?? \Illuminate\Support\Str::slug((string) ($this->option('name') ?: config('app.name', SkeletonBaseline::DEFAULT_SLUG)))),
        ];

        if ($this->option('detect')) {
            if ($this->option('repository')) {
                config(['commerce.updater.skeleton_repository' => $this->option('repository')]);
            }
            $results = $skeleton->detect(10, $names);
            if (! $results) {
                $this->error('No skeleton releases found.');

                return self::FAILURE;
            }
            $this->table(['Skeleton release', 'Identical files', 'Share'], array_map(fn ($r) => [$r['tag'], $r['matching'].' / '.$r['total'], round($r['ratio'] * 100).'%'], $results));
            $best = $results[0];
            $this->line("Best match: {$best['tag']} (".round($best['ratio'] * 100).'% of the skeleton files are identical here).');
            if ($best['ratio'] < 0.5) {
                $this->warn('Less than half of the files match: this project was probably not created from the skeleton. Record a baseline with --disabled.');
            }
            if (! $this->option('write')) {
                return self::SUCCESS;
            }
            $ref = $best['tag'];
        }

        if ($ref === null && ! $this->option('disabled') && ! $this->option('enabled')) {
            if (! $current) {
                $this->warn('No .commerce-skeleton.json in this project. Set one: php artisan commerce:skeleton:baseline <tag> (or --detect).');

                return self::SUCCESS;
            }
            $this->line(json_encode(array_diff_key($current, ['files' => 1]) + ['files' => count($current['files']).' recorded hashes'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        if ($ref !== null && ! preg_match('/^[\w.\/-]+$/', (string) $ref)) {
            $this->error("Not a git reference: {$ref}");

            return self::FAILURE;
        }

        $data = $current ?? [];
        if ($ref !== null) {
            $data['ref'] = (string) $ref;
            $data['commit'] = preg_match('/^[0-9a-f]{40}$/', (string) $ref) ? (string) $ref : null;
            $data['files'] = $ref === ($current['ref'] ?? null) ? ($current['files'] ?? []) : [];
        }
        if (empty($data['ref'])) {
            $this->error('Give the skeleton release: php artisan commerce:skeleton:baseline <tag>');

            return self::FAILURE;
        }
        if ($this->option('repository')) {
            $data['repository'] = (string) $this->option('repository');
        }
        $data['name'] = $names['name'];
        $data['slug'] = $names['slug'];
        if ($this->option('disabled')) {
            $data['updates'] = false;
        } elseif ($this->option('enabled')) {
            $data['updates'] = true;
        }
        if ($this->option('note') !== null) {
            $data['note'] = (string) $this->option('note') ?: null;
        }
        $data['created_by'] ??= 'commerce:skeleton:baseline';
        $data['updated_at'] = now()->toIso8601String();
        SkeletonBaseline::write($data);

        $this->components->info('Skeleton baseline: '.$data['ref'].(Versions::fromTag((string) $data['ref']) ? '' : ' (not a release tag)')
            .(($data['updates'] ?? true) ? '' : ' – skeleton file updates disabled').'.');
        $this->line('  written to '.SkeletonBaseline::path());

        return self::SUCCESS;
    }
}
