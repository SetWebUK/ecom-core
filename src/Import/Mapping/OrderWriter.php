<?php

namespace Pine\Commerce\Import\Mapping;

use Illuminate\Support\Facades\DB;
use Pine\Commerce\Import\Data\WcRefund;
use Pine\Commerce\Import\Support\Formatter;
use Pine\Commerce\Import\Support\Upserter;

/**
 * Stores mapped orders (OrderRows::map()) idempotently: the orders are upserted on their remote id within the import
 * source, their child rows (items, tax lines, notes, refunds and the payment record the importer made) are rebuilt.
 * Shared by the database importer and the WooCommerce REST API importer.
 */
final class OrderWriter
{
    /** @param string $paymentSource payments.payload->source of the payment rows this importer creates (rebuilt on re-import) */
    public function __construct(private readonly Upserter $upserter, private readonly string $paymentSource = 'wordpress') {}

    /**
     * @param  list<array{row:array, items:array<int|string,array>, tax_lines:list<array>}>  $mapped
     * @param  bool  $replaceNotes  false = keep the orders' notes (the caller does not re-import them)
     * @return array{orders:array<int|string,int>, items:array<int|string,int>, payments:int, line_items:int}
     *               remote order id => orders.id, remote item id => order_items.id
     */
    public function write(array $mapped, string $now, bool $replaceNotes = true): array
    {
        if (! $mapped) {
            return ['orders' => [], 'items' => [], 'payments' => 0, 'line_items' => 0];
        }
        $rows = array_column($mapped, 'row');
        $orderIds = $this->upserter->save('orders', $rows, 'wp_id', ['created_at']);
        $ids = array_values($orderIds);
        foreach (array_merge(['order_items', 'refunds', 'order_tax_lines'], $replaceNotes ? ['order_notes'] : []) as $table) {
            foreach (array_chunk($ids, 500) as $part) {
                DB::table($table)->whereIn('order_id', $part)->delete();
            }
        }
        foreach (array_chunk($ids, 500) as $part) {
            DB::table('payments')->whereIn('order_id', $part)->where('payload->source', $this->paymentSource)->delete();
        }

        // line items one by one: refunds need the remote item id -> new id map
        $itemIds = [];
        $lineItems = 0;
        $taxLines = [];
        foreach ($mapped as $m) {
            $orderId = $orderIds[$m['row']['wp_id']] ?? null;
            if (! $orderId) {
                continue;
            }
            foreach ($m['items'] as $remoteItemId => $item) {
                $itemIds[$remoteItemId] = DB::table('order_items')->insertGetId(['order_id' => $orderId] + $item);
                $lineItems++;
            }
            foreach ($m['tax_lines'] as $line) {
                $taxLines[] = ['order_id' => $orderId] + $line;
            }
        }
        $this->upserter->insert('order_tax_lines', $taxLines);

        $payments = [];
        foreach ($rows as $row) {
            if (! $row['paid_at'] || ! isset($orderIds[$row['wp_id']])) {
                continue;
            }
            $payments[] = [
                'order_id' => $orderIds[$row['wp_id']],
                'gateway' => $row['payment_method'] ?? 'unknown',
                'reference' => $row['transaction_id'],
                'amount' => $row['total'],
                'status' => $row['status'] === 'refunded' ? 'refunded' : 'succeeded',
                'payload' => json_encode(['source' => $this->paymentSource, 'title' => $row['payment_method_title']], JSON_UNESCAPED_UNICODE),
                'created_at' => $row['paid_at'],
                'updated_at' => $row['paid_at'],
            ];
        }
        $this->upserter->insert('payments', $payments);

        return ['orders' => $orderIds, 'items' => $itemIds, 'payments' => count($payments), 'line_items' => $lineItems];
    }

    /**
     * Refunds of the given orders (+ refunded quantities and orders.refunded_total). The orders' refund rows were
     * removed by write(); every order in $orderIds gets refunded_total 0 unless one of $refunds belongs to it.
     *
     * @param  iterable<WcRefund>  $refunds
     * @param  array<int|string,int>  $orderIds  remote order id => orders.id
     * @param  array<int|string,int>  $itemIds  remote item id => order_items.id
     * @param  array<int|string,int>  $staffByWpId  remote user id => users.id
     */
    public function refunds(iterable $refunds, array $orderIds, array $itemIds, array $staffByWpId, string $now): int
    {
        $rows = [];
        $refundedQty = [];
        $refundedTotals = [];
        foreach ($refunds as $refund) {
            $orderId = $orderIds[$refund->parentId] ?? null;
            if (! $orderId) {
                continue;
            }
            $lines = [];
            foreach ($refund->items as $item) {
                $im = $item->meta;
                $newItem = $itemIds[(int) ($im['_refunded_item_id'] ?? 0)] ?? null;
                $qty = abs((int) ($im['_qty'] ?? 0));
                if ($newItem) {
                    $lines[] = ['order_item_id' => $newItem, 'quantity' => $qty, 'amount' => round(abs((float) ($im['_line_total'] ?? 0)) + abs((float) ($im['_line_tax'] ?? 0)), 2)];
                    $refundedQty[$newItem] = ($refundedQty[$newItem] ?? 0) + $qty;
                }
            }
            $refundedTotals[$orderId] = ($refundedTotals[$orderId] ?? 0) + $refund->amount;
            $rows[] = [
                'order_id' => $orderId,
                'user_id' => $staffByWpId[$refund->refundedBy] ?? null,
                'amount' => $refund->amount,
                'reason' => trim(Formatter::decode($refund->reason)) ?: null,
                'items' => $lines ? json_encode($lines) : null,
                'restock' => false,
                'gateway_refund_id' => null,
                'status' => 'completed',
                'created_at' => $refund->dateGmt ?? $now,
                'updated_at' => $refund->modifiedGmt ?? $now,
            ];
        }
        $this->upserter->insert('refunds', $rows);
        foreach ($refundedQty as $itemId => $qty) {
            DB::table('order_items')->where('id', $itemId)->update(['refunded_quantity' => $qty]);
        }
        foreach (array_chunk(array_values($orderIds), 1000) as $chunk) {
            DB::table('orders')->whereIn('id', $chunk)->update(['refunded_total' => 0]);
        }
        foreach ($refundedTotals as $orderId => $total) {
            DB::table('orders')->where('id', $orderId)->update(['refunded_total' => round($total, 2)]);
        }

        return count($rows);
    }

    /** One order_notes row. */
    public static function noteRow(int $orderId, ?int $userId, string $note, bool $customerNote, bool $system, ?string $dateGmt, string $now): array
    {
        return [
            'order_id' => $orderId,
            'user_id' => $system ? null : $userId,
            'note' => html_entity_decode($note, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'is_customer_note' => $customerNote,
            'is_system' => $system,
            'created_at' => $dateGmt ?? $now,
            'updated_at' => $dateGmt ?? $now,
        ];
    }
}
