<?php

namespace Pine\Commerce\Http\Requests\Admin\Sales;

use Pine\Commerce\Models\Order;
use Pine\Commerce\Services\Admin\OrderManager;
use Illuminate\Validation\Validator;

/**
 * Refund form (order page modal): quantities per line (lines[order_item_id] = qty), a shipping refund, the total to
 * refund (pre-filled from the lines + shipping but editable for a custom amount), a reason, restock, and how:
 * method=gateway (send the money back through Stripe/PayPal) or method=manual (just record it).
 * Errors go to the "refund" bag so the modal re-opens.
 */
class RefundRequest extends SalesRequest
{
    protected $errorBag = 'refund';

    protected array $moneyFields = ['amount', 'shipping'];

    public function rules(): array
    {
        return [
            'lines' => ['nullable', 'array', 'max:200'],
            'lines.*' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'shipping' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999.99'],
            'reason' => ['nullable', 'string', 'max:500'],
            'restock' => ['boolean'],
            'method' => ['required', 'in:gateway,manual'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Enter the amount to refund.',
            'amount.gt' => 'The refund amount must be more than £0.00.',
            'amount.decimal' => 'Use at most 2 decimal places.',
            'lines.*.integer' => 'Quantities must be whole numbers.',
        ];
    }

    public function attributes(): array
    {
        return ['amount' => 'refund amount', 'shipping' => 'shipping refund'];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $order = $this->order();
            if (! $order || $validator->errors()->isNotEmpty()) {
                return;
            }
            $refundable = OrderManager::refundableAmount($order);
            if ((float) $this->input('amount') > $refundable + 0.001) {
                $validator->errors()->add('amount', 'You can refund at most '.money($refundable).'.');
            }
            $items = $order->items->keyBy('id');
            foreach ((array) $this->input('lines', []) as $id => $qty) {
                $item = $items->get((int) $id);
                if ((int) $qty > 0 && ! $item) {
                    $validator->errors()->add('lines', 'One of the items is no longer on this order – reload the page.');
                    break;
                }
                if ($item && (int) $qty > $item->quantity - $item->refunded_quantity) {
                    $validator->errors()->add('lines.'.$id, 'Only '.($item->quantity - $item->refunded_quantity).' of “'.$item->name.'” can still be refunded.');
                }
            }
            $shippingLeft = max(0, round((float) $order->shipping_total - OrderManager::refundedShipping($order), 2));
            if ((float) $this->input('shipping') > $shippingLeft + 0.001) {
                $validator->errors()->add('shipping', 'You can refund at most '.money($shippingLeft).' of shipping.');
            }
            if ($this->input('method') === 'gateway' && ! OrderManager::gatewayCanRefund($order)) {
                $validator->errors()->add('method', 'This payment method can’t be refunded automatically. Refund the customer in the payment provider’s dashboard, then record a manual refund.');
            }
        }];
    }

    public function order(): ?Order
    {
        $order = $this->route('order');

        return $order instanceof Order ? $order->loadMissing('items', 'refunds') : null;
    }

    /** @return list<array{order_item_id:int, quantity:int, amount:float}> amounts from each line's (discounted) unit price */
    public function refundLines(): array
    {
        $items = $this->order()->items->keyBy('id');
        $lines = [];
        foreach ((array) $this->input('lines', []) as $id => $qty) {
            $item = $items->get((int) $id);
            if (! $item || (int) $qty <= 0) {
                continue;
            }
            $unit = $item->quantity > 0 ? ((float) $item->total + (float) $item->tax) / $item->quantity : 0; // what the customer paid, tax included
            $lines[] = ['order_item_id' => $item->id, 'quantity' => (int) $qty, 'amount' => round($unit * (int) $qty, 2)];
        }

        return $lines;
    }

    public function amount(): float
    {
        return round((float) $this->input('amount'), 2);
    }

    public function shipping(): float
    {
        return round((float) $this->input('shipping', 0), 2);
    }

    public function viaGateway(): bool
    {
        return $this->input('method') === 'gateway';
    }

    public function reason(): ?string
    {
        return $this->nullableString('reason');
    }
}
