<?php

namespace Pine\Commerce\Updater;

use Illuminate\Support\Str;
use Pine\Commerce\Models\PlatformUpdate;
use Pine\Commerce\Models\User;
use RuntimeException;
use Throwable;

/**
 * Approval and start of a core update (Admin › Updates "Approve & install", `commerce:update:run --approve`).
 *
 *  approve(): only the version the newest check found, only while nothing else is approved or running, only by a
 *             named administrator (or the CLI with --approve) – writes the "core" audit row (status approved) with a
 *             fresh maintenance bypass secret;
 *  launch():  starts `php artisan commerce:update:run {id}` detached from the web request (no queue worker needed);
 *  reconcile(): a run whose process is gone without finishing is marked failed ("interrupted").
 */
class UpdateManager
{
    public function __construct(
        protected ProcessRunner $runner,
        protected Environment $environment,
        protected ComposerProject $project,
        protected UpdateLock $lock,
    ) {}

    public function approve(PlatformUpdate $check, ?User $user, string $via = 'admin'): PlatformUpdate
    {
        if ($check->type !== PlatformUpdate::TYPE_CHECK || ! $check->is(PlatformUpdate::latestCheck())) {
            throw new RuntimeException('That check is out of date – check for updates again.');
        }
        $to = $check->result['latest'] ?? null;
        $installed = $this->project->installedVersion();
        if (! $to || ! $installed || ! Versions::greater($to, $installed) || ! Versions::satisfies($to, $this->project->constraint())) {
            throw new RuntimeException('There is no update to install.');
        }
        if ($open = PlatformUpdate::openRun()) {
            $open = $this->reconcile($open);
            if (in_array($open->status, PlatformUpdate::OPEN, true)) {
                throw new RuntimeException("Update #{$open->getKey()} is already {$open->status}.");
            }
        }
        if ($this->lock->held()) {
            throw new RuntimeException('Another update is running.');
        }

        return PlatformUpdate::create([
            'type' => PlatformUpdate::TYPE_CORE,
            'status' => 'approved',
            'via' => $via,
            'user_id' => $user?->getKey(),
            'user_email' => $user?->email ?? 'CLI'.(function_exists('get_current_user') ? ' ('.get_current_user().')' : ''),
            'from_version' => $installed,
            'to_version' => $to,
            'approved_at' => now(),
            'result' => ['check_id' => $check->getKey(), 'changelog' => $check->result['changelog'] ?? [],
                'actions_required' => (bool) ($check->result['actions_required'] ?? false)],
            'meta' => ['secret' => Str::random(32)],
        ]);
    }

    /** @return array{started:bool, pid:?int, error:?string, command:string} */
    public function launch(PlatformUpdate $update): array
    {
        $php = $this->environment->php();
        $command = [$php ?? 'php', $this->project->path('artisan'), 'commerce:update:run', (string) $update->getKey()];
        $manual = 'php artisan commerce:update:run '.$update->getKey();
        if (! $php) {
            return ['started' => false, 'pid' => null, 'error' => 'No PHP command-line binary found (set commerce.updater.php_binary).', 'command' => $manual];
        }
        try {
            $log = Updater::ensureDirectory(Updater::workPath('runs')).'/launch-'.$update->getKey().'.log';
            $pid = $this->runner->launch($command, $this->project->path(), $this->environment->variables(), $log);
            $update->putMeta(['launched_at' => now()->toIso8601String(), 'launch_pid' => $pid])->save();

            return ['started' => true, 'pid' => $pid, 'error' => null, 'command' => $manual];
        } catch (Throwable $e) {
            $update->putMeta(['launch_error' => $e->getMessage()])->save();

            return ['started' => false, 'pid' => null, 'error' => $e->getMessage(), 'command' => $manual];
        }
    }

    /** Mark a "running" update whose process has gone (killed, server restart) as failed. */
    public function reconcile(PlatformUpdate $update): PlatformUpdate
    {
        if ($update->status !== 'running' || $this->lock->held()) {
            return $update;
        }
        $pid = (int) $update->meta('pid');
        if ($pid > 0 && static::alive($pid)) {
            return $update;
        }
        $update->forceFill([
            'status' => 'failed',
            'error' => 'The update process stopped without finishing (interrupted). Check the site, then run `php artisan up` if it is still in maintenance mode, and `composer install` if vendor/ is incomplete.',
            'finished_at' => now(),
            'log' => rtrim((string) $update->log)."\n✗ Interrupted: the update process is no longer running.\n",
        ])->save();

        return $update;
    }

    public static function alive(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return is_dir('/proc/'.$pid);
    }
}
