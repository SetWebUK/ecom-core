<?php

namespace Pine\Commerce\Updater;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Pine\Commerce\Models\PlatformUpdate;
use RuntimeException;
use Throwable;

/**
 * Runs one APPROVED pine/commerce update (`php artisan commerce:update:run {id}`, started in the background by
 * Admin › Updates or in the foreground with `--approve --yes`). Only one runs at a time (UpdateLock).
 *
 * Steps: preflight → database backup → record composer files → maintenance mode (bypass secret) →
 * composer update pine/commerce --with-dependencies (pinned to the approved version) → migrate --force →
 * commerce:publish → commerce:theme:publish → optimize:clear + optimize → commerce:doctor --json → up.
 *
 * On any failure: composer.json/composer.lock are restored and `composer install` brings the previous code back,
 * the admin and theme assets are published again from it, caches are rebuilt and the site comes back up. The
 * database backup is kept; it is never restored automatically – the log says how (PLAYBOOK part 3.6).
 *
 * Every command is built here from a fixed list; nothing from the browser reaches a command line.
 */
class UpdateRunner
{
    public const STEPS = [
        'preflight' => 'Pre-flight checks',
        'backup' => 'Database backup',
        'snapshot' => 'Record the current version',
        'maintenance' => 'Maintenance mode on',
        'composer' => 'composer update pine/commerce',
        'migrate' => 'Database migrations',
        'publish' => 'Publish admin assets',
        'theme' => 'Publish theme assets',
        'optimize' => 'Rebuild caches',
        'health' => 'Health check',
        'up' => 'Maintenance mode off',
    ];

    protected PlatformUpdate $update;

    /** @var array{composer:bool, migrate:bool, down:bool, was_down:bool} */
    protected array $state = ['composer' => false, 'migrate' => false, 'down' => false, 'was_down' => false];

    /** @var (callable(string):void)|null */
    protected $echo = null;

    protected float $flushedAt = 0.0;

    protected ?string $logFile = null;

    /** @var list<string> */
    protected array $doctorBefore = [];

    public function __construct(
        protected ProcessRunner $runner,
        protected Environment $environment,
        protected ComposerProject $project,
        protected DatabaseBackup $backup,
        protected UpdateLock $lock,
    ) {}

    /**
     * @param  (callable(string):void)|null  $echo  also print each log line (CLI)
     */
    public function run(PlatformUpdate $update, ?callable $echo = null): bool
    {
        if ($update->type !== PlatformUpdate::TYPE_CORE || $update->status !== 'approved' || ! $update->approved_at) {
            throw new RuntimeException("Update #{$update->getKey()} is not an approved, waiting core update (status: {$update->status}). Approve it in Admin › Updates first.");
        }
        $this->update = $update;
        $this->echo = $echo;
        $this->logFile = Updater::ensureDirectory(Updater::workPath('runs')).'/update-'.$update->getKey().'.log';

        if (! $this->lock->acquire()) {
            $this->log('Another update is running (lock '.$this->lock->file().'). Nothing was changed.');
            $this->finish('failed', 'Another update is running.');

            return false;
        }
        static::preload();

        $update->forceFill(['status' => 'running', 'started_at' => now(), 'step' => null, 'error' => null])
            ->putMeta(['pid' => getmypid(), 'host_user' => function_exists('get_current_user') ? get_current_user() : null])->save();
        $this->log(sprintf('pine/commerce %s → %s, approved by %s at %s.', $update->from_version, $update->to_version,
            $update->actor(), $update->approved_at->toDateTimeString()));

        $step = null;
        try {
            foreach (self::STEPS as $step => $label) {
                $this->update->step = $step;
                $this->log('');
                $this->log('== '.$label);
                $this->flush(true);
                $this->{'step'.Str::studly($step)}();
            }
            $this->log('');
            $this->log("✓ Updated to pine/commerce {$update->to_version}.");
            $this->update->putMeta(['secret' => null]);
            $this->finish('succeeded');

            return true;
        } catch (Throwable $e) {
            $this->log('');
            $this->log('✗ '.self::STEPS[$step ?? 'preflight'].' failed: '.$e->getMessage());
            $this->update->putMeta(['failed_step' => $step]);
            $this->rollback($step);
            $this->update->putMeta(['secret' => null]);
            $this->finish('failed', $e->getMessage());

            return false;
        } finally {
            $this->lock->release();
        }
    }

    // ------------------------------------------------------------------ steps

