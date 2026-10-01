<?php

namespace Pine\Commerce\Import\Steps;

use Illuminate\Support\Facades\DB;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Import\Support\Formatter;

/** WooCommerce product reviews (comments with a rating) -> product_reviews, matched on product + email + date. */
class ReviewsStep extends AbstractStep
{
    public function key(): string
    {
        return 'extras.reviews';
    }

    public function section(): string
    {
        return 'extras';
    }

    public function after(): array
    {
        return ['catalog.products'];
    }

    protected function import(): void
    {
        $productMap = $this->ctx->map('products');
        $comments = $this->wp->table('comments as c')
            ->join('posts as p', 'p.ID', '=', 'c.comment_post_ID')
            ->where('p.post_type', 'product')
            ->whereIn('c.comment_type', ['review', 'comment', ''])
            ->whereIn('c.comment_approved', ['0', '1'])
            ->select('c.*')->orderBy('c.comment_ID')->get();
        $meta = [];
        foreach (array_chunk($comments->pluck('comment_ID')->all(), 2000) ?: [[0]] as $chunk) {
            foreach ($this->wp->table('commentmeta')->whereIn('comment_id', $chunk)->whereIn('meta_key', ['rating', 'verified'])->get() as $row) {
                $meta[$row->comment_id][$row->meta_key] = $row->meta_value;
            }
        }
        $users = DB::table('users')->pluck('id', 'email')->all();

        $existing = DB::table('product_reviews')->get(['id', 'product_id', 'email', 'created_at'])
            ->keyBy(fn ($r) => $r->product_id.'|'.$r->email.'|'.$r->created_at)->all();
        $count = 0;
        foreach ($comments as $c) {
            $productId = $productMap[$c->comment_post_ID] ?? null;
            $rating = (int) ($meta[$c->comment_ID]['rating'] ?? 0);
            if (! $productId || $rating < 1) {
                continue;
            }
            $email = strtolower((string) $c->comment_author_email) ?: null;
            $created = WordPressSource::gmt($c->comment_date_gmt) ?? $this->now();
            $row = [
                'product_id' => $productId,
                'user_id' => $email ? ($users[$email] ?? null) : null,
                'name' => Formatter::decode($c->comment_author) ?: 'Customer',
                'email' => $email,
                'rating' => min(5, $rating),
                'content' => Formatter::decode($c->comment_content) ?: null,
                'is_approved' => $c->comment_approved === '1',
                'is_verified_owner' => ($meta[$c->comment_ID]['verified'] ?? '0') === '1',
                'created_at' => $created,
                'updated_at' => $created,
            ];
            $key = $productId.'|'.$email.'|'.$created;
            if (isset($existing[$key])) {
                DB::table('product_reviews')->where('id', $existing[$key]->id)->update($row);
            } else {
                DB::table('product_reviews')->insert($row);
            }
            $count++;
        }
        $this->ctx->count('Product reviews', $comments->count(), $count);
    }
}
