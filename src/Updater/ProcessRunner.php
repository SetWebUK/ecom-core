<?php

namespace Pine\Commerce\Updater;

/**
 * Runs the external programs of the updater (composer, git, mysqldump, php artisan …). Bound in the container to
 * SystemProcessRunner; tests bind a fake so nothing real is executed.
 *
 * Commands are always argument lists built by the updater itself (never a shell string, never browser input).
 */
interface ProcessRunner
{
    /**
     * Run a command and wait for it.
     *
     * @param  list<string>  $command
     * @param  array<string,string>  $env  added to the current environment
     * @param  (callable(string):void)|null  $onLine  receives each output line (stdout + stderr) as it arrives
     */
    public function run(array $command, ?string $cwd = null, array $env = [], ?callable $onLine = null, ?int $timeout = 600): ProcessResult;

    /**
     * Start a command detached from the current request (new session, no controlling terminal, output appended to
     * $logFile) and return at once. Returns the pid when known.
     *
     * @param  list<string>  $command
     * @param  array<string,string>  $env
     *
     * @throws \RuntimeException when it cannot be started (e.g. proc_open disabled)
     */
    public function launch(array $command, string $cwd, array $env, string $logFile): ?int;
}
