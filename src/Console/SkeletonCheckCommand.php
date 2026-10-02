<?php

namespace Pine\Commerce\Console;

use Illuminate\Console\Command;
use Pine\Commerce\Updater\Skeleton\SkeletonComparer;
use Pine\Commerce\Updater\Skeleton\SkeletonUpdater;
use Pine\Commerce\Updater\Updater;
use Throwable;

/**
 * Compare the project with the newest skeleton release for the installed core (Admin › Updates › Skeleton files).
 *
 *   php artisan commerce:skeleton:check                       list what changed upstream and how each file is classified
 *   php artisan commerce:skeleton:check --apply-safe --yes    apply every file that is unchanged here (backups kept)
 *
 * Files changed in this project are never applied: they are listed for a developer.
 */
class SkeletonCheckCommand extends Command
{
    protected $signature = 'commerce:skeleton:check
        {--apply-safe : Apply every safe file (new upstream, or unchanged in this project)}
        {--yes : Confirm --apply-safe without asking}';

    protected $description = 'Compare this project with the newest skeleton release and optionally apply the safe files';

    public function handle(SkeletonUpdater $skeleton): int
    {
        if (! Updater::enabled()) {
            $this->error('The updater is switched off (config commerce.features.updater).');

            return self::FAILURE;
        }
        try {
            $plan = $skeleton->plan(null, 'cli');
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $files = (array) $plan->result['files'];
        $this->line("Skeleton {$plan->from_version} → {$plan->to_version} (comparison #{$plan->getKey()})");
        if (! $files) {
            $this->components->info('Nothing changed in the skeleton files between these releases.');

            return self::SUCCESS;
        }
        $this->table(['File', 'Status', 'Upstream', 'Here'], array_map(fn ($path, $e) => [
            $path, SkeletonComparer::LABELS[$e['status']] ?? $e['status'],
            $e['upstream'] ? '+'.$e['upstream']['added'].' −'.$e['upstream']['removed'] : '',
            $e['local'] ? '+'.$e['local']['added'].' −'.$e['local']['removed'] : '',
        ], array_keys($files), $files));

        $safe = array_keys(array_filter($files, fn ($e) => $e['safe']));
        if (! $this->option('apply-safe')) {
            $this->line(count($safe).' safe file(s). Apply them in Admin › Updates or with --apply-safe --yes.');

            return self::SUCCESS;
        }
        if (! $safe) {
            $this->info('No safe files to apply.');

            return self::SUCCESS;
        }
        if (! $this->option('yes') && ! ($this->input->isInteractive() && $this->confirm('Apply '.count($safe).' safe file(s)?'))) {
            $this->error('Not applied. Pass --yes to confirm.');

            return self::FAILURE;
        }
        $result = $skeleton->apply($plan, $safe);
        $this->components->info(count($result['applied']).' file(s) applied'.($result['baseline_moved'] ? '; baseline is now '.$plan->to_version : '').'.');
        foreach ($result['skipped'] as $path => $why) {
            $this->warn("skipped {$path}: {$why}");
        }

        return self::SUCCESS;
    }
}
