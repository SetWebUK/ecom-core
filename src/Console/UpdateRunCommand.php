<?php

namespace Pine\Commerce\Console;

use Illuminate\Console\Command;
use Pine\Commerce\Models\PlatformUpdate;
use Pine\Commerce\Updater\UpdateChecker;
use Pine\Commerce\Updater\UpdateManager;
use Pine\Commerce\Updater\Updater;
use Pine\Commerce\Updater\UpdateRunner;
use Throwable;

/**
 * Install an APPROVED pine/commerce update.
 *
 *   php artisan commerce:update:run 12                  run update #12, approved in Admin › Updates (what the admin
 *                                                        starts in the background; also the manual fallback)
 *   php artisan commerce:update:run --approve --yes     check, approve the newest installable release as "CLI" and
 *                                                        install it now (both flags are required)
 *
 * Steps, rollback and backups: Pine\Commerce\Updater\UpdateRunner, docs/PLAYBOOK.md part 3.6.
 */
class UpdateRunCommand extends Command
{
    protected $signature = 'commerce:update:run
        {id? : An update approved in Admin › Updates}
        {--approve : Approve the newest installable release now (CLI approval)}
        {--yes : Do not ask for confirmation (required with --approve when not interactive)}';

    protected $description = 'Install an approved pine/commerce update (backup, maintenance mode, composer, migrate, publish, health check, rollback on failure)';

    public function handle(UpdateRunner $runner, UpdateManager $manager, UpdateChecker $checker): int
    {
        if (! Updater::enabled()) {
            $this->error('The updater is switched off (config commerce.features.updater).');

            return self::FAILURE;
        }

        if ($this->argument('id') !== null) {
            $update = PlatformUpdate::query()->where('type', PlatformUpdate::TYPE_CORE)->find((int) $this->argument('id'));
            if (! $update || $update->status !== 'approved' || ! $update->approved_at) {
                $this->error('Update #'.$this->argument('id').' is not an approved update waiting to run'.($update ? " (status: {$update->status})" : '').'.');

                return self::FAILURE;
            }
        } else {
            if (! $this->option('approve')) {
                $this->error('Updates must be approved: approve one in Admin › Updates (then this command runs it), or pass --approve --yes to approve from the command line.');

                return self::FAILURE;
            }
            $check = $checker->check(null, 'cli');
            if ($check->result['error'] ?? null) {
                $this->error($check->result['error']);

                return self::FAILURE;
            }
            if (! ($check->result['update_available'] ?? false)) {
                $this->info('pine/commerce '.($check->result['installed_pretty'] ?? '').' is up to date – nothing to install.');

                return self::SUCCESS;
            }
            $to = $check->result['latest'];
            if ($check->result['actions_required'] ?? false) {
                $this->warn("The changelog up to {$to} lists client actions – read them first: php artisan commerce:update:check");
            }
            if (! $this->option('yes') && ! ($this->input->isInteractive() && $this->confirm("Install pine/commerce {$to} now (backup, maintenance mode, composer update, migrations)?"))) {
                $this->error('Not approved. Pass --yes to confirm.');

                return self::FAILURE;
            }
            try {
                $update = $manager->approve($check, null, 'cli');
            } catch (Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }

        try {
            $ok = $runner->run($update, fn (string $line) => $this->line($line));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
