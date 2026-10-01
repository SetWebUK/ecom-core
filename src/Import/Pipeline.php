<?php

namespace Pine\Commerce\Import;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Pine\Commerce\Import\Contracts\Step;
use Pine\Commerce\Import\Steps;

/**
 * Orders and runs the import steps: the core steps + adapter steps, sorted by section, then registration order,
 * then topologically by Step::after(); --only / --skip select by section, step key or alias. Every step runs in its
 * own transaction on the target connection; --dry-run wraps the whole run in a transaction that is rolled back.
 */
class Pipeline
{
    public const SECTIONS = ['settings', 'media', 'users', 'catalog', 'orders', 'extras', 'content', 'menus', 'redirects'];

    /** Core steps in their natural order (§12.4). */
    public const CORE = [
        Steps\SettingsStep::class,
        Steps\MediaFilesStep::class,
        Steps\MediaStep::class,
        Steps\UsersStep::class,
        Steps\CategoriesStep::class,
        Steps\AttributesStep::class,
        Steps\ProductsStep::class,
        Steps\VariationsStep::class,
        Steps\OrdersStep::class,
        Steps\ReviewsStep::class,
        Steps\CouponsStep::class,
        Steps\ShippingStep::class,
        Steps\TaxStep::class,
        Steps\PagesStep::class,
        Steps\PostsStep::class,
        Steps\MenusStep::class,
        Steps\RedirectsStep::class,
    ];

    public const ALIASES = [
        'customers' => 'users', 'products' => 'catalog', 'categories' => 'catalog.categories', 'attributes' => 'catalog.attributes',
        'variations' => 'catalog.variations', 'pages' => 'content.pages', 'posts' => 'content.posts', 'blog' => 'content.posts',
        'coupons' => 'extras.coupons', 'shipping' => 'extras.shipping', 'tax' => 'extras.tax', 'reviews' => 'extras.reviews', 'forms' => 'extras.forms',
        'wishlists' => 'extras.wishlists', 'stock-alerts' => 'extras.stock-alerts', 'uploads' => 'media.files', 'files' => 'media.files',
    ];

    /** @var list<Step> */
    private array $steps;

    /** @param list<Step> $adapterSteps */
    public function __construct(array $adapterSteps = [], ?array $core = null)
    {
        $steps = [];
        foreach (array_merge(array_map(fn ($c) => app($c), $core ?? self::CORE), $adapterSteps) as $step) {
            $steps[$step->key()] ??= $step;
        }
        $this->steps = self::order(array_values($steps));
    }

    /** @return list<Step> */
    public function steps(): array
    {
        return $this->steps;
    }

    /**
     * Sort by section rank (unknown sections after 'extras', before 'content'), keep registration order within a
     * section, then satisfy after() constraints (a step moves behind every step it names).
     *
     * @param  list<Step>  $steps
     * @return list<Step>
     */
    public static function order(array $steps): array
    {
        $rank = fn (Step $s) => ($i = array_search($s->section(), self::SECTIONS, true)) !== false ? $i * 10 : 55;
        $indexed = [];
        foreach ($steps as $i => $step) {
            $indexed[] = [$rank($step), $i, $step];
        }
        usort($indexed, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $sorted = array_map(fn ($x) => $x[2], $indexed);

        // Stable topological pass: repeatedly emit the first step whose dependencies (present in the list) are emitted.
        $keys = array_flip(array_map(fn (Step $s) => $s->key(), $sorted));
        $out = [];
        $done = [];
        while ($sorted) {
            foreach ($sorted as $i => $step) {
                $pending = array_filter($step->after(), fn ($k) => isset($keys[$k]) && ! isset($done[$k]) && $k !== $step->key());
                if (! $pending) {
                    $out[] = $step;
                    $done[$step->key()] = true;
                    unset($sorted[$i]);
                    $sorted = array_values($sorted);
                    continue 2;
                }
            }
            throw new InvalidArgumentException('Import steps have circular dependencies: '.implode(', ', array_map(fn ($s) => $s->key(), $sorted)));
        }

        return $out;
    }

    /**
     * Steps selected by --only/--skip (comma lists of sections, step keys or aliases).
     *
     * @return list<Step>
     */
    public function select(?string $only, ?string $skip): array
    {
        $match = function (Step $step, array $names) {
            foreach ($names as $name) {
                if ($name === $step->section() || $name === $step->key() || str_starts_with($step->key(), $name.'.')) {
                    return true;
                }
            }

            return false;
        };
        $only = $this->names($only);
        $skip = $this->names($skip);

        return array_values(array_filter($this->steps, fn (Step $s) => (! $only || $match($s, $only)) && ! $match($s, $skip)));
    }

    /** @return list<string> */
    private function names(?string $list): array
    {
        $names = [];
        foreach (array_filter(array_map('trim', explode(',', strtolower((string) $list)))) as $name) {
            $name = self::ALIASES[$name] ?? $name;
            $known = false;
            foreach ($this->steps as $step) {
                if ($name === $step->section() || $name === $step->key() || str_starts_with($step->key(), $name.'.')) {
                    $known = true;
                    break;
                }
            }
            if (! $known) {
                throw new InvalidArgumentException("Unknown section/step '$name'. Valid: ".implode(', ', array_unique(array_merge(
                    array_map(fn ($s) => $s->section(), $this->steps), array_map(fn ($s) => $s->key(), $this->steps), array_keys(self::ALIASES)))));
            }
            $names[] = $name;
        }

        return $names;
    }

    /**
     * Run steps: --fresh purges in reverse order first, then each step runs in a transaction. With $dryRun the whole
     * run is rolled back at the end (row data unchanged; auto-increment counters may still advance).
     *
     * @param  list<Step>  $steps
     * @param  callable(Step, callable): void|null  $wrap  runs a step (e.g. inside a console task line)
     */
    public static function run(ImportContext $ctx, array $steps, ?callable $wrap = null): void
    {
        $wrap ??= fn (Step $step, callable $run) => $run();
        $db = DB::connection();
        if ($ctx->dryRun) {
            $db->beginTransaction();
        }
        try {
            if ($ctx->fresh) {
                foreach (array_reverse($steps) as $step) {
                    if ($step->shouldRun($ctx)) {
                        $db->transaction(fn () => $step->purge($ctx));
                    }
                }
            }
            foreach ($steps as $step) {
                if (! $step->shouldRun($ctx)) {
                    continue;
                }
                $wrap($step, fn () => $db->transaction(fn () => $step->run($ctx)));
            }
        } finally {
            if ($ctx->dryRun) {
                $db->rollBack();
            }
        }
    }
}
