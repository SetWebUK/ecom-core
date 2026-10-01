<?php

namespace Pine\Commerce\Scheduling\Tasks;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pine\Commerce\Models\Cart;
use Pine\Commerce\Scheduling\Task;
use Pine\Commerce\Services\Admin\StoreSettings;

/**
 * Daily tidy-up:
 *  - guest baskets (no customer account, never ordered) idle for more than setting
 *    "scheduler.cart_retention_days" days (default 90; 0 = keep them all) – their lines and reminder rows go with them.
 *    Customers' baskets and baskets that became orders are always kept;
 *  - expired database sessions (SESSION_DRIVER=database, older than session.lifetime);
 *  - expired password-reset links (every broker in config auth.passwords, older than its "expire" minutes).
 */
class PruneStaleData extends Task
{
    public const BATCH = 500;

    public static function cartRetentionDays(): int
    {
        return max(0, (int) setting('scheduler.cart_retention_days', StoreSettings::defaultFor('scheduler.cart_retention_days', 90)));
    }

    public function handle(): string
    {
        $carts = $this->pruneCarts();
        $sessions = $this->pruneSessions();
        $tokens = $this->pruneResetTokens();

        return "{$carts} guest ".str('basket')->plural($carts).", {$sessions} ".str('session')->plural($sessions)
            .", {$tokens} reset ".str('link')->plural($tokens).' deleted';
    }

    public function pruneCarts(): int
    {
        $days = static::cartRetentionDays();
        if ($days <= 0) {
            return 0;
        }
        $deleted = 0;
        do {
            $ids = Cart::query()->whereNull('user_id')->whereNull('converted_at')
                ->where('updated_at', '<', now()->subDays($days))
                ->orderBy('id')->limit(self::BATCH)->pluck('id')->all();
            if (! $ids) {
                break;
            }
            DB::transaction(function () use ($ids) {
                // children first: SQLite installs may not enforce the ON DELETE CASCADE foreign keys
                DB::table('cart_items')->whereIn('cart_id', $ids)->delete();
                if (Schema::hasTable('cart_recovery_emails')) {
                    DB::table('cart_recovery_emails')->whereIn('cart_id', $ids)->delete();
                }
                DB::table('carts')->whereIn('id', $ids)->delete();
            });
            $deleted += count($ids);
        } while (count($ids) === self::BATCH);

        return $deleted;
    }

    public function pruneSessions(): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }
        $connection = config('session.connection');
        $table = (string) config('session.table', 'sessions');
        if (! Schema::connection($connection)->hasTable($table)) {
            return 0;
        }

        return DB::connection($connection)->table($table)
            ->where('last_activity', '<=', now()->subMinutes(max(1, (int) config('session.lifetime', 120)))->getTimestamp())
            ->delete();
    }

    public function pruneResetTokens(): int
    {
        $deleted = 0;
        $seen = [];
        foreach ((array) config('auth.passwords', []) as $broker) {
            $table = is_array($broker) ? ($broker['table'] ?? null) : null;
            $connection = is_array($broker) ? ($broker['connection'] ?? null) : null;
            if (! $table || isset($seen[$connection.'|'.$table]) || ! Schema::connection($connection)->hasTable($table)) {
                continue;
            }
            $seen[$connection.'|'.$table] = true;
            $deleted += DB::connection($connection)->table($table)
                ->where('created_at', '<', now()->subMinutes(max(1, (int) ($broker['expire'] ?? 60))))
                ->delete();
        }

        return $deleted;
    }
}
