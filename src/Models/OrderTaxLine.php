<?php

namespace Pine\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tax charged on an order for one rate ("VAT 20%"): on the items and on shipping. */
class OrderTaxLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['rate' => 'float', 'compound' => 'boolean', 'tax_total' => 'decimal:2', 'shipping_tax_total' => 'decimal:2'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function amount(): float
    {
        return round((float) $this->tax_total + (float) $this->shipping_tax_total, 2);
    }
}
