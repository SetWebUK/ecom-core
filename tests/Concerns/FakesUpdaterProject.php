<?php

namespace Pine\Commerce\Tests\Concerns;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pine\Commerce\Tests\Fixtures\FakeProcessRunner;
use Pine\Commerce\Updater\ProcessResult;
use Pine\Commerce\Updater\ProcessRunner;

/**
 * A throw-away client project for the updater tests (composer.json, composer.lock, vendor/composer/installed.json,
 * artisan) in storage/framework/testing, a fake ProcessRunner (nothing is executed) and faked GitHub answers.
 */
trait FakesUpdaterProject
{
    protected string $project;

    protected FakeProcessRunner $runner;

    protected function setUpUpdaterProject(string $installed = 'v1.2.1', string $constraint = '^1.2'): void
    {
        $this->project = storage_path('framework/testing/updater-'.uniqid());
        File::ensureDirectoryExists($this->project.'/vendor/composer');
        File::put($this->project.'/composer.json', json_encode([
            'name' => 'pine-clients/acme',
            'repositories' => [['type' => 'vcs', 'url' => 'https://github.com/SetWebUK/ecom-core.git']],
            'require' => ['php' => '^8.3', 'pine/commerce' => $constraint],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        File::put($this->project.'/composer.lock', '{"lock": "'.$installed.'"}');
        File::put($this->project.'/artisan', "<?php\n");
        $this->setInstalled($installed);

        config([
            'commerce.updater.project_path' => $this->project,
            'commerce.updater.path' => $this->project.'/storage/updater',
            'commerce.updater.php_binary' => '/usr/bin/php-test',
            'commerce.updater.composer_binary' => '/usr/bin/composer-test',
            'commerce.updater.min_free_mb' => 1,
            'commerce.updater.backup' => false,
            'commerce.features.updater' => true,
        ]);

        $this->runner = new FakeProcessRunner;
        $this->runner->on('commerce:doctor', fn () => json_encode(['counts' => ['fail' => 0], 'checks' => [['title' => 'Database', 'status' => 'pass']]]));
        // composer update installs the version it was pinned to (--with=pine/commerce:X) and rewrites the lock file
        $this->runner->on('composer-test update', function (array $command) {
            foreach ($command as $part) {
                if (str_starts_with($part, '--with=pine/commerce:')) {
                    $this->setInstalled('v'.substr($part, strlen('--with=pine/commerce:')));
                    File::put($this->project.'/composer.lock', '{"lock": "updated"}');
                }
            }

            return new ProcessResult(0, "Updating pine/commerce\nGenerating autoload files");
        });
        // composer install puts back what composer.lock says
        $this->runner->on('composer-test install', function () {
            $lock = json_decode(File::get($this->project.'/composer.lock'), true);
            $this->setInstalled((string) ($lock['lock'] ?? 'v1.2.1'));

            return new ProcessResult(0, 'Installing dependencies from lock file');
        });
        $this->app->instance(ProcessRunner::class, $this->runner);
    }

    protected function tearDownUpdaterProject(): void
    {
        if (isset($this->project)) {
            File::deleteDirectory($this->project);
        }
    }

    protected function setInstalled(string $version): void
    {
        File::put($this->project.'/vendor/composer/installed.json', json_encode(['packages' => [
            ['name' => 'pine/commerce', 'version' => $version],
        ], 'dev' => true]));
    }

    /** GitHub API tags + raw CHANGELOG.md of the package repository. */
    protected function fakeGithub(array $tags = ['v1.3.0', 'v1.2.1', 'v2.0.0'], ?string $changelog = null): void
    {
        $changelog ??= static::changelogFixture();
        Http::fake([
            'api.github.com/repos/SetWebUK/ecom-core/tags*' => Http::response(array_map(fn ($t) => ['name' => $t], $tags)),
            'api.github.com/repos/SetWebUK/ecom-skeleton/tags*' => Http::response([['name' => 'v1.3.0']]),
            'raw.githubusercontent.com/SetWebUK/ecom-core/*' => Http::response($changelog),
            '*' => Http::response('not found', 404),
        ]);
    }

    protected static function changelogFixture(): string
    {
        return <<<'MD'
# Changelog

## [Unreleased]

## [2.0.0] - 2026-12-01

### Changed
- Breaking things.

## [1.3.0] - 2026-10-02

### Added
- Admin › Updates.

### Client actions required
- Run `php artisan migrate --force` <script>alert(1)</script>.

## [1.2.2] - 2026-10-01

### Fixed
- A fix.

### Client actions required
- None.

## [1.2.1] - 2026-09-30

### Changed
- Old news.

[1.2.1]: https://github.com/SetWebUK/ecom-core/releases/tag/v1.2.1
MD;
    }
}
