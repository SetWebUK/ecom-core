<?php

namespace Pine\Commerce\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Pine\Commerce\Commerce;
use Pine\Commerce\Updater\Skeleton\SkeletonBaseline;

/**
 * Scaffolds a new client project (a Laravel app that requires pine/commerce) from stubs/client-skeleton.
 *
 *   php artisan commerce:new-client /var/www/acme/app --name="Acme Tools" --repo=git@github.com:SetWebUK/ecom-core.git
 *   php artisan commerce:new-client ../acme --name="Acme" --path=../commerce       (local development checkout)
 *
 * Composer wiring of pine/commerce in the new project:
 *   --repo=<git url>   VCS repository, "pine/commerce": "^1.0" (production; repository access: docs/PLAYBOOK.md part 1.6)
 *   --path=<dir>       path repository (symlinked into vendor/), "pine/commerce": "*@dev" (local development)
 *   neither            path repository to the package this command runs from
 * --vcs / --package are the older names of --repo / --path.
 *
 * Placeholders in the skeleton ({{CLIENT_NAME}} (+ _JSON / _ENV escaped variants), {{CLIENT_SLUG}}, {{COMMERCE_REPOSITORY}},
 * {{COMMERCE_CONSTRAINT}})
 * are filled in. Nothing is installed: the playbook (docs/PLAYBOOK.md part 2) continues with composer install.
 */
class NewClientCommand extends Command
{
    protected $signature = 'commerce:new-client
        {path : Directory of the new client project (created; must be empty)}
        {--name= : Client / store name (default: from the directory name)}
        {--slug= : Short client slug used for the theme and composer name (default: from the name)}
        {--repo= : Git URL of the pine/commerce repository – composer "vcs" repository, requires ^1.0 (production)}
        {--path= : Local checkout of pine/commerce – composer "path" repository, symlinked (development; default: this package)}
        {--constraint= : Version constraint for pine/commerce (default: ^1.0 with --repo, *@dev with a path)}
        {--vcs= : Alias of --repo}
        {--package= : Alias of --path}
        {--force : Write into a non-empty directory (existing files are overwritten)}';

    protected $description = 'Create a new client project from the pine/commerce client skeleton';