    protected function stepPreflight(): void
    {
        $php = $this->environment->php();
        $composer = $this->environment->composerCommand();
        $env = $this->environment->variables();
        $this->log('Project: '.$this->project->path());
        $this->log('PHP: '.($php ?? 'NOT FOUND').' · composer: '.($composer ? implode(' ', $composer) : 'NOT FOUND'));
        $this->log('HOME='.$env['HOME'].' · COMPOSER_HOME='.$env['COMPOSER_HOME'].' · preferred-install: '.$this->project->preferredInstall());
        if (! $php) {
            throw new RuntimeException('No PHP command-line binary found: set commerce.updater.php_binary.');
        }
        if (! $composer) {
            throw new RuntimeException('composer was not found in PATH: set commerce.updater.composer_binary.');
        }

        $repository = $this->project->repository();
        if ($repository['type'] === 'path') {
            throw new RuntimeException('pine/commerce comes from a path repository – update a development checkout with git, not from the admin.');
        }
        $installed = $this->project->installedVersion();
        $to = (string) $this->update->to_version;
        if ($installed !== $this->update->from_version) {
            throw new RuntimeException("Installed version is {$installed}, but this update was approved for {$this->update->from_version}. Check for updates again.");
        }
        if (! Versions::greater($to, (string) $installed) || ! Versions::satisfies($to, $this->project->constraint())) {
            throw new RuntimeException("{$to} is not a newer release within composer.json's constraint ".$this->project->constraint().'.');
        }

        $free = @disk_free_space($this->project->path());
        $needed = (int) config('commerce.updater.min_free_mb', 1024);
        $this->log('Free disk space: '.($free === false ? 'unknown' : number_format($free / 1048576).' MB').' (needs '.$needed.' MB)');
        if ($free !== false && $free / 1048576 < $needed) {
            throw new RuntimeException('Not enough free disk space.');
        }

        $notWritable = [];
        foreach ([$this->project->path('vendor'), $this->project->path('composer.lock'), $this->project->path('composer.json'),
            $this->project->path('bootstrap/cache'), storage_path(), public_path(), Updater::workPath()] as $path) {
            if (file_exists($path) && ! is_writable($path)) {
                $notWritable[] = $path;
            }
        }
        if ($notWritable) {
            throw new RuntimeException('Not writable by the update process: '.implode(', ', $notWritable));
        }

        if (is_dir($this->project->path('.git'))) {
            $status = $this->runner->run([$this->environment->git(), 'status', '--porcelain', '--untracked-files=no'], $this->project->path(), $env, null, 60);
            $changed = array_values(array_filter(explode("\n", trim($status->output))));
            if ($status->ok() && $changed) {
                $this->log('WARNING: '.count($changed).' tracked file(s) changed in the project and not committed (they are not touched by the update):');
                foreach (array_slice($changed, 0, 10) as $line) {
                    $this->log('  '.$line);
                }
            } elseif ($status->ok()) {
                $this->log('Project git tree: clean');
            }
        }

        $this->command('composer --version', [...$composer, '--version', '--no-ansi'], 60);
        $this->artisan(['--version'], 60);
        $this->doctorBefore = $this->doctorFailures(false);
        $this->update->putMeta(['doctor_before' => $this->doctorBefore]);
        $this->log('commerce:doctor before the update: '.($this->doctorBefore ? count($this->doctorBefore).' failing check(s): '.implode('; ', $this->doctorBefore) : 'no failing checks'));
    }

    protected function stepBackup(): void
    {
        if (! filter_var(config('commerce.updater.backup', true), FILTER_VALIDATE_BOOL)) {
            $this->log('Skipped: commerce.updater.backup is false.');

            return;
        }
        $result = $this->backup->create('before-'.$this->update->to_version, fn ($line) => $this->log('  '.$line));
        $this->update->putMeta(['backup' => $result['path']]);
        $this->log($result['note']);
    }

    protected function stepSnapshot(): void
    {
        $dir = Updater::ensureDirectory($this->runDirectory());
        foreach (['composer.json', 'composer.lock'] as $file) {
            if (! is_file($this->project->path($file)) || ! @copy($this->project->path($file), $dir.'/'.$file)) {
                throw new RuntimeException("Could not copy {$file} to {$dir}.");
            }
        }
        $this->update->putMeta(['snapshot' => $dir, 'previous_pretty_version' => $this->project->installedPrettyVersion()]);
        $this->log('Saved composer.json and composer.lock to '.$dir);
        $this->log('Installed now: pine/commerce '.$this->project->installedPrettyVersion());
    }

    protected function stepMaintenance(): void
    {
        $this->state['was_down'] = is_file(storage_path('framework/down'));
        if ($this->state['was_down']) {
            $this->log('The site was already in maintenance mode – it stays down afterwards.');

            return;
        }
        $secret = (string) ($this->update->meta('secret') ?: Str::random(32));
        $this->update->putMeta(['secret' => $secret]);
        $this->state['down'] = true;
        $this->artisan(['down', '--secret='.$secret, '--retry=60'], 120);
        $this->log('Bypass link: shown on the update page to the administrator who approved it.');
    }

