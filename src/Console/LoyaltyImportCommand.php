<?php

namespace Pine\Commerce\Console;

use Illuminate\Console\Command;
use Pine\Commerce\Commerce;
use Pine\Commerce\Models\LoyaltyPoint;
use Pine\Commerce\Services\Loyalty\Loyalty;

/**
 * Bring customers' points balances over from the old shop (feature "loyalty").
 *
 *   php artisan commerce:loyalty:import points.csv --dry-run     what would change
 *   php artisan commerce:loyalty:import points.csv
 *
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
    protected $signature = 'commerce:loyalty:import {file : CSV with email and points columns} {--dry-run : Only show what would change}';

    protected $description = 'Import customers\' loyalty points balances from a CSV (email, points)';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (! is_readable($path) || ! ($handle = fopen($path, 'r'))) {
            $this->error("Can't read {$path}.");

            return self::FAILURE;
        }
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), (array) fgetcsv($handle, 0, ',', '"', ''));
        $emailCol = $this->column($header, ['email', 'user_email', 'customer_email']);
        $pointsCol = $this->column($header, ['points', 'points_balance', 'balance']);
        if ($emailCol === null || $pointsCol === null) {
            $this->error('The first row must name an email column (email) and a points column (points).');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $users = Commerce::userModel()::query();
        $added = $unchanged = $points = 0;
        $missing = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $email = mb_strtolower(trim((string) ($row[$emailCol] ?? '')));
            $target = (int) round((float) ($row[$pointsCol] ?? 0));
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
        fclose($handle);

        $this->info(($dry ? '[dry run] ' : '').number_format($added).' customers updated ('.($points >= 0 ? '+' : '').number_format($points).' points), '
            .number_format($unchanged).' already up to date, '.number_format(count($missing)).' emails without an account here.');
        if ($missing) {
            $this->line('No account: '.implode(', ', array_slice($missing, 0, 20)).(count($missing) > 20 ? ' …' : ''));
        }

        return self::SUCCESS;
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
