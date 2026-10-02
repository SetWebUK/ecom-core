<?php

namespace Pine\Commerce\Updater;

use RuntimeException;

/**
 * Database backup before an update: mysqldump (MySQL / MariaDB, credentials in a temporary 0600 option file – never
 * on the command line) compressed with gzip, or a copy of the SQLite file. Written to commerce.updater.backup_path
 * (never under public/); only the newest commerce.updater.keep_backups files are kept.
 */
class DatabaseBackup
{
    public function __construct(protected ProcessRunner $runner, protected Environment $environment) {}

    /** @return array{path:?string, note:string} path null = nothing to back up (in-memory database) */
    public function create(string $label, ?callable $log = null): array
    {
        $directory = Updater::backupPath();
        Updater::assertPrivate($directory);
        Updater::ensureDirectory($directory);
        $connection = (string) config('database.default');
        $config = (array) config("database.connections.{$connection}");
        $driver = (string) ($config['driver'] ?? '');
        $stamp = now()->format('Ymd-His');
        $label = preg_replace('/[^\w.-]/', '_', $label);

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $path = "{$directory}/db-{$stamp}-{$label}.sql";
            $this->mysqldump($config, $path, $log);
            $path = $this->gzip($path);
        } elseif ($driver === 'sqlite') {
            $database = (string) ($config['database'] ?? '');
            if ($database === '' || $database === ':memory:' || ! is_file($database)) {
                return ['path' => null, 'note' => 'In-memory SQLite database: nothing to back up.'];
            }
            $path = "{$directory}/db-{$stamp}-{$label}.sqlite";
            if (! @copy($database, $path)) {
                throw new RuntimeException("Could not copy {$database} to {$path}.");
            }
        } else {
            throw new RuntimeException("No backup method for the {$driver} driver – back the database up yourself, then set commerce.updater.backup to false.");
        }
        @chmod($path, 0600);
        $this->prune($directory);

        return ['path' => $path, 'note' => 'Backup: '.$path.' ('.number_format(filesize($path) / 1048576, 1).' MB)'];
    }

    protected function mysqldump(array $config, string $path, ?callable $log): void
    {
        $binary = $this->environment->mysqldump();
        if (! $binary) {
            throw new RuntimeException('mysqldump was not found: set commerce.updater.mysqldump_binary.');
        }
        $options = tempnam(Updater::ensureDirectory(Updater::workPath()), 'my');
        @chmod($options, 0600);
        $lines = ['[client]', 'user="'.addcslashes((string) ($config['username'] ?? ''), '"\\').'"',
            'password="'.addcslashes((string) ($config['password'] ?? ''), '"\\').'"'];
        if (! empty($config['unix_socket'])) {
            $lines[] = 'socket="'.addcslashes((string) $config['unix_socket'], '"\\').'"';
        } else {
            $lines[] = 'host="'.addcslashes((string) ($config['host'] ?? '127.0.0.1'), '"\\').'"';
            $lines[] = 'port='.(int) ($config['port'] ?? 3306);
        }
        file_put_contents($options, implode("\n", $lines)."\n");
        try {
            $result = $this->runner->run([$binary, '--defaults-extra-file='.$options, '--single-transaction', '--quick',
                '--routines', '--no-tablespaces', '--default-character-set=utf8mb4', '--result-file='.$path, (string) ($config['database'] ?? '')],
                null, $this->environment->variables(), $log, (int) config('commerce.updater.timeout', 900));
        } finally {
            @unlink($options);
        }
        if (! $result->ok() || ! is_file($path) || filesize($path) === 0) {
            @unlink($path);
            throw new RuntimeException('mysqldump failed: '.($result->lastLine() ?: 'exit code '.$result->exitCode));
        }
    }

    protected function gzip(string $path): string
    {
        if (! function_exists('gzopen')) {
            return $path;
        }
        $in = fopen($path, 'rb');
        $out = gzopen($path.'.gz', 'wb6');
        while (! feof($in)) {
            gzwrite($out, (string) fread($in, 1048576));
        }
        fclose($in);
        gzclose($out);
        @unlink($path);

        return $path.'.gz';
    }

    /** Keep the newest N backups (commerce.updater.keep_backups, minimum 1). */
    public function prune(?string $directory = null): void
    {
        $directory ??= Updater::backupPath();
        $files = glob($directory.'/db-*') ?: [];
        rsort($files); // names start with the timestamp: newest first
        foreach (array_slice($files, max(1, (int) config('commerce.updater.keep_backups', 5))) as $old) {
            @unlink($old);
        }
    }
}
