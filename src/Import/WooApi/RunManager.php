<?php

namespace Pine\Commerce\Import\WooApi;

use Pine\Commerce\Models\User;
use Pine\Commerce\Models\WooApiImport;
use Pine\Commerce\Updater\Environment;
use Pine\Commerce\Updater\ProcessRunner;
use Pine\Commerce\Updater\UpdateLock;
use RuntimeException;
use Throwable;

/**
 * Starts, watches and cancels WooCommerce API import runs. Like the platform updater there is no queue worker and no
 * cron on shared hosting, so a run is a detached `php artisan commerce:import-woo-api {id}` process (proc_open +
 * setsid/nohup via ProcessRunner::launch) holding an exclusive lock file for its whole life; the admin page polls the
 * run's row. A run whose process is gone without finishing becomes "interrupted" and can be resumed.
 */
class RunManager
{
    public function __construct(protected ProcessRunner $runner, protected Environment $environment) {}

    public static function lock(): UpdateLock
    {
        $dir = RunLog::directory();
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir}.");
        }

        return new UpdateLock($dir.'/import.lock');
    }

    /**
     * A new run (status pending).
     *
     * @param  array{entities:list<string>, images?:bool, existing?:string, orders_after?:?string, since?:?string, notes?:bool}  $options
     */
    public function create(Connection $connection, array $options, bool $dryRun, bool $sameSite, ?User $user, string $via = 'admin'): WooApiImport
    {
        if ($open = WooApiImport::openRun()) {
            $open = $this->reconcile($open);
            if ($open->isOpen()) {
                throw new RuntimeException("Import #{$open->id} is still {$open->status} – wait for it or cancel it first.");
            }
        }
        $choices = $connection->store ? Importer::STORE_CHOICES : array_keys(Importer::CHOICES);
        $entities = array_values(array_intersect($choices, (array) ($options['entities'] ?? [])));
        if (! $entities) {
            throw new RuntimeException('Choose at least one thing to import'.($connection->store ? ' (the public Store API has the catalogue, pages, posts and media only).' : '.'));
        }

        return WooApiImport::create([
            'status' => 'pending',
            'mode' => $connection->store ? 'store' : 'rest',
            'site_url' => $connection->url,
            'source' => $sameSite ? null : $connection->sourceKey(),
            'dry_run' => $dryRun,
            'options' => [
                'entities' => $entities,
                'images' => (bool) ($options['images'] ?? true),
                'existing' => ($options['existing'] ?? 'update') === 'skip' ? 'skip' : 'update',
                'orders_after' => ($options['orders_after'] ?? null) ?: null,
                'since' => ($options['since'] ?? null) ?: null,
                'notes' => (bool) ($options['notes'] ?? true),
                'same_site' => $sameSite,
            ],
            'progress' => ['entities' => [], 'issues' => []],
            'via' => $via,
            'user_id' => $user?->getKey(),
            'user_email' => $user?->email ?? ($via === 'cli' ? 'CLI'.(function_exists('get_current_user') ? ' ('.get_current_user().')' : '') : null),
        ]);
    }

    /** Start `php artisan commerce:import-woo-api {id}` detached. @return array{started:bool, pid:?int, error:?string, command:string} */
    public function launch(WooApiImport $run): array
    {
        $manual = 'php artisan commerce:import-woo-api '.$run->id;
        $php = $this->environment->php();
        if (! $php) {
            return ['started' => false, 'pid' => null, 'error' => 'No PHP command-line binary found (set commerce.updater.php_binary).', 'command' => $manual];
        }
        try {
            $log = RunLog::for($run->id)->file;
            $pid = $this->runner->launch([$php, base_path('artisan'), 'commerce:import-woo-api', (string) $run->id], base_path(),
                $this->environment->variables(), $log);
            $run->forceFill(['pid' => $pid])->save();

            return ['started' => true, 'pid' => $pid, 'error' => null, 'command' => $manual];
        } catch (Throwable $e) {
            return ['started' => false, 'pid' => null, 'error' => $e->getMessage(), 'command' => $manual];
        }
    }

    /** A running run whose process has gone (killed, server restart, PHP time limit) → interrupted (resumable). */
    public function reconcile(WooApiImport $run): WooApiImport
    {
        if (! in_array($run->status, ['running', 'cancelling'], true) || static::lock()->held()) {
            return $run;
        }
        $pid = (int) $run->pid;
        if ($pid > 0 && \Pine\Commerce\Updater\UpdateManager::alive($pid) && $run->updated_at && $run->updated_at->gt(now()->subMinutes(10))) {
            return $run;
        }
        $run->forceFill([
            'status' => $run->status === 'cancelling' ? 'cancelled' : 'interrupted',
            'finished_at' => now(),
            'error' => $run->status === 'cancelling' ? null : 'The import process stopped without finishing (server restart or time limit). Resume it to continue where it stopped.',
        ])->save();

        return $run;
    }

    /** Ask a run to stop after its current page (or cancel it at once when it has not started). */
    public function cancel(WooApiImport $run): WooApiImport
    {
        $run = $this->reconcile($run);
        if ($run->status === 'pending') {
            $run->forceFill(['status' => 'cancelled', 'finished_at' => now()])->save();
        } elseif ($run->status === 'running') {
            $run->forceFill(['status' => 'cancelling'])->save();
        }

        return $run;
    }

    /** Make a stopped run startable again (its checkpoint and progress are kept). */
    public function resume(WooApiImport $run): WooApiImport
    {
        $run = $this->reconcile($run);
        if (! $run->isResumable()) {
            throw new RuntimeException('Only an interrupted, failed or cancelled import can be resumed.');
        }
        if (($open = WooApiImport::openRun()) && ! $open->is($run)) {
            throw new RuntimeException("Import #{$open->id} is running – wait for it first.");
        }
        $run->forceFill(['status' => 'pending', 'finished_at' => null, 'error' => null])->save();

        return $run;
    }
}
