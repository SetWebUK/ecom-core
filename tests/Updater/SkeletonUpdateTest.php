<?php

namespace Pine\Commerce\Tests\Updater;

use Illuminate\Support\Facades\File;
use Pine\Commerce\Models\PlatformUpdate;
use Pine\Commerce\Tests\TestCase;
use Pine\Commerce\Updater\ComposerProject;
use Pine\Commerce\Updater\Environment;
use Pine\Commerce\Updater\ReleaseSource;
use Pine\Commerce\Updater\Skeleton\SkeletonBaseline;
use Pine\Commerce\Updater\Skeleton\SkeletonComparer;
use Pine\Commerce\Updater\Skeleton\SkeletonUpdater;
use Pine\Commerce\Updater\SystemProcessRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Admin › Updates › skeleton files: classification of every changed file, applying safe files, the baseline. */
class SkeletonUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();
        if (! static::standalone()) {
            $this->markTestSkipped('Runs in the package checkout only (in-memory SQLite).');
        }
        $this->root = storage_path('framework/testing/skeleton-'.uniqid());
        config(['commerce.updater.project_path' => $this->root.'/project', 'commerce.updater.path' => $this->root.'/work']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    protected function tree(string $dir, array $files): string
    {
        foreach ($files as $path => $content) {
            File::ensureDirectoryExists(dirname($this->root.'/'.$dir.'/'.$path));
            File::put($this->root.'/'.$dir.'/'.$path, $content);
        }

        return $this->root.'/'.$dir;
    }

    /** The scenario: base = v1.2.1 skeleton, target = v1.3.0 skeleton, project = a client made from v1.2.1. */
    protected function scenario(): array
    {
        $base = $this->tree('base', [
            'a.php' => "A\n", 'b.php' => "B\n", 'd.php' => "D\n", 'e.php' => "E\n", 'f.php' => "F\n", 'g.php' => "same\n",
            'h.php' => "H1\n", '.env' => "X=1\n", 'themes/acme/site.css' => "a{}\n", 'composer.lock' => '{}',
            'config/app.php' => "'name' => 'Commerce Skeleton',\n", 'storage/logs/.gitignore' => "*\n",
            '.commerce-skeleton.json' => json_encode(['ref' => 'v1.2.1', 'name' => 'Commerce Skeleton', 'slug' => 'commerce-skeleton']),
        ]);
        $target = $this->tree('target', [
            'a.php' => "A2\n", 'b.php' => "B2\n", 'c.php' => "C\n", 'f.php' => "F2\n", 'g.php' => "same\n", 'h.php' => "H3\n",
            '.env' => "X=2\n", 'themes/acme/site.css' => "b{}\n", 'composer.lock' => '{"x":1}',
            'config/app.php' => "'name' => 'Commerce Skeleton',\n'new' => true,\n", 'storage/logs/.gitignore' => "*\n!.gitignore\n",
            '.commerce-skeleton.json' => json_encode(['ref' => 'v1.3.0', 'name' => 'Commerce Skeleton', 'slug' => 'commerce-skeleton']),
        ]);
        $project = $this->tree('project', [
            'a.php' => "A\n",            // untouched → unchanged (safe)
            'b.php' => "B-mine\n",       // changed here and upstream → modified (developer)
            'd.php' => "D\n",            // removed upstream, untouched → removed (safe)
            'e.php' => "E-mine\n",       // removed upstream, changed here → removed-modified (developer)
            // f.php deleted here, changed upstream → deleted-locally (developer)
            'g.php' => "same\n",
            'h.php' => "H2\n",           // differs from the base, but it is what the skeleton delivered (hash) → unchanged
            '.env' => "X=secret\n",
            'themes/acme/site.css' => "a{}\n",
            'config/app.php' => "'name' => 'Acme Tools',\n", // placeholders mapped: still pristine
            'storage/logs/.gitignore' => "*\n",
        ]);
        SkeletonBaseline::write(['ref' => 'v1.2.1', 'name' => 'Acme Tools', 'slug' => 'acme',
            'files' => ['h.php' => SkeletonBaseline::hash("H2\n")]], $project);

        return [$base, $target, $project];
    }

    public function test_files_are_classified_against_the_baseline_and_the_target(): void
    {
        [$base, $target, $project] = $this->scenario();
        $plan = (new SkeletonComparer)->compare($base, $target, $project, SkeletonBaseline::read($project),
            ['name' => 'Commerce Skeleton', 'slug' => 'commerce-skeleton']);
        $status = array_map(fn ($e) => $e['status'], $plan['files']);

        $this->assertSame('unchanged', $status['a.php']);
        $this->assertSame('modified', $status['b.php']);
        $this->assertSame('added', $status['c.php']);
        $this->assertSame('removed', $status['d.php']);
        $this->assertSame('removed-modified', $status['e.php']);
        $this->assertSame('deleted-locally', $status['f.php']);
        $this->assertArrayNotHasKey('g.php', $status, 'unchanged upstream: not listed');
        $this->assertSame('unchanged', $status['h.php'], 'content the skeleton delivered counts as untouched');
        $this->assertSame('unchanged', $status['config/app.php'], 'skeleton placeholders are mapped to the project name');
        $this->assertSame('unchanged', $status['storage/logs/.gitignore']);
        foreach (['.env', 'themes/acme/site.css', 'composer.lock', '.commerce-skeleton.json'] as $protected) {
            $this->assertSame('protected', $status[$protected], $protected);
            $this->assertFalse($plan['files'][$protected]['safe']);
        }
        $this->assertTrue($plan['files']['a.php']['safe']);
        $this->assertFalse($plan['files']['b.php']['safe']);
        $this->assertSame(['added' => 1, 'removed' => 1], $plan['files']['b.php']['upstream']);
        $this->assertSame(['added' => 1, 'removed' => 1], $plan['files']['b.php']['local']);
        $this->assertStringContainsString("+B2", (string) $plan['files']['b.php']['diff']);
        $this->assertSame(1, $plan['counts']['modified']);
    }

    public function test_only_selected_safe_files_are_applied_with_backups_and_the_baseline_follows(): void
    {
        [$base, $target, $project] = $this->scenario();
        $source = new class($base, $target) extends ReleaseSource
        {
            public function __construct(private string $base, private string $target)
            {
                parent::__construct(new SystemProcessRunner, new Environment);
            }

            public function tags(string $url): array
            {
                return ['tags' => ['v1.2.1', 'v1.3.0', 'v1.4.0'], 'via' => 'test', 'notes' => []];
            }

            public function checkout(string $url, string $ref, string $area): string
            {
                return $ref === 'v1.2.1' ? $this->base : $this->target;
            }
        };
        File::ensureDirectoryExists($project.'/vendor/composer');
        File::put($project.'/vendor/composer/installed.json', json_encode(['packages' => [['name' => 'pine/commerce', 'version' => 'v1.3.0']]]));
        $updater = new SkeletonUpdater($source, new SkeletonComparer, new ComposerProject($project));

        $this->assertSame('v1.3.0', $updater->target()['tag'], 'the newest skeleton not newer than the installed core');

        $plan = $updater->plan(null, 'cli');
        $this->assertSame('planned', $plan->status);
        $this->assertSame('v1.2.1', $plan->from_version);
        $this->assertSame('v1.3.0', $plan->to_version);

        File::put($project.'/a.php', "A-edited-after-the-comparison\n");
        $result = $updater->apply($plan, ['config/app.php', 'c.php', 'd.php', 'a.php', 'b.php', '.env', '../outside.php']);

        $this->assertSame(['config/app.php', 'c.php', 'd.php'], $result['applied']);
        $this->assertSame('changed since the comparison', $result['skipped']['a.php']);
        $this->assertSame('not a safe file of this comparison', $result['skipped']['b.php']);
        $this->assertSame('not a safe file of this comparison', $result['skipped']['.env']);
        $this->assertArrayHasKey('../outside.php', $result['skipped']);
        $this->assertSame("'name' => 'Acme Tools',\n'new' => true,\n", File::get($project.'/config/app.php'), 'placeholders mapped on write');
        $this->assertSame("C\n", File::get($project.'/c.php'));
        $this->assertFileDoesNotExist($project.'/d.php');
        $this->assertSame("B-mine\n", File::get($project.'/b.php'), 'a file changed here is never overwritten');
        $this->assertSame("X=secret\n", File::get($project.'/.env'));
        $backup = $this->root.'/work/skeleton-backups/'.$plan->id;
        $this->assertSame("'name' => 'Acme Tools',\n", File::get($backup.'/config/app.php'));
        $this->assertSame("D\n", File::get($backup.'/d.php'));

        $baseline = SkeletonBaseline::read($project);
        $this->assertSame('v1.2.1', $baseline['ref'], 'files are left to review: the baseline stays');
        $this->assertSame(SkeletonBaseline::hash("C\n"), $baseline['files']['c.php']);
        $this->assertArrayNotHasKey('d.php', $baseline['files']);
        $this->assertFalse($result['baseline_moved']);

        $plan->refresh();
        $this->assertSame('applied', $plan->status);
        $this->assertSame(['config/app.php', 'c.php', 'd.php'], $plan->files);
        $this->assertStringContainsString('baseline kept at v1.2.1', $plan->log);

        // a plan is applied once
        $this->expectException(\RuntimeException::class);
        $updater->apply($plan, ['c.php']);
    }

    public function test_baseline_moves_when_nothing_is_left_and_disabled_projects_are_refused(): void
    {
        $base = $this->tree('base', ['a.php' => "A\n"]);
        $target = $this->tree('target', ['a.php' => "A2\n", 'n.php' => "N\n"]);
        $project = $this->tree('project', ['a.php' => "A\n"]);
        SkeletonBaseline::write(['ref' => 'v1.2.1', 'name' => 'Acme', 'slug' => 'acme'], $project);
        $source = new class($base, $target) extends ReleaseSource
        {
            public function __construct(private string $base, private string $target)
            {
                parent::__construct(new SystemProcessRunner, new Environment);
            }

            public function tags(string $url): array
            {
                return ['tags' => ['v1.2.1', 'v1.3.0'], 'via' => 'test', 'notes' => []];
            }

            public function checkout(string $url, string $ref, string $area): string
            {
                return $ref === 'v1.2.1' ? $this->base : $this->target;
            }
        };
        $updater = new SkeletonUpdater($source, new SkeletonComparer, new ComposerProject($project));
        $plan = $updater->plan();
        $result = $updater->apply($plan, ['a.php', 'n.php']);
        $this->assertTrue($result['baseline_moved']);
        $this->assertSame('v1.3.0', SkeletonBaseline::read($project)['ref']);

        SkeletonBaseline::write(array_replace(SkeletonBaseline::read($project), ['updates' => false, 'note' => 'Not created from the skeleton']), $project);
        try {
            $updater->plan();
            $this->fail('plan() must refuse while skeleton updates are disabled');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Not created from the skeleton', $e->getMessage());
        }
        $this->assertSame(1, PlatformUpdate::query()->where('type', 'skeleton')->count());
    }

    public function test_new_client_projects_record_their_skeleton_baseline(): void
    {
        $target = $this->root.'/new-client';
        $this->artisan('commerce:new-client', ['path' => $target, '--name' => 'Acme Tools', '--slug' => 'acme'])->assertSuccessful();
        $baseline = SkeletonBaseline::read($target);
        $this->assertSame('v'.\Pine\Commerce\Commerce::VERSION, $baseline['ref']);
        $this->assertSame('Acme Tools', $baseline['name']);
        $this->assertSame('acme', $baseline['slug']);
        $this->assertTrue($baseline['updates']);
        $this->assertSame(SkeletonBaseline::hash(File::get($target.'/config/commerce.php')), $baseline['files']['config/commerce.php']);
        $this->assertSame('https://github.com/SetWebUK/ecom-skeleton.git', $baseline['repository']);
    }

    public function test_baseline_command(): void
    {
        File::ensureDirectoryExists($this->root.'/project');
        $this->artisan('commerce:skeleton:baseline', ['ref' => 'v1.3.0', '--name' => 'Acme', '--slug' => 'acme', '--disabled' => true, '--note' => 'Not created from the skeleton'])
            ->assertSuccessful();
        $baseline = SkeletonBaseline::read($this->root.'/project');
        $this->assertSame('v1.3.0', $baseline['ref']);
        $this->assertFalse($baseline['updates']);
        $this->assertSame('Not created from the skeleton', $baseline['note']);
        $this->artisan('commerce:skeleton:baseline', ['ref' => 'v1.3.0; rm -rf /'])->assertFailed();
        $this->artisan('commerce:skeleton:baseline', ['--enabled' => true])->assertSuccessful();
        $this->assertTrue(SkeletonBaseline::read($this->root.'/project')['updates']);
    }
}
