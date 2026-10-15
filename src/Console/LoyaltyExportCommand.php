<?php

namespace Pine\Commerce\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Pine\Commerce\Commerce;
use Pine\Commerce\Services\Loyalty\Loyalty;

/**
 * Export every customer's loyalty points balance as CSV (feature "loyalty") – for a backup, an accountant, or moving
 * the balances to another system. The same columns commerce:loyalty:import reads, so the file can be imported back.
 *
 *   php artisan commerce:loyalty:export                       storage/app/private/exports/loyalty-points-{date}.csv
 *   php artisan commerce:loyalty:export /path/points.csv
 *   php artisan commerce:loyalty:export --all                 customers with 0 points too
 */
class LoyaltyExportCommand extends Command
{
    protected $signature = 'commerce:loyalty:export {file? : Where to write the CSV} {--all : Include customers with no points}';

    protected $description = 'Export customers\' loyalty points balances to CSV (email, points, worth)';

    public function handle(): int
    {
        $path = (string) ($this->argument('file') ?: storage_path('app/private/exports/loyalty-points-'.now()->format('Ymd-His').'.csv'));
        @mkdir(dirname($path), 0775, true);
        if (! ($out = @fopen($path, 'w'))) {
            $this->error("Can't write {$path}.");

            return self::FAILURE;
        }
        fputcsv($out, ['email', 'points', 'worth', 'first_name', 'last_name'], ',', '"', '');
        $users = (new (Commerce::userModel()))->getTable();
        $rows = DB::table('loyalty_points')->join($users.' as u', 'u.id', '=', 'loyalty_points.user_id')
            ->selectRaw('u.id, u.email, u.first_name, u.last_name, SUM(loyalty_points.points) as balance')
            ->groupBy('u.id', 'u.email', 'u.first_name', 'u.last_name')
            ->when(! $this->option('all'), fn ($q) => $q->havingRaw('SUM(loyalty_points.points) <> 0'))
            ->orderBy('u.email')->cursor();
        $count = $total = 0;
        foreach ($rows as $row) {
            fputcsv($out, [$row->email, (int) $row->balance, number_format(Loyalty::value((int) $row->balance), 2, '.', ''), $row->first_name, $row->last_name], ',', '"', '');
            $count++;
            $total += (int) $row->balance;
        }
        fclose($out);
        $this->info(number_format($count).' customers, '.number_format($total).' points (worth '.money(Loyalty::value(max(0, $total))).') → '.$path);

        return self::SUCCESS;
    }
}
