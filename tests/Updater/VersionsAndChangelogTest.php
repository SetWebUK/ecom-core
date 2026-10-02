<?php

namespace Pine\Commerce\Tests\Updater;

use Pine\Commerce\Tests\Concerns\FakesUpdaterProject;
use Pine\Commerce\Tests\TestCase;
use Pine\Commerce\Updater\Changelog;
use Pine\Commerce\Updater\ReleaseSource;
use Pine\Commerce\Updater\Versions;

/** Admin › Updates: semver + composer-constraint logic and CHANGELOG extraction. */
class VersionsAndChangelogTest extends TestCase
{
    use FakesUpdaterProject;

    public function test_tags_and_comparison(): void
    {
        $this->assertSame('1.2.3', Versions::fromTag('v1.2.3'));
        $this->assertSame('1.2.3', Versions::fromTag('1.2.3'));
        $this->assertNull(Versions::fromTag('v1.3.0-beta.1'), 'pre-releases are not offered');
        $this->assertNull(Versions::fromTag('main'));
        $this->assertTrue(Versions::greater('1.10.0', '1.9.9'));
        $this->assertSame(-1, Versions::compare('1.2.1', '1.3.0'));
        $this->assertTrue(Versions::satisfies('1.3.0', '^1.2.1'));
        $this->assertFalse(Versions::satisfies('2.0.0', '^1.2.1'));
        $this->assertFalse(Versions::satisfies('1.3.0', '~1.2.1'));
        $this->assertFalse(Versions::satisfies('1.3.0', 'not a constraint'));
        $this->assertFalse(Versions::validConstraint(''));
    }

    public function test_newer_minor_within_the_constraint_is_installable_and_a_new_major_needs_a_developer(): void
    {
        $tags = ['v1.0.0', 'v1.2.1', 'v1.2.2', 'v1.3.0', 'v2.0.0', 'v1.4.0-beta.1', 'nightly'];
        $result = Versions::classify('1.2.1', '^1.2.1', $tags);
        $this->assertSame('1.3.0', $result['latest']);
        $this->assertSame('v1.3.0', $result['latest_tag']);
        $this->assertSame(['2.0.0', '1.3.0', '1.2.2'], $result['newer']);
        $this->assertSame('2.0.0', $result['blocked']['version']);
        $this->assertStringContainsString('major', $result['blocked']['reason']);

        // a tighter constraint: only patches are installable, the minor needs a developer
        $result = Versions::classify('1.2.1', '~1.2.1', ['v1.2.2', 'v1.3.0']);
        $this->assertSame('1.2.2', $result['latest']);
        $this->assertSame('1.3.0', $result['blocked']['version']);
        $this->assertStringContainsString('constraint', $result['blocked']['reason']);

        // up to date
        $result = Versions::classify('1.3.0', '^1.2', ['v1.2.1', 'v1.3.0']);
        $this->assertNull($result['latest']);
        $this->assertNull($result['blocked']);
    }

    public function test_changelog_sections_between_the_installed_and_the_latest_version(): void
    {
        $sections = Changelog::between(static::changelogFixture(), '1.2.1', '1.3.0');
        $this->assertSame(['1.3.0', '1.2.2'], array_column($sections, 'version'));
        $this->assertSame('2026-10-02', $sections[0]['date']);
        $this->assertTrue($sections[0]['actions_required']);
        $this->assertStringContainsString('migrate --force', $sections[0]['client_actions']);
        $this->assertFalse($sections[1]['actions_required'], '"None." is not an action');
        $this->assertSame('- None.', $sections[1]['client_actions']);

        $all = Changelog::sections(static::changelogFixture());
        $this->assertSame(['2.0.0', '1.3.0', '1.2.2', '1.2.1'], array_column($all, 'version'), '[Unreleased] is skipped');
        $this->assertStringNotContainsString('releases/tag', end($all)['body'], 'link reference definitions are dropped');
        $this->assertNull($all[0]['client_actions']);
    }

    public function test_github_urls(): void
    {
        foreach (['https://github.com/SetWebUK/ecom-core.git', 'https://github.com/SetWebUK/ecom-core', 'git@github.com:SetWebUK/ecom-core.git',
            'ssh://git@github.com/SetWebUK/ecom-core.git'] as $url) {
            $this->assertSame('SetWebUK/ecom-core', ReleaseSource::github($url), $url);
        }
        $this->assertNull(ReleaseSource::github('https://git.example.test/acme/core.git'));
        $this->assertNull(ReleaseSource::github('/home/you/ecom-core'));
    }

    public function test_tags_fall_back_to_git_ls_remote(): void
    {
        $this->setUpUpdaterProject();
        try {
            \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['message' => 'rate limited'], 403)]);
            $this->runner->on('ls-remote', fn () => "abc\trefs/tags/v1.2.1\ndef\trefs/tags/v1.3.0\n");
            $tags = app(ReleaseSource::class)->tags('https://github.com/SetWebUK/ecom-core.git');
            $this->assertSame(['v1.2.1', 'v1.3.0'], $tags['tags']);
            $this->assertSame('git ls-remote', $tags['via']);
            $this->assertStringContainsString('403', $tags['notes'][0]);
            $this->assertNotNull($this->runner->indexOf('ls-remote --tags --refs https://github.com/SetWebUK/ecom-core.git'));
        } finally {
            $this->tearDownUpdaterProject();
        }
    }
}
