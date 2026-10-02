<?php

namespace Pine\Commerce\Import\Steps;

use Illuminate\Support\Facades\DB;
use Pine\Commerce\Import\Mapping\CatalogRows;

/** shop_coupon posts -> coupons (matched on the lower-cased code). */
class CouponsStep extends AbstractStep
{
    public function key(): string
    {
        return 'extras.coupons';
    }

    public function section(): string
    {
        return 'extras';
    }

    public function after(): array
    {
        return ['catalog.products'];
    }

    protected function clear(): void
    {
        $codes = $this->wp->posts('shop_coupon')->map(fn ($p) => strtolower($p->post_title))->all();
        DB::table('coupons')->whereIn('code', $codes)->delete();
    }

    protected function import(): void
    {
        $posts = $this->wp->posts('shop_coupon');
        $meta = $this->wp->postMeta($posts->pluck('ID')->all());
        $productMap = $this->ctx->map('products');
        $categoryMap = $this->ctx->map('categories');
        $rows = [];
        foreach ($posts as $post) {
            $rows[] = CatalogRows::coupon((string) $post->post_title, (string) $post->post_excerpt, (string) $post->post_status, $meta[$post->ID] ?? [],
                $post->post_date_gmt, $post->post_modified_gmt, $productMap, $categoryMap, $this->now(), fn ($w) => $this->ctx->warn($w));
        }
        $this->ctx->save('coupons', $rows, 'code', ['created_at']);
        $this->ctx->count('Coupons', $posts->count(), DB::table('coupons')->count());
    }
}
