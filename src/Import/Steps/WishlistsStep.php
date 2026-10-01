<?php

namespace Pine\Commerce\Import\Steps;

use Illuminate\Support\Facades\DB;

/** YITH WooCommerce Wishlist ({p}yith_wcwl) and TI WooCommerce Wishlist ({p}tinvwl_items) items of registered users. */
class WishlistsStep extends AbstractStep
{
    public function key(): string
    {
        return 'extras.wishlists';
    }

    public function section(): string
    {
        return 'extras';
    }

    public function after(): array
    {
        return ['users', 'catalog.products'];
    }

    protected function import(): void
    {
        $productMap = $this->ctx->map('products');
        $userMap = $this->ctx->map('users');
        $rows = [];
        $wp = 0;
        if ($this->wp->hasTable('yith_wcwl')) {
            $wp += $this->wp->table('yith_wcwl')->count();
            foreach ($this->wp->table('yith_wcwl')->whereNotNull('user_id')->get() as $w) {
                if (($u = $userMap[$w->user_id] ?? null) && ($p = $productMap[$w->prod_id] ?? null)) {
                    $rows[$u.'|'.$p] = ['user_id' => $u, 'product_id' => $p, 'created_at' => $w->dateadded, 'updated_at' => $w->dateadded];
                }
            }
        }
        if ($this->wp->hasTable('tinvwl_items')) {
            $wp += $this->wp->table('tinvwl_items')->count();
            foreach ($this->wp->table('tinvwl_items')->get() as $w) {
                if (($u = $userMap[$w->author] ?? null) && ($p = $productMap[$w->product_id] ?? null)) {
                    $rows[$u.'|'.$p] = ['user_id' => $u, 'product_id' => $p, 'created_at' => $w->date, 'updated_at' => $w->date];
                }
            }
        }
        foreach ($rows as $row) {
            DB::table('wishlist_items')->updateOrInsert(['user_id' => $row['user_id'], 'product_id' => $row['product_id']], $row);
        }
        $this->ctx->count('Wishlist items', $wp, count($rows), 'only items of existing accounts');
    }
}
