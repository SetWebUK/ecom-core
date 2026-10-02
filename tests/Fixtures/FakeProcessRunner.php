<?php

namespace Pine\Commerce\Tests\Fixtures;

use Pine\Commerce\Updater\ProcessResult;
use Pine\Commerce\Updater\ProcessRunner;

/**
 * Test double of the updater's ProcessRunner: records every command and answers from handlers matched by a
 * substring of the command line – nothing is executed (no composer, git, mysqldump or artisan).
 *
 *   $runner->on('composer update', fn (array $cmd) => new ProcessResult(1, 'boom'));
 */
class FakeProcessRunner implements ProcessRunner
{
    /** @var list<string> */
    public array $commands = [];

    /** @var list<array{command:list<string>, env:array}> */
    public array $launched = [];

    /** @var array<string, callable> */
    protected array $handlers = [];

    public ?\Throwable $launchError = null;

    public function on(string $needle, callable $handler): static
    {
        $this->handlers[$needle] = $handler;

        return $this;
    }

    public function run(array $command, ?string $cwd = null, array $env = [], ?callable $onLine = null, ?int $timeout = 600): ProcessResult
    {
        $line = implode(' ', $command);
        $this->commands[] = $line;
        foreach ($this->handlers as $needle => $handler) {
            if (str_contains($line, $needle)) {
                $result = $handler($command, $env);
                $result = $result instanceof ProcessResult ? $result : new ProcessResult(0, (string) $result);
                if ($onLine) {
                    foreach (array_filter(explode("\n", $result->output)) as $out) {
                        $onLine($out);
                    }
                }

                return $result;
            }
        }

        return new ProcessResult(0, '');
    }

    public function launch(array $command, string $cwd, array $env, string $logFile): ?int
    {
        if ($this->launchError) {
            throw $this->launchError;
        }
        $this->launched[] = ['command' => $command, 'env' => $env];

        return 4242;
    }

    /** Index of the first recorded command containing $needle (or null). */
    public function indexOf(string $needle): ?int
    {
        foreach ($this->commands as $i => $line) {
            if (str_contains($line, $needle)) {
                return $i;
            }
        }

        return null;
    }
}
