<?php

namespace Pine\Commerce\Import\Steps;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Import\Support\Formatter;

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
        $json = fn ($v) => $v === null || $v === [] ? null : json_encode($v);
        $ids = fn ($value, $map) => array_values(array_filter(array_map(fn ($id) => $map[(int) $id] ?? null,
            is_array($u = WordPressSource::unserialize($value)) ? $u : explode(',', (string) $value))));

        $rows = [];
        foreach ($posts as $post) {
            $m = $meta[$post->ID] ?? [];
            $emails = WordPressSource::unserialize($m['customer_email'] ?? '');
            $type = $m['discount_type'] ?? 'fixed_cart';
            if (! in_array($type, ['percent', 'fixed_cart', 'fixed_product'], true)) {
                $this->ctx->warn("Coupon '{$post->post_title}' has unsupported type '$type' – imported as fixed_cart");
            }
            $rows[] = [
                'code' => Str::limit(strtolower(trim(Formatter::decode($post->post_title))), 190, ''),
                'description' => Str::limit(Formatter::decode($post->post_excerpt), 250, '') ?: null,
                'type' => in_array($type, ['percent', 'fixed_cart', 'fixed_product'], true) ? $type : 'fixed_cart',
                'amount' => WordPressSource::decimal($m['coupon_amount'] ?? 0) ?? 0,
                'free_shipping' => WordPressSource::yes($m['free_shipping'] ?? 'no'),
                'minimum_spend' => WordPressSource::decimal($m['minimum_amount'] ?? null),
                'maximum_spend' => WordPressSource::decimal($m['maximum_amount'] ?? null),
                'individual_use' => WordPressSource::yes($m['individual_use'] ?? 'no'),
                'exclude_sale_items' => WordPressSource::yes($m['exclude_sale_items'] ?? 'no'),
                'product_ids' => $json($ids($m['product_ids'] ?? '', $productMap)),
                'excluded_product_ids' => $json($ids($m['exclude_product_ids'] ?? '', $productMap)),
                'category_ids' => $json($ids($m['product_categories'] ?? '', $categoryMap)),
                'excluded_category_ids' => $json($ids($m['exclude_product_categories'] ?? '', $categoryMap)),
                'allowed_emails' => $json(is_array($emails) && $emails ? array_values($emails) : null),
                'usage_limit' => (int) ($m['usage_limit'] ?? 0) ?: null,
                'usage_limit_per_user' => (int) ($m['usage_limit_per_user'] ?? 0) ?: null,
                'usage_count' => (int) ($m['usage_count'] ?? 0),
                'starts_at' => null,
                'expires_at' => WordPressSource::ts($m['date_expires'] ?? null),
                'is_active' => $post->post_status === 'publish',
                'created_at' => WordPressSource::gmt($post->post_date_gmt) ?? $this->now(),
                'updated_at' => WordPressSource::gmt($post->post_modified_gmt) ?? $this->now(),
            ];
        }
        $this->ctx->save('coupons', $rows, 'code', ['created_at']);
        $this->ctx->count('Coupons', $posts->count(), DB::table('coupons')->count());
    }
}
