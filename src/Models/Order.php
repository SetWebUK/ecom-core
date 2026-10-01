<?php

namespace Pine\Commerce\Models;

use Pine\Commerce\Commerce;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Order extends Model
{
    use SoftDeletes;

    public const STATUSES = [
        'pending' => 'Pending payment',
        'processing' => 'Processing',
        'on-hold' => 'On hold',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'refunded' => 'Refunded',
        'failed' => 'Failed',
    ];

    /** Statuses that count as a sale in reports. */
    public const PAID_STATUSES = ['processing', 'completed', 'on-hold'];

    protected $guarded = ['id'];

    protected $casts = [
        'meta' => 'array',
        'paid_at' => 'datetime',
        'completed_at' => 'datetime',
        'invoice_date' => 'datetime',
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'shipping_total' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'total' => 'decimal:2',
        'refunded_total' => 'decimal:2',
        'shipping_tax' => 'decimal:2',
        'prices_include_tax' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            $order->order_key ??= 'wc_order_'.Str::random(13);
            $order->number ??= static::nextNumber();
        });
    }

    public static function nextNumber(): string
    {
        $max = static::withTrashed()->selectRaw('MAX(CAST(number AS UNSIGNED)) as n')->value('n');

        return (string) max((int) $max + 1, (int) setting('orders.starting_number', 1000));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Commerce::userModel());
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** Tax per rate (v1.1+ orders; older orders have none – use tax_total). */
    public function taxLines(): HasMany
    {
        return $this->hasMany(OrderTaxLine::class)->orderBy('id');
    }

    /**
     * Tax breakdown for receipts: [['label' => 'VAT 20%', 'amount' => 3.33], ...]. Orders without per-rate lines
     * (placed before v1.1 or imported) show one line with the order's tax total under the store's tax label.
     *
     * @return list<array{label: string, rate: ?float, amount: float}>
     */
    public function taxBreakdown(): array
    {
        try {
            $lines = $this->relationLoaded('taxLines') ? $this->taxLines : $this->taxLines()->get();
        } catch (\Throwable) {
            $lines = collect(); // table not migrated yet
        }
        $out = [];
        foreach ($lines as $line) {
            if ($line->amount() != 0.0) {
                $out[] = ['label' => $line->label, 'rate' => $line->rate, 'amount' => $line->amount()];
            }
        }
        if (! $out && (float) $this->tax_total != 0.0) {
            $out[] = ['label' => (string) setting('tax.label', 'VAT'), 'rate' => null, 'amount' => round((float) $this->tax_total, 2)];
        }

        return $out;
    }

    /**
     * Amounts for receipts, shown including or excluding tax (default: Settings › Tax "prices in the basket").
     * Stored amounts are without tax; with $incl the tax of each part is added back.
     *
     * @return array{incl: bool, subtotal: float, discount: float, shipping: float, tax: float, total: float, tax_lines: list<array>}
     */
    public function displayAmounts(?bool $incl = null): array
    {
        $incl ??= \Pine\Commerce\Services\Tax\TaxSettings::displayCartIncl();
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();
        $shippingTax = (float) ($this->shipping_tax ?? 0);
        $itemsTax = round((float) $this->tax_total - $shippingTax, 2);
        $subtotalTax = round((float) $items->sum(fn ($i) => (float) $i->subtotal_tax), 2) ?: round((float) $items->sum(fn ($i) => (float) $i->tax), 2);
        $subtotal = (float) $this->subtotal + ($incl ? $subtotalTax : 0);
        $itemsTotal = (float) $this->subtotal - (float) $this->discount_total + ($incl ? $itemsTax : 0);

        return [
            'incl' => $incl && (float) $this->tax_total != 0.0,
            'subtotal' => round($subtotal, 2),
            'discount' => round(max(0, $subtotal - $itemsTotal), 2),
            'shipping' => round((float) $this->shipping_total + ($incl ? $shippingTax : 0), 2),
            'tax' => round((float) $this->tax_total, 2),
            'total' => round((float) $this->total, 2),
            'tax_lines' => $this->taxBreakdown(),
        ];
    }

    /** Were this order's prices entered with tax included (the store setting when it was placed)? */
    public function pricesIncludeTax(): bool
    {
        return (bool) ($this->prices_include_tax ?? (($this->meta['prices_include_tax'] ?? 'no') === 'yes'));
    }

    public function notes(): HasMany
    {
        return $this->hasMany(OrderNote::class)->latest();
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? Str::headline($this->status);
    }

    public function getBillingNameAttribute(): string
    {
        return trim($this->billing_first_name.' '.$this->billing_last_name);
    }

    public function getShippingNameAttribute(): string
    {
        return trim($this->shipping_first_name.' '.$this->shipping_last_name);
    }

    public function billingAddressLines(): array
    {
        return array_values(array_filter([
            $this->billing_name, $this->billing_company, $this->billing_address_1, $this->billing_address_2,
            $this->billing_city, $this->billing_county, $this->billing_postcode, $this->billing_country,
        ]));
    }

    public function shippingAddressLines(): array
    {
        return array_values(array_filter([
            $this->shipping_name, $this->shipping_company, $this->shipping_address_1, $this->shipping_address_2,
            $this->shipping_city, $this->shipping_county, $this->shipping_postcode, $this->shipping_country,
        ]));
    }

    public function addNote(string $note, bool $customer = false, bool $system = true): OrderNote
    {
        return $this->notes()->create([
            'note' => $note,
            'is_customer_note' => $customer,
            'is_system' => $system,
            'user_id' => auth()->id(),
        ]);
    }

    /** Change status, record a note and fire the OrderStatusChanged event (emails hook into this). */
    public function updateStatus(string $status, ?string $note = null): void
    {
        $old = $this->status;
        if ($old === $status) {
            return;
        }
        $this->status = $status;
        if ($status === 'completed' && ! $this->completed_at) {
            $this->completed_at = now();
        }
        if (in_array($status, ['processing', 'completed'], true) && ! $this->paid_at) {
            $this->paid_at = now();
        }
        $this->save();
        $this->addNote(trim(($note ? $note.' ' : '').'Order status changed from '.(self::STATUSES[$old] ?? $old).' to '.(self::STATUSES[$status] ?? $status).'.'));
        event(new \Pine\Commerce\Events\OrderStatusChanged($this, $old, $status));
    }

    public function isPaid(): bool
    {
        return in_array($this->status, ['processing', 'completed'], true);
    }

    public function getViewUrlAttribute(): string
    {
        return route('checkout.thankyou', ['order' => $this->number, 'key' => $this->order_key]);
    }
}
