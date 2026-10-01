<?php

namespace Pine\Commerce\Models;

use Pine\Commerce\Commerce;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['items' => 'array', 'restock' => 'boolean', 'amount' => 'decimal:2'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Commerce::userModel());
    }
}
