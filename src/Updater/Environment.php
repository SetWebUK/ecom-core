<?php

namespace Pine\Commerce\Updater;

/**
 * Where the updater's programs are and the environment they run with. A process started from a web request
 * (LiteSpeed/PHP-FPM) often has no HOME, a minimal PATH and PHP_BINARY pointing at the SAPI binary (lsphp, php-fpm),
 * so everything is resolved explicitly:
 *
 *  - PHP CLI:   config commerce.updater.php_binary → PHP_BINARY (CLI only) → PHP_BINDIR/php → `php` in PATH
 *  - composer:  config commerce.updater.composer_binary → `composer` / `composer.phar` in PATH, the project, ~/bin
 *  - HOME:      config commerce.updater.home → $HOME → the account's home directory (posix) → the project's parent
 *  - COMPOSER_HOME: config commerce.updater.composer_home → $COMPOSER_HOME → ~/.config/composer (if present) → ~/.composer
 */
class Environment
{
    public function __construct(protected ?string $projectPath = null) {}

    public function projectPath(): string
    {
        return rtrim($this->projectPath ?? Updater::projectPath(), '/');
    }

    public function php(): ?string
    {
        $configured = trim((string) config('commerce.updater.php_binary'));
        if ($configured !== '') {
            return $configured;
        }
        if (PHP_SAPI === 'cli' && PHP_BINARY !== '' && static::isCliPhp(PHP_BINARY)) {
            return PHP_BINARY;
        }
        foreach ([PHP_BINDIR.'/php', static::which('php'), '/usr/local/bin/php', '/usr/bin/php'] as $candidate) {
            if ($candidate && is_file($candidate) && is_executable($candidate) && static::isCliPhp($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** Not a SAPI binary (lsphp, php-fpm, php-cgi). */
    protected static function isCliPhp(string $path): bool
    {
        return ! preg_match('/(lsphp|php-fpm|php-cgi|phpdbg)[\d.]*$/', basename($path));
    }

    public function composer(): ?string
    {
        $configured = trim((string) config('commerce.updater.composer_binary'));
        if ($configured !== '') {
            return $configured;
        }
        foreach (['composer', 'composer.phar'] as $name) {
            if ($found = static::which($name, [$this->home().'/bin', $this->home().'/.local/bin', $this->projectPath()])) {
                return $found;
            }
        }

        return null;
    }

    /**
     * The command prefix that runs composer: [php, composer.phar] for a PHP file (a phar or the usual
     * `#!/usr/bin/env php` script – so no `env php` lookup is needed), else the binary itself.
     *
     * @return list<string>|null
     */
    public function composerCommand(): ?array
    {
        $composer = $this->composer();
        if ($composer === null) {
            return null;
        }
        $head = is_file($composer) && is_readable($composer) ? (string) @file_get_contents($composer, false, null, 0, 200) : '';
        $isPhp = str_ends_with($composer, '.phar') || str_starts_with($head, '<?php')
            || (str_starts_with($head, '#!') && str_contains(strtok($head, "\n"), 'php'));
        if ($isPhp && ($php = $this->php())) {
            return [$php, $composer];
        }

        return [$composer];
    }

    public function mysqldump(): ?string
    {
        $configured = trim((string) config('commerce.updater.mysqldump_binary'));

        // MariaDB 11 warns "Deprecated program name" for mysqldump: use its own name when it is there
        return $configured !== '' ? $configured : (static::which('mariadb-dump') ?? static::which('mysqldump'));
    }

    public function git(): string
    {
        $configured = trim((string) config('commerce.updater.git_binary'));

        return $configured !== '' ? $configured : (static::which('git') ?? 'git');
    }

    public function home(): string
    {
        $configured = trim((string) config('commerce.updater.home'));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
        if ((! is_string($home) || $home === '' || ! is_dir($home)) && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $home = (string) (posix_getpwuid(posix_geteuid())['dir'] ?? '');
        }
        if (! is_string($home) || $home === '' || ! is_dir($home)) {
            $home = dirname($this->projectPath());
        }

        return rtrim($home, '/');
    }

    public function composerHome(): string
    {
        $configured = trim((string) config('commerce.updater.composer_home'));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
        $env = getenv('COMPOSER_HOME');
        if (is_string($env) && $env !== '') {
            return rtrim($env, '/');
        }
        $xdg = $this->home().'/.config/composer';

        return is_dir($xdg) ? $xdg : $this->home().'/.composer';
    }

    /**
     * Environment for every updater process (merged over the current one).
     *
     * @return array<string,string>
     */
    public function variables(): array
    {
        $path = (string) (getenv('PATH') ?: '');
        $dirs = array_filter(array_unique(array_merge(
            ($php = $this->php()) ? [dirname($php)] : [],
            explode(':', $path),
            ['/usr/local/bin', '/usr/bin', '/bin'],
        )));

        return [
            'HOME' => $this->home(),
            'COMPOSER_HOME' => $this->composerHome(),
            'PATH' => implode(':', $dirs),
            'COMPOSER_NO_INTERACTION' => '1',
            'GIT_TERMINAL_PROMPT' => '0',
        ];
    }

    /** Find an executable in PATH (plus $extra directories). */
    public static function which(string $name, array $extra = []): ?string
    {
        $dirs = array_merge(explode(':', (string) (getenv('PATH') ?: '')), ['/usr/local/bin', '/usr/bin', '/bin'], $extra);
        foreach (array_unique(array_filter($dirs)) as $dir) {
            $file = rtrim($dir, '/').'/'.$name;
            if (@is_file($file) && @is_executable($file)) {
                return $file;
            }
        }

        return null;
    }
}