    protected function stepComposer(): void
    {
        $command = [...$this->environment->composerCommand(), 'update', 'pine/commerce', '--with-dependencies',
            '--with=pine/commerce:'.$this->update->to_version, '--no-interaction', '--no-progress', '--no-ansi'];
        if (! $this->project->devInstalled()) {
            $command[] = '--no-dev';
        }
        $this->state['composer'] = true;
        $this->command('composer update', $command, (int) config('commerce.updater.timeout', 900));
        $installed = $this->project->installedVersion();
        if ($installed !== $this->update->to_version) {
            throw new RuntimeException("composer finished, but pine/commerce is {$installed} instead of {$this->update->to_version}.");
        }
        $this->log('Installed: pine/commerce '.$this->project->installedPrettyVersion());
    }

    protected function stepMigrate(): void
    {
        $this->state['migrate'] = true;
        $this->artisan(['migrate', '--force', '--no-interaction']);
    }

    protected function stepPublish(): void
    {
        $this->artisan(['commerce:publish']);
    }

    protected function stepTheme(): void
    {
        $this->artisan(['commerce:theme:publish']);
    }

    protected function stepOptimize(): void
    {
        $this->artisan(['optimize:clear']);
        $this->artisan(['optimize']);
    }

    protected function stepHealth(): void
    {
        $after = $this->doctorFailures(true);
        $new = array_values(array_diff($after, $this->doctorBefore));
        $this->update->putMeta(['doctor_after' => $after]);
        if ($new) {
            throw new RuntimeException('commerce:doctor reports new failing checks: '.implode('; ', $new));
        }
        $this->log('commerce:doctor: no new failing checks'.($after ? ' ('.count($after).' already failing before the update)' : '').'.');
    }

    protected function stepUp(): void
    {
        if (! $this->state['down']) {
            $this->log('Nothing to do.');

            return;
        }
        $this->artisan(['up'], 120);
        $this->state['down'] = false;
    }

    // ------------------------------------------------------------------ rollback

    protected function rollback(?string $failedStep): void
    {
        $this->log('');
        $this->log('== Rolling back');
        if ($this->state['composer']) {
            $dir = $this->runDirectory();
            foreach (['composer.json', 'composer.lock'] as $file) {
                if (is_file($dir.'/'.$file) && @copy($dir.'/'.$file, $this->project->path($file))) {
                    $this->log("Restored {$file}.");
                } else {
                    $this->log("ERROR: could not restore {$file} from {$dir}.");
                }
            }
            $install = [...($this->environment->composerCommand() ?? ['composer']), 'install', '--no-interaction', '--no-progress', '--no-ansi'];
            if (! $this->project->devInstalled()) {
                $install[] = '--no-dev';
            }
            foreach ([['composer install', $install], ['publish', ['commerce:publish']], ['theme', ['commerce:theme:publish']],
                ['optimize:clear', ['optimize:clear']], ['optimize', ['optimize']]] as [$label, $command]) {
                try {
                    $label === 'composer install'
                        ? $this->command($label, $command, (int) config('commerce.updater.timeout', 900))
                        : $this->artisan($command);
                } catch (Throwable $e) {
                    $this->log("ERROR during rollback ({$label}): ".$e->getMessage());
                }
            }
            $this->log('Code restored: pine/commerce '.($this->project->installedPrettyVersion() ?? '?'));
        } else {
            $this->log('Nothing was installed – no code to restore.');
        }

        if ($this->state['migrate']) {
            $backup = $this->update->meta('backup');
            $this->log('');
            $this->log('DATABASE: the new version\'s migrations ran (or started) before the failure. They are additive');
            $this->log('(new tables/columns), so the previous version normally keeps working. The database was NOT restored');
            $this->log('automatically. If the shop misbehaves, restore the backup taken before the update:');
            $this->log($backup ? '  '.static::restoreHint((string) $backup) : '  (no backup was taken – see commerce.updater.backup)');
        }

        if ($this->state['down']) {
            try {
                $this->artisan(['up'], 120);
                $this->state['down'] = false;
                $this->log('The site is back up.');
            } catch (Throwable $e) {
                $this->log('ERROR: could not bring the site up: '.$e->getMessage().' – run `php artisan up` on the server.');
            }
        }
        if ($failedStep !== null) {
            $this->log('Failed at: '.(self::STEPS[$failedStep] ?? $failedStep).'.');
        }
    }

