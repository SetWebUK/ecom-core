<?php

namespace Pine\Commerce\Import\Steps;

use Illuminate\Support\Facades\DB;
use Pine\Commerce\Import\Source\WordPressSource;

/**
 * Back-in-stock subscriptions -> stock_notifications: CWG "Back In Stock Notifier" (cwginstocknotifier posts),
 * plus the per-product waitlist meta of "WooCommerce Waitlist" (woocommerce_waitlist) and YITH WooCommerce Waiting
 * List (_yith_wcwtl_users_list). Matched on product + email.
 */
class StockAlertsStep extends AbstractStep
{
    public function key(): string
    {
        return 'extras.stock-alerts';
    }

    public function section(): string
    {
        return 'extras';
    }

    public function after(): array
    {
        return ['catalog.variations'];
    }

    protected function clear(): void
    {
        $emails = array_column($this->subscriptions(), 'email');
        foreach (array_chunk($emails, 500) as $chunk) {
            DB::table('stock_notifications')->whereIn('email', $chunk)->delete();
        }
    }

    /** @return list<array{wp_product:int,wp_variation:int,email:string,notified_at:?string,created_at:?string,updated_at:?string}> */
    private function subscriptions(): array
    {
        $out = [];
        $posts = $this->wp->posts('cwginstocknotifier');
        $meta = $this->wp->postMeta($posts->pluck('ID')->all());
        foreach ($posts as $post) {
            $m = $meta[$post->ID] ?? [];
            $out[] = [
                'wp_product' => (int) ($m['cwginstock_product_id'] ?? $m['cwginstock_pid'] ?? 0),
                'wp_variation' => (int) ($m['cwginstock_variation_id'] ?? 0),
                'email' => strtolower(trim((string) ($m['cwginstock_subscriber_email'] ?? $post->post_title))),
                'notified_at' => $post->post_status === 'cwg_mailsent' ? (WordPressSource::ts($m['cwginstock_mail_on'] ?? null) ?? WordPressSource::gmt($post->post_modified_gmt)) : null,
                'created_at' => WordPressSource::gmt($post->post_date_gmt),
                'updated_at' => WordPressSource::gmt($post->post_modified_gmt),
            ];
        }
        // Waitlist plugins keep subscribers in product/variation meta.
        foreach ($this->wp->table('postmeta as pm')->join('posts as p', 'p.ID', '=', 'pm.post_id')
            ->whereIn('pm.meta_key', ['woocommerce_waitlist', '_yith_wcwtl_users_list'])
            ->get(['pm.post_id', 'pm.meta_value', 'p.post_type', 'p.post_parent']) as $row) {
            $list = WordPressSource::unserialize($row->meta_value);
            $isVariation = $row->post_type === 'product_variation';
            foreach (is_array($list) ? $list : [] as $key => $value) {
                $email = is_string($value) && str_contains($value, '@') ? $value : (is_string($key) && str_contains($key, '@') ? $key : null);
                if (! $email && is_numeric($value)) {
                    $email = $this->wp->table('users')->where('ID', (int) $value)->value('user_email');
                }
                if ($email) {
                    $out[] = ['wp_product' => $isVariation ? (int) $row->post_parent : (int) $row->post_id,
                        'wp_variation' => $isVariation ? (int) $row->post_id : 0, 'email' => strtolower(trim($email)),
                        'notified_at' => null, 'created_at' => null, 'updated_at' => null];
                }
            }
        }

        return $out;
    }

    protected function import(): void
    {
        $productMap = $this->ctx->map('products');
        $variationMap = $this->ctx->map('product_variations');
        $existing = DB::table('stock_notifications')->get(['id', 'product_id', 'email'])
            ->keyBy(fn ($r) => $r->product_id.'|'.$r->email)->all();
        $subscriptions = $this->subscriptions();
        $rows = [];
        $count = 0;
        foreach ($subscriptions as $s) {
            $productId = $productMap[$s['wp_product']] ?? null;
            if (! $productId || ! filter_var($s['email'], FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $row = [
                'product_id' => $productId,
                'product_variation_id' => $s['wp_variation'] ? ($variationMap[$s['wp_variation']] ?? null) : null,
                'email' => $s['email'],
                'notified_at' => $s['notified_at'],
                'created_at' => $s['created_at'] ?? $this->now(),
                'updated_at' => $s['updated_at'] ?? $this->now(),
            ];
            $key = $productId.'|'.$s['email'];
            if (isset($existing[$key])) {
                DB::table('stock_notifications')->where('id', $existing[$key]->id)->update($row);
            } elseif (! isset($rows[$key])) {
                $rows[$key] = $row;
            }
            $count++;
        }
        $this->ctx->insert('stock_notifications', array_values($rows));
        $this->ctx->count('Back-in-stock subscriptions', count($subscriptions), $count);
    }
}
