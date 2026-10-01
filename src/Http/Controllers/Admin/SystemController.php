<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Http\Controllers\Controller;
use Illuminate\View\View;
use Pine\Commerce\Commerce;
use Pine\Commerce\Support\Doctor;
use Pine\Commerce\Support\Features;
use Throwable;

/**
 * Admin › Settings › System (administrators only): platform version, active theme, feature switches, the
 * commerce:doctor health checks and the last WordPress import report. Read-only.
 */
class SystemController extends Controller
{
    public function index(): View
    {
        $checks = (new Doctor)->run();

        return view('commerce::admin.system.index', [
            'platform' => [
                'pine/commerce' => Commerce::VERSION,
                'Laravel' => app()->version(),
                'PHP' => PHP_VERSION,
                'Environment' => app()->environment(),
                'Site address' => config('app.url'),
                'Database' => config('database.default').' · '.config('database.connections.'.config('database.default').'.database'),
                'Mail' => config('mail.default'),
                'Queue' => config('queue.default'),
            ],
            'theme' => $this->theme(),
            'features' => $this->features(),
            'checks' => collect($checks)->groupBy('group'),
            'counts' => Doctor::counts($checks),
            'import' => Doctor::lastImportReport(),
        ]);
    }

    /** @return array{slug:string, name:?string, version:?string, chain:list<string>, error:?string} */
    protected function theme(): array
    {
        $slug = (string) config('commerce.theme', 'default');
        $info = ['slug' => $slug, 'name' => null, 'version' => null, 'chain' => [], 'error' => null];
        if (! function_exists('theme')) {
            return $info;
        }
        try {
            $theme = theme();
            $info['slug'] = (string) ($theme->slug ?? $slug);
            $info['name'] = $theme->name ?? null;
            $info['version'] = $theme->version ?? null;
            $manager = app('Pine\\Commerce\\Theme\\ThemeManager');
            if (method_exists($manager, 'chain')) {
                $info['chain'] = array_map(fn ($t) => (string) ($t->slug ?? ''), $manager->chain());
            }
        } catch (Throwable $e) {
            $info['error'] = $e->getMessage();
        }

        return $info;
    }

    /**
     * Every feature switch: configured value, effective storefront value (theme support), package default and what it
     * gates. Read-only here – switches are set per site in config/commerce.php (features), optionally from .env.
     *
     * @return list<array{key:string, on:bool, effective:bool, description:?string, default:?bool}>
     */
    protected function features(): array
    {
        try {
            return Features::report();
        } catch (Throwable) {
            return [];
        }
    }
}