    public function handle(Filesystem $files): int
    {
        $skeleton = static::skeletonPath();
        $target = $this->absolute((string) $this->argument('path'));

        if (str_starts_with($target.'/', rtrim(base_path(), '/').'/') && ! str_starts_with($target.'/', rtrim(storage_path(), '/').'/')) {
            $this->error("Refusing to scaffold inside this application ({$target}). Pick a directory outside ".base_path().'.');

            return self::FAILURE;
        }
        if (is_file($target) || (is_dir($target) && (new \FilesystemIterator($target))->valid() && ! $this->option('force'))) {
            $this->error("{$target} exists and is not empty (use --force to write into it anyway).");

            return self::FAILURE;
        }

        $name = trim((string) $this->option('name')) ?: Str::headline(basename($target) === 'public_html' ? basename(dirname($target)) : basename($target));
        $slug = Str::slug((string) ($this->option('slug') ?: $name));
        if ($slug === '') {
            $this->error('Could not derive a slug; pass --slug=.');

            return self::FAILURE;
        }

        $vcs = trim((string) ($this->option('repo') ?: $this->option('vcs')));
        $path = trim((string) ($this->option('path') ?: $this->option('package')));
        if ($vcs !== '' && $path !== '') {
            $this->error('Use either --repo (VCS repository) or --path (path repository), not both.');

            return self::FAILURE;
        }
        if ($vcs !== '') {
            $repository = ['type' => 'vcs', 'url' => $vcs];
            $constraint = '^1.0';
        } else {
            $package = $path !== '' ? $this->absolute($path) : dirname(__DIR__, 2);
            if (! is_file($package.'/composer.json')) {
                $this->error("No pine/commerce package at {$package}.");

                return self::FAILURE;
            }
            $repository = ['type' => 'path', 'url' => realpath($package) ?: $package, 'options' => ['symlink' => true, 'versions' => ['pine/commerce' => '1.0.x-dev']]];
            $constraint = '*@dev';
        }
        if ($custom = trim((string) $this->option('constraint'))) {
            if (! preg_match('/^[\w.*@^~<>=!|, -]+$/', $custom)) {
                $this->error("Not a composer version constraint: {$custom}");

                return self::FAILURE;
            }
            $constraint = $custom;
        }

        $replace = [
            '{{CLIENT_NAME}}' => $name,
            '{{CLIENT_NAME_JSON}}' => substr(json_encode($name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1),
            '{{CLIENT_NAME_ENV}}' => addcslashes($name, '"\\$'),
            '{{CLIENT_SLUG}}' => $slug,
            '{{COMMERCE_REPOSITORY}}' => json_encode($repository, JSON_UNESCAPED_SLASHES),
            '{{COMMERCE_CONSTRAINT}}' => $constraint,
        ];

        $files->ensureDirectoryExists($target);
        $count = 0;
        $hashes = [];
        foreach ($files->allFiles($skeleton, true) as $file) {
            $relative = $file->getRelativePathname();
            // stored under another name so they do not act on this repository (a .gitignore would hide skeleton files)
            $relative = static::RENAMES[$relative] ?? $relative;
            $destination = $target.'/'.$relative;
            $files->ensureDirectoryExists(dirname($destination));
            $contents = $file->getContents();
            if (! str_contains($contents, "\0")) {
                $contents = strtr($contents, $replace);
            }
            $files->put($destination, $contents);
            $hashes[$relative] = SkeletonBaseline::hash($contents);
            if ($relative === 'artisan') {
                @chmod($destination, 0755);
            }
            $count++;
        }
        // skeleton baseline (Admin › Updates › skeleton files): which skeleton release these files are, and their hashes
        SkeletonBaseline::write([
            'repository' => (string) config('commerce.updater.skeleton_repository', 'https://github.com/SetWebUK/ecom-skeleton.git'),
            'ref' => 'v'.Commerce::VERSION,
            'name' => $name,
            'slug' => $slug,
            'updates' => true,
            'created_by' => 'commerce:new-client',
            'created_at' => now()->toDateString(),
            'files' => $hashes,
        ], $target);
        $count++;

        // .env from the documented example (never overwrite an existing one)
        if (! is_file($target.'/.env') && is_file($target.'/.env.example')) {
            $files->copy($target.'/.env.example', $target.'/.env');
        }

        $this->components->info("Client project \"{$name}\" ({$slug}) created in {$target} ({$count} files).");
        $this->line($repository['type'] === 'vcs'
            ? "  pine/commerce {$constraint} from {$repository['url']} (VCS – the server needs read access: deploy key or auth.json, PLAYBOOK part 1)"
            : "  pine/commerce {$constraint} from the local checkout {$repository['url']} (path repository – switch to --repo for production)");
        $this->line('<options=bold>Next steps</> (full playbook: pine/commerce docs/PLAYBOOK.md, part 2)');
        foreach ([
            "cd {$target} && composer install",
            'Edit .env (APP_URL, DB_*, MAIL_*, WP_DB_*), then: php artisan key:generate',
            "php artisan commerce:theme:make {$slug} --parent=default   (then COMMERCE_THEME={$slug} in .env)",
            'php artisan commerce:install --admin-email=you@agency.test --store-name='.escapeshellarg($name),
            'Import the old site: WP_DB_* + WP_PATH in .env, then php artisan commerce:import-wordpress (PLAYBOOK.md part 2)',
            'php artisan commerce:doctor',
        ] as $i => $step) {
            $this->line('  '.($i + 1).'. '.$step);
        }

        return self::SUCCESS;
    }

    /** Skeleton files stored under another name. */
    public const RENAMES = ['gitignore.stub' => '.gitignore'];

    public static function skeletonPath(): string
    {
        return dirname(__DIR__, 2).'/stubs/client-skeleton';
    }

    protected function absolute(string $path): string
    {
        if (! str_starts_with($path, '/')) {
            $path = getcwd().'/'.$path;
        }
        // normalise ./ and ../ without requiring the path to exist
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            $part === '..' ? array_pop($parts) : $parts[] = $part;
        }

        return '/'.implode('/', $parts);
    }
}
