<?php

namespace Pine\Commerce\Updater;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The real ProcessRunner: Symfony Process for foreground commands, proc_open + `setsid`/`nohup` for the detached
 * update run started from a web request (there is no queue worker on shared hosting, and a web request must not wait
 * for composer).
 */
class SystemProcessRunner implements ProcessRunner
{
    public function run(array $command, ?string $cwd = null, array $env = [], ?callable $onLine = null, ?int $timeout = 600): ProcessResult
    {
        $process = new Process($command, $cwd, $env + $this->inheritedEnv(), null, $timeout ? (float) $timeout : null);
        $output = '';
        $buffer = '';
        $process->run(function ($type, $chunk) use (&$output, &$buffer, $onLine) {
            $output .= $chunk;
            if ($onLine === null) {
                return;
            }
            $buffer .= str_replace("\r", "\n", $chunk);
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                if (trim($line) !== '') {
                    $onLine(rtrim($line));
                }
            }
        });
        if ($onLine !== null && trim($buffer) !== '') {
            $onLine(rtrim($buffer));
        }

        return new ProcessResult((int) $process->getExitCode(), $output);
    }

    public function launch(array $command, string $cwd, array $env, string $logFile): ?int
    {
        if (! function_exists('proc_open')) {
            throw new RuntimeException('proc_open() is disabled in this PHP configuration (disable_functions).');
        }
        $line = implode(' ', array_map('escapeshellarg', $command));
        $detach = '';
        foreach (['setsid', 'nohup'] as $tool) {
            if ($path = Environment::which($tool)) {
                $detach .= escapeshellarg($path).' ';
            }
        }
        $shell = $detach.$line.' >> '.escapeshellarg($logFile).' 2>&1 < /dev/null & echo $!';
        $pipes = [];
        $process = @proc_open(['/bin/sh', '-c', $shell], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, $cwd, $env + $this->inheritedEnv());
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the background process (proc_open failed).');
        }
        $pid = trim((string) stream_get_contents($pipes[1]));
        $error = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            throw new RuntimeException('The background process did not start: '.($error ?: 'exit code '.$status));
        }

        return ctype_digit($pid) ? (int) $pid : null;
    }

    /** @return array<string,string> */
    protected function inheritedEnv(): array
    {
        $env = getenv();

        return is_array($env) ? array_filter($env, 'is_string') : [];
    }
}
