<?php

namespace Pine\Commerce\Import\Steps;

use Illuminate\Support\Facades\DB;
use Pine\Commerce\Import\Contracts\OrderNumberProvider;
use Pine\Commerce\Import\Data\WcOrder;
use Pine\Commerce\Import\Mapping\OrderRows;
use Pine\Commerce\Import\Mapping\OrderWriter;
use Pine\Commerce\Import\Source\WordPressSource;

/**
 * WooCommerce orders from either storage (HPOS tables or legacy posts – ImportContext::orderSource()), in chunks:
 * totals, addresses, payment, attribution, line items (product/variation ids remapped), shipping line, coupon and fee
 * lines (kept in orders.meta – the schema has no columns for them), refunds (+ refunded quantities), order notes and
 * a payment record for every paid order. Order numbers come from an OrderNumberProvider (sequential-number plugins),
 * else the WooCommerce order id.
 */
class OrdersStep extends AbstractStep
{
    public function key(): string
    {
        return 'orders';
    }

    public function section(): string
    {
        return 'orders';
    }

    public function after(): array
    {
        return ['users', 'catalog.variations'];
    }

    /** Order meta copied into orders.meta (JSON) for reference in the admin (`commerce-import.orders.meta_keys` adds more). */
    public const META_KEYS = OrderRows::META_KEYS;

    public const CHUNK = 500;

    protected function clear(): void
    {
        $this->ctx->owned('orders')->delete(); // cascades items, notes, refunds, payments
    }

    protected function import(): void
    {
        $source = $this->ctx->orderSource();
        $numbers = $this->ctx->adapters->providers(OrderNumberProvider::class);
        $mapper = OrderRows::lookups($this->ctx->upserter(), (array) $this->ctx->config('orders.meta_keys', []), $this->ctx->site->woo['currency'] ?? 'GBP');
        $writer = new OrderWriter($this->ctx->upserter(), 'wordpress');

        $orderIds = [];      // wp id => Laravel id
        $itemIdMap = [];     // wp item id => Laravel order_items id
        $statuses = [];
        $wpCount = 0;
        $lineItems = 0;
        $flat = 0;
        $payments = 0;
        $withFees = 0;
        foreach ($source->orders(self::CHUNK) as $chunk) {
            $mapped = [];
            foreach ($chunk as $order) {
                /** @var WcOrder $order */
                $wpCount++;
                $order->number = $this->number($order, $numbers);
                $result = $mapper->map($order, $this->now());
                if (is_string($result)) {
                    $this->ctx->warn($result);

                    continue;
                }
                $lineItems += $result['line_items'];
                $withFees += $result['fees'] ? 1 : 0;
                $statuses[$order->status] = ($statuses[$order->status] ?? 0) + 1;
                $mapped[] = $result;
            }
            // Imported numbers must stay unique: orders sharing a number with a non-imported order are reported above.
            $written = $writer->write($mapped, $this->now());
            $orderIds += $written['orders'];
            $itemIdMap += $written['items'];
            $flat += $written['line_items'];
            $payments += $written['payments'];
        }

        $wpRefunds = $source->refundCount();
        $refunds = $writer->refunds($source->refunds(array_keys($orderIds)), $orderIds, $itemIdMap, $mapper->staffByWpId, $this->now());
        [$notes, $wpNotes] = $this->notes($orderIds);

        $this->ctx->count('Orders', $wpCount.' ('.$source->storage().' storage)', $this->ctx->owned('orders')->count(),
            'statuses: '.collect($statuses)->map(fn ($c, $s) => "$s $c")->implode(', ').($withFees ? "; $withFees with fee lines (orders.meta.fees)" : ''));
        $this->ctx->count('Order line items', $lineItems, $flat);
        $this->ctx->count('Refunds', $wpRefunds, $refunds);
        $this->ctx->count('Order notes', $wpNotes, $notes);
        $this->ctx->count('Payments (paid orders)', '—', $payments);
        $linked = $this->ctx->owned('orders')->whereNotNull('user_id')->count();
        $this->ctx->count('Orders linked to customer accounts', '—', $linked, 'by WP user or billing email');
    }

    /** @param list<OrderNumberProvider> $providers */
    private function number(WcOrder $order, array $providers): string
    {
        foreach ($providers as $provider) {
            if (($n = $provider->orderNumber($order)) !== null && $n !== '') {
                return $n;
            }
        }

        return (string) $order->id;
    }

    /** Order notes (comments of type order_note, in comment order) – streamed. @return array{0:int,1:int} */
    private function notes(array $orderIds): array
    {
        $staff = DB::table('users')->whereIn('role', ['admin', 'manager'])->pluck('id', 'email')->all();
        $staffByLogin = [];
        foreach ($this->wp->table('users')->get(['ID', 'user_login', 'display_name', 'user_email']) as $u) {
            $id = $staff[strtolower($u->user_email)] ?? null;
            if ($id) {
                $staffByLogin[$u->user_login] = $staffByLogin[$u->display_name] = $id;
            }
        }
        $customerFlags = $this->wp->table('commentmeta')->where('meta_key', 'is_customer_note')->where('meta_value', '1')
            ->pluck('comment_id')->flip()->all();

        $count = 0;
        $wpCount = 0;
        $rows = [];
        foreach ($this->wp->table('comments')->where('comment_type', 'order_note')->orderBy('comment_ID')->cursor() as $c) {
            $wpCount++;
            $orderId = $orderIds[$c->comment_post_ID] ?? null;
            if (! $orderId) {
                continue;
            }
            $isSystem = in_array($c->comment_author, ['WooCommerce', 'system', ''], true);
            $rows[] = OrderWriter::noteRow($orderId, $staff[strtolower((string) $c->comment_author_email)] ?? $staffByLogin[$c->comment_author] ?? null,
                (string) $c->comment_content, isset($customerFlags[$c->comment_ID]), $isSystem, WordPressSource::gmt($c->comment_date_gmt), $this->now());
            if (count($rows) >= 1000) {
                $this->ctx->insert('order_notes', $rows);
                $count += count($rows);
                $rows = [];
            }
        }
        $this->ctx->insert('order_notes', $rows);

        return [$count + count($rows), $wpCount];
    }
}
