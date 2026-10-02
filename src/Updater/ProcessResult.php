<?php

namespace Pine\Commerce\Updater;

/** Exit code + combined output of one ProcessRunner::run(). */
final class ProcessResult
{
    public function __construct(public readonly int $exitCode, public readonly string $output = '') {}

    public function ok(): bool
    {
        return $this->exitCode === 0;
    }

    /** The last non-empty output line (for error messages). */
    public function lastLine(): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $this->output)), fn ($l) => $l !== ''));

        return $lines ? (string) end($lines) : '';
    }
}
