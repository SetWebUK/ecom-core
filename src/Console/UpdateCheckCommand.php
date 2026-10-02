<?php

namespace Pine\Commerce\Console;

use Illuminate\Console\Command;
use Pine\Commerce\Updater\UpdateChecker;
use Pine\Commerce\Updater\Updater;

/**
 * Is a newer pine/commerce release available? (Admin › Updates "Check now".) Never installs anything.
 *
 *   php artisan commerce:update:check            summary + changelog headings + client actions
 *   php artisan commerce:update:check --json
 *
 * Exit code: 0 = checked (whether or not an update exists), 1 = the check failed.
 */
class UpdateCheckCommand extends Command
{
    protected $signature = 'commerce:update:check {--json : Print the result as JSON}';

    protected $description = 'Check for a newer pine/commerce release (and skeleton release) – never installs anything';

    public function handle(UpdateChecker $checker): int
    {
        if (! Updater::enabled()) {
            $this->error('The updater is switched off (config commerce.features.updater).');

            return self::FAILURE;
        }
        $check = $checker->check(null, 'cli');
        $result = $check->result;
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $result['error'] ? self::FAILURE : self::SUCCESS;
        }

        $this->line("Installed: pine/commerce {$result['installed_pretty']} · constraint {$result['constraint']} · {$result['repository']}"
            .($result['via'] ? " (via {$result['via']})" : ''));
        foreach ($result['notes'] as $note) {
            $this->line("  <fg=gray>{$note}</>");
        }
        if ($result['error']) {
            $this->error($result['error']);

            return self::FAILURE;
        }
        if ($result['update_available']) {
            $this->components->warn("Update available: {$result['latest']} – approve it in Admin › Updates or run `php artisan commerce:update:run --approve --yes`.");
        } else {
            $this->components->info('pine/commerce is up to date within '.$result['constraint'].'.');
        }
        if ($result['blocked']) {
            $this->components->warn("{$result['blocked']['version']} also exists – requires a developer: {$result['blocked']['reason']}");
        }
        foreach ($result['changelog'] as $section) {
            $this->line('');
            $this->line("<options=bold>[{$section['version']}]</> ".($section['date'] ?? ''));
            if ($section['client_actions'] !== null) {
                $this->line(($section['actions_required'] ? '<fg=yellow>Client actions required:</>' : 'Client actions:').' '.str_replace("\n", "\n  ", $section['client_actions']));
            }
        }
        if ($result['changelog_error']) {
            $this->warn('Changelog not available: '.$result['changelog_error']);
        }
        $skeleton = $result['skeleton'] ?? null;
        if ($skeleton) {
            $this->line('');
            $this->line('Skeleton baseline: '.($skeleton['baseline'] ?? 'none').($skeleton['enabled'] ? '' : ' (skeleton file updates disabled)')
                .' · newest skeleton for this core: '.($skeleton['latest'] ?? '?').($skeleton['update_available'] ? ' – compare with `php artisan commerce:skeleton:check`' : ''));
            if ($skeleton['note']) {
                $this->line('  <fg=gray>'.$skeleton['note'].'</>');
            }
            if ($skeleton['error']) {
                $this->line('  <fg=yellow>Skeleton releases not available: '.$skeleton['error'].'</>');
            }
        }

        return self::SUCCESS;
    }
}