    /** How to restore a backup by hand (shown in the log and on the update page). */
    public static function restoreHint(string $backup): string
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        if (str_ends_with($backup, '.sqlite')) {
            return "cp {$backup} {$database}";
        }
        $read = str_ends_with($backup, '.gz') ? "gunzip -c {$backup}" : "cat {$backup}";

        return "{$read} | mysql -u <user> -p {$database}";
    }

    // ------------------------------------------------------------------ helpers

    /** @param list<string> $args */
    protected function artisan(array $args, ?int $timeout = null): ProcessResult
    {
        $php = $this->environment->php() ?? throw new RuntimeException('No PHP binary.');

        return $this->command('php artisan '.$args[0], [$php, $this->project->path('artisan'), ...$args, '--no-ansi'], $timeout ?? 600);
    }

    /** @param list<string> $command */
    protected function command(string $label, array $command, int $timeout): ProcessResult
    {
        $shown = implode(' ', array_map(fn ($part) => preg_replace('/^--secret=.*/', '--secret=******', $part), $command));
        $this->log('$ '.$shown);
        $result = $this->runner->run($command, $this->project->path(), $this->environment->variables(),
            fn (string $line) => $this->log('  '.$line), $timeout);
        if (! $result->ok()) {
            throw new RuntimeException("{$label} exited with code {$result->exitCode}".($result->lastLine() ? ': '.$result->lastLine() : '.'));
        }

        return $result;
    }

    /** Titles of the failing commerce:doctor checks. @return list<string> */
    protected function doctorFailures(bool $strict): array
    {
        $php = $this->environment->php();
        $result = $this->runner->run([$php, $this->project->path('artisan'), 'commerce:doctor', '--json', '--no-ansi'],
            $this->project->path(), $this->environment->variables(), null, 300);
        $json = json_decode(substr($result->output, (int) strpos($result->output, '{')), true);
        if (! is_array($json) || ! isset($json['checks'])) {
            if ($strict) {
                throw new RuntimeException('commerce:doctor --json did not answer: '.($result->lastLine() ?: 'exit code '.$result->exitCode));
            }
            $this->log('commerce:doctor could not run before the update: '.$result->lastLine());

            return [];
        }
        $failures = [];
        foreach ((array) $json['checks'] as $check) {
            if (($check['status'] ?? '') === 'fail') {
                $failures[] = (string) ($check['title'] ?? '?');
            }
        }

        return $failures;
    }

    protected function runDirectory(): string
    {
        return Updater::workPath('runs/'.$this->update->getKey());
    }

    public function log(string $line): void
    {
        $secret = (string) $this->update->meta('secret');
        if ($secret !== '') {
            $line = str_replace($secret, '******', $line);
        }
        // no terminal colours (composer scripts run `package:discover --ansi`)
        $line = rtrim((string) preg_replace('/\e\[[\d;?]*[A-Za-z]/', '', $line));
        $this->update->log = ($this->update->log ?? '').$line."\n";
        if ($this->logFile) {
            @file_put_contents($this->logFile, $line."\n", FILE_APPEND);
        }
        if ($this->echo) {
            ($this->echo)($line);
        }
        $this->flush();
    }

    protected function flush(bool $force = false): void
    {
        if (! $force && microtime(true) - $this->flushedAt < 1.0) {
            return;
        }
        $this->flushedAt = microtime(true);
        try {
            if ($this->update->exists) {
                $this->update->save();
            }
        } catch (Throwable) {
            // the database may be briefly unavailable; the log file keeps everything
        }
    }

    protected function finish(string $status, ?string $error = null): void
    {
        $this->update->forceFill(['status' => $status, 'error' => $error, 'finished_at' => now()]);
        $this->flush(true);
    }

    /**
     * Load every class the rest of the run needs before composer replaces vendor/pine/commerce under this process
     * (a class loaded afterwards would come from the new version).
     */
    public static function preload(): void
    {
        foreach ([PlatformUpdate::class, ProcessResult::class, Versions::class, Updater::class, ComposerProject::class,
            Environment::class, DatabaseBackup::class, UpdateLock::class, File::class, Str::class, RuntimeException::class,
            \Illuminate\Support\Carbon::class, \Composer\Semver\Comparator::class, \Composer\Semver\Semver::class,
            \Composer\Semver\VersionParser::class, \Composer\Semver\Constraint\Constraint::class,
            \Composer\Semver\Constraint\MultiConstraint::class, \Composer\Semver\Constraint\MatchAllConstraint::class,
            \Composer\Semver\Intervals::class, \Composer\Semver\CompilingMatcher::class] as $class) {
            class_exists($class);
        }
    }
}
