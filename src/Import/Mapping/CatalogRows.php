<?php

namespace Pine\Commerce\Import\Mapping;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pine\Commerce\Import\Data\SeoData;
use Pine\Commerce\Import\Data\WpTerm;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Import\Support\Formatter;

/**
 * Category, coupon and review rows as both importers write them (the database importer from WordPress tables, the
 * REST API importer from WooCommerce's JSON converted to the same WordPress field names).
 */
final class CatalogRows
{
    /**
     * A `categories` row. The path is the hierarchical slug path (= the storefront URL).
     *
     * @param  ?string  $descriptionHtml  the description when the caller already has it as HTML (else the term's, through wpautop)
     */
    public static function category(WpTerm $term, string $path, ?int $parentId, ?string $image, ?SeoData $seo, ?string $fallbackMetaDescription,
        bool $showInMenu, string $now, ?string $descriptionHtml = null): array
    {
        return [
            'wp_id' => $term->id,
            'parent_id' => $parentId,
            'name' => Formatter::decode($term->name),
            'slug' => urldecode($term->slug),
            'path' => $path,
            'description' => $descriptionHtml ?? Formatter::clean(Formatter::autop($term->description)),
            'extra_content' => null,
            'image' => $image,
            'sort_order' => $term->order,
            'is_visible' => true,
            'show_in_menu' => $showInMenu,
            'meta_title' => Str::limit((string) $seo?->title, 250, '') ?: null,
            'meta_description' => $seo?->description ?? $fallbackMetaDescription,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Term id => hierarchical slug path ("clothing/shirts") for terms with ->slug and ->parent (cycles are cut).
     *
     * @param  iterable<int|string, object>  $terms  id => term
     * @return array<int,string>
     */
    public static function paths(iterable $terms): array
    {
        $byId = [];
        foreach ($terms as $id => $t) {
            $byId[(int) $id] = $t;
        }
        $out = [];
        $path = function (int $id, array $seen = []) use (&$path, $byId) {
            $t = $byId[$id];
            $slug = urldecode((string) $t->slug);
            $parent = (int) $t->parent;

            return ($parent && isset($byId[$parent]) && ! isset($seen[$parent]) ? $path($parent, $seen + [$id => true]).'/' : '').$slug;
        };
        foreach (array_keys($byId) as $id) {
            $out[$id] = $path($id);
        }

        return $out;
    }

    /**
     * A `coupons` row from a coupon in WordPress form (shop_coupon post fields + its meta: discount_type,
     * coupon_amount, product_ids …). Coupons are matched on the lower-cased code.
     *
     * @param  callable(string):void  $warn
     */
    public static function coupon(string $title, string $excerpt, string $postStatus, array $m, ?string $createdGmt, ?string $modifiedGmt,
        array $productMap, array $categoryMap, string $now, callable $warn): array
    {
        $json = fn ($v) => $v === null || $v === [] ? null : json_encode($v);
        $ids = fn ($value, $map) => array_values(array_filter(array_map(fn ($id) => $map[(int) $id] ?? null,
            is_array($u = WordPressSource::unserialize($value)) ? $u : explode(',', (string) $value))));
        $emails = WordPressSource::unserialize($m['customer_email'] ?? '');
        $type = $m['discount_type'] ?? 'fixed_cart';
        if (! in_array($type, ['percent', 'fixed_cart', 'fixed_product'], true)) {
            $warn("Coupon '{$title}' has unsupported type '$type' – imported as fixed_cart");
        }

        return [
            'code' => Str::limit(strtolower(trim(Formatter::decode($title))), 190, ''),
            'description' => Str::limit(Formatter::decode($excerpt), 250, '') ?: null,
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
            'is_active' => $postStatus === 'publish',
            'created_at' => WordPressSource::gmt($createdGmt) ?? $now,
            'updated_at' => WordPressSource::gmt($modifiedGmt) ?? $now,
        ];
    }

    /** A `product_reviews` row. */
    public static function review(int $productId, ?int $userId, string $author, ?string $email, int $rating, string $content, bool $approved,
        bool $verified, ?string $createdGmt, string $now): array
    {
        $created = WordPressSource::gmt($createdGmt) ?? $now;

        return [
            'product_id' => $productId,
            'user_id' => $userId,
            'name' => Formatter::decode($author) ?: 'Customer',
            'email' => $email ? strtolower($email) : null,
            'rating' => max(1, min(5, $rating)),
            'content' => Formatter::decode($content) ?: null,
            'is_approved' => $approved,
            'is_verified_owner' => $verified,
            'created_at' => $created,
            'updated_at' => $created,
        ];
    }

    /**
     * Upsert reviews matched on product + e-mail + date (reviews have no remote-id column). Returns how many were written.
     *
     * @param  list<array>  $rows  review() rows
     */
    public static function saveReviews(array $rows): int
    {
        if (! $rows) {
            return 0;
        }
        $existing = DB::table('product_reviews')->whereIn('product_id', array_values(array_unique(array_column($rows, 'product_id'))))
            ->get(['id', 'product_id', 'email', 'created_at'])
            ->keyBy(fn ($r) => $r->product_id.'|'.$r->email.'|'.$r->created_at)->all();
        foreach ($rows as $row) {
            $key = $row['product_id'].'|'.$row['email'].'|'.$row['created_at'];
            if (isset($existing[$key])) {
                DB::table('product_reviews')->where('id', $existing[$key]->id)->update($row);
            } else {
                DB::table('product_reviews')->insert($row);
                $existing[$key] = (object) ['id' => DB::getPdo()->lastInsertId()];
            }
        }

        return count($rows);
    }
}
