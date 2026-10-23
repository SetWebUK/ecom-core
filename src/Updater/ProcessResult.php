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

    /** The last non-empty output line. */
    public function lastLine(): string
    {
        $lines = $this->lines();

        return $lines ? (string) end($lines) : '';
    }

    /**
     * The line that explains a failure: the message under a Symfony console "In File.php line N:" header (composer and
     * artisan print their usage after it), else the last line that is not a command synopsis.
     */
    public function errorLine(): string
    {
        $lines = $this->lines();
        $message = '';
        foreach ($lines as $i => $line) {
            if (preg_match('/^In \S+ line \d+:$/', $line) && isset($lines[$i + 1])) {
                $message = $lines[$i + 1];
            }
        }
        if ($message !== '') {
            return $message;
        }
        $lines = array_filter($lines, fn ($l) => ! preg_match('/^[\w:-]+ \[-/', $l));

        return $lines ? (string) end($lines) : '';
    }

    /** @return list<string> */
    protected function lines(): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $this->output)), fn ($l) => $l !== ''));
    }
}
