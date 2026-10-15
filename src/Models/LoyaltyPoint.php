<?php

namespace Pine\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One change to a customer's loyalty points (Services\Loyalty\Loyalty). */
class LoyaltyPoint extends Model
{
    public const TYPES = [
        'signup' => 'Welcome bonus',
        'earn' => 'Earned on an order',
        'redeem' => 'Spent at checkout',
        'refund' => 'Given back (order cancelled)',
        'reverse' => 'Taken back (order cancelled or refunded)',
        'adjust' => 'Adjusted by the shop',
        'import' => 'Balance from the old website',
    ];

    protected $guarded = ['id'];

    protected $casts = ['points' => 'integer'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function label(): string
    {
        return self::TYPES[$this->type] ?? ucfirst((string) $this->type);
    }
}
