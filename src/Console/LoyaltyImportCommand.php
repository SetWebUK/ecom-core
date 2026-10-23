<?php

namespace Pine\Commerce\Console;

use Illuminate\Console\Command;
use Pine\Commerce\Commerce;
use Pine\Commerce\Models\LoyaltyPoint;
use Pine\Commerce\Services\Loyalty\Loyalty;

/**
 * Bring customers' points balances over from the old shop (feature "loyalty").
 *
 *   php artisan commerce:loyalty:import --from-wordpress --dry-run   straight from WooCommerce Points & Rewards' table in
 *   php artisan commerce:loyalty:import --from-wordpress             the WordPress database (read only; the importer's
 *                                                                    WP_DB_* settings, or --wp-path=/path/to/wordpress)
 *   php artisan commerce:loyalty:import points.csv --dry-run         or from a CSV
 *   php artisan commerce:loyalty:import points.csv
 *
 * The WooCommerce REST API doesn't expose Points & Rewards balances, so they come from the database or a CSV of it.
 * The CSV has a header row with an email column (email / user_email / customer_email) and a points column
 * (points / points_balance / balance). From WooCommerce Points & Rewards, e.g. in phpMyAdmin:
 *   SELECT u.user_email AS email, SUM(p.points_balance) AS points FROM wp_wc_points_rewards_user_points p
 *   JOIN wp_users u ON u.ID = p.user_id GROUP BY u.user_email HAVING points > 0
 * Each customer's imported points are one "Balance from the old website" row. Safe to run again (e.g. with a fresher
 * export on launch day): only the difference from what was imported before is added, and points earned or spent on
 * this site are never touched. Emails with no customer account here are listed and skipped.
 */
class LoyaltyImportCommand extends Command
{
    protected $signature = 'commerce:loyalty:import {file? : CSV with email and points columns}
        {--from-wordpress : Read the balances from WooCommerce Points & Rewards in the WordPress database}
        {--wp-path= : WordPress folder whose wp-config.php gives the database (else the WP_DB_* settings)}
        {--dry-run : Only show what would change}';

    protected $description = 'Import customers\' loyalty points balances from the old WordPress shop or a CSV (email, points)';

    public function handle(): int
    {
        $balances = $this->option('from-wordpress') ? $this->fromWordPress() : $this->fromCsv((string) $this->argument('file'));
        if ($balances === null) {
            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $users = Commerce::userModel()::query();
        $added = $unchanged = $points = 0;
        $missing = [];
        foreach ($balances as [$email, $target]) {
            $email = mb_strtolower(trim((string) $email));
            $target = (int) round((float) $target);
            if ($email === '' || $target < 0) {
                continue;
            }
            $user = (clone $users)->whereRaw('LOWER(email) = ?', [$email])->first(['id']);
            if (! $user) {
                $missing[] = $email;

                continue;
            }
            $before = (int) LoyaltyPoint::query()->where('user_id', $user->id)->where('type', 'import')->sum('points');
            $delta = $target - $before;
            if ($delta === 0) {
                $unchanged++;

                continue;
            }
            if (! $dry) {
                Loyalty::add((int) $user->id, $delta, 'import', null, 'Old website balance '.number_format($target));
            }
            $added++;
            $points += $delta;
        }

        $this->info(($dry ? '[dry run] ' : '').number_format($added).' customers updated ('.($points >= 0 ? '+' : '').number_format($points).' points), '
            .number_format($unchanged).' already up to date, '.number_format(count($missing)).' emails without an account here.');
        if ($missing) {
            $this->line('No account: '.implode(', ', array_slice($missing, 0, 20)).(count($missing) > 20 ? ' …' : ''));
        }

        return self::SUCCESS;
    }

    /** @return list<array{0: string, 1: int|float|string}>|null [email, points] rows, null after an error */
    protected function fromCsv(string $path): ?array
    {
        if ($path === '' || ! is_readable($path) || ! ($handle = fopen($path, 'r'))) {
            $this->error($path === '' ? 'Give a CSV file, or --from-wordpress.' : "Can't read {$path}.");

            return null;
        }
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), (array) fgetcsv($handle, 0, ',', '"', ''));
        $emailCol = $this->column($header, ['email', 'user_email', 'customer_email']);
        $pointsCol = $this->column($header, ['points', 'points_balance', 'balance']);
        if ($emailCol === null || $pointsCol === null) {
            fclose($handle);
            $this->error('The first row must name an email column (email) and a points column (points).');

            return null;
        }
        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = [(string) ($row[$emailCol] ?? ''), $row[$pointsCol] ?? 0];
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Current balances from WooCommerce Points & Rewards: the sum of points_balance in {prefix}wc_points_rewards_user_points
     * per customer email (read only).
     *
     * @return list<array{0: string, 1: int|float|string}>|null
     */
    protected function fromWordPress(): ?array
    {
        try {
            $source = new \Pine\Commerce\Import\Source\SourceConnection(['wp_path' => $this->option('wp-path')], (array) config('commerce-import.source', []));
            $wp = new \Pine\Commerce\Import\Source\WordPressSource($source->register());
            if (! $wp->hasTable('wc_points_rewards_user_points')) {
                $this->error('The WordPress database has no WooCommerce Points & Rewards table (wc_points_rewards_user_points).');

                return null;
            }
            $grammar = $wp->db()->getQueryGrammar(); // raw SQL needs the table prefix the aliases get
            $email = $grammar->wrap('u.user_email');
            $rows = $wp->db()->table('wc_points_rewards_user_points as p')
                ->join('users as u', 'u.ID', '=', 'p.user_id')
                ->selectRaw("LOWER({$email}) as email, SUM({$grammar->wrap('p.points_balance')}) as points")
                ->groupByRaw("LOWER({$email})")
                ->get();
        } catch (\Throwable $e) {
            $this->error('Cannot read the WordPress database: '.$e->getMessage());

            return null;
        }
        $this->line('WordPress: '.number_format($rows->count()).' customers with Points & Rewards balances.');

        return $rows->map(fn ($r) => [(string) $r->email, $r->points])->all();
    }

    protected function column(array $header, array $names): ?int
    {
        foreach ($names as $name) {
            if (($i = array_search($name, $header, true)) !== false) {
                return (int) $i;
            }
        }

        return null;
    }
}
