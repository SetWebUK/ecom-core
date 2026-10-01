<?php

namespace Pine\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['options' => 'array', 'unit_price' => 'decimal:2', 'subtotal' => 'decimal:2', 'total' => 'decimal:2', 'tax' => 'decimal:2', 'subtotal_tax' => 'decimal:2', 'taxes' => 'array'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(ProductVariation::class, 'product_variation_id');
    }

    /** Line total after discounts as shown to the customer: with tax ($incl) or without (as stored). */
    public function displayTotal(bool $incl): float
    {
        return round((float) $this->total + ($incl ? (float) $this->tax : 0), 2);
    }

    /** Line total before discounts, with or without tax. */
    public function displaySubtotal(bool $incl): float
    {
        return round((float) $this->subtotal + ($incl ? (float) $this->subtotal_tax : 0), 2);
    }
}
