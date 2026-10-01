<?php

namespace Pine\Commerce\Tests;

use PHPUnit\Framework\TestCase as PlainTestCase;
use Pine\Commerce\Commerce;

/**
 * pine/commerce is its own repository (docs/PLAYBOOK.md part 1): it must carry everything a release needs and never
 * point at files of the application it happens to be developed in.
 */
class PackageRepositoryTest extends PlainTestCase
{
    private function root(): string
    {
        return dirname(__DIR__);
    }

    public function test_repository_files_exist(): void
    {
        foreach (['composer.json', 'README.md', 'CHANGELOG.md', 'LICENSE', 'VERSION', '.gitattributes', '.gitignore', 'phpunit.xml.dist',
            'docs/PLAYBOOK.md', 'docs/ARCHITECTURE.md', 'docs/UPGRADING.md'] as $file) {
            $this->assertFileExists($this->root().'/'.$file);
        }
    }

    public function test_version_file_constant_and_changelog_agree(): void
    {
        $version = trim((string) file_get_contents($this->root().'/VERSION'));
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $version, 'VERSION holds a SemVer number');
        $this->assertSame($version, Commerce::VERSION, 'VERSION file = Pine\Commerce\Commerce::VERSION');
        $this->assertStringContainsString("## [{$version}]", (string) file_get_contents($this->root().'/CHANGELOG.md'), 'CHANGELOG has an entry for VERSION');
    }

    public function test_composer_json_is_a_standalone_library(): void
    {
        $composer = json_decode((string) file_get_contents($this->root().'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('pine/commerce', $composer['name']);
        $this->assertArrayNotHasKey('version', $composer, 'versions come from git tags (vX.Y.Z), never from composer.json');
        $this->assertArrayNotHasKey('repositories', $composer);
        $this->assertArrayHasKey('laravel/framework', $composer['require']);
        $this->assertArrayHasKey('stripe/stripe-php', $composer['require']);
        $this->assertArrayHasKey('orchestra/testbench', $composer['require-dev']);
        $this->assertSame('src/', $composer['autoload']['psr-4']['Pine\\Commerce\\']);
        $this->assertSame('tests/', $composer['autoload-dev']['psr-4']['Pine\\Commerce\\Tests\\']);
        $this->assertArrayHasKey('test', $composer['scripts']);
    }

    public function test_dist_archives_leave_out_tests_and_docs(): void
    {
        $attributes = (string) file_get_contents($this->root().'/.gitattributes');
        foreach (['/tests', '/docs', '/phpunit.xml.dist'] as $path) {
            $this->assertMatchesRegularExpression('#^'.preg_quote($path, '#').'\s+export-ignore#m', $attributes);
        }
    }

    /** No path of the host application (monorepo layout, WordPress, server paths) in code, config, stubs or tests. */
    public function test_nothing_points_outside_the_package(): void
    {
        $root = $this->root();
        $hits = [];
        foreach (['src', 'config', 'routes', 'resources', 'database', 'stubs', 'tests'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $path = $file->getPathname();
                if ($path === __FILE__ || ! preg_match('/\.(php|json|js|css|md|stub|xml)$/', $path) || str_contains($path, '/tinymce')) {
                    continue;
                }
                $text = (string) file_get_contents($path);
                foreach (['packages/commerce', 'dirname(__DIR__, 4)', 'dirname(__DIR__, 5)', 'wp-content/themes/'] as $needle) {
                    if (str_contains($text, $needle)) {
                        $hits[] = substr($path, strlen($root) + 1).': '.$needle;
                    }
                }
            }
        }
        $this->assertSame([], $hits, "References outside the package:\n".implode("\n", $hits));
    }
}
