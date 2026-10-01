<?php

namespace Pine\Commerce\Models;

use Pine\Commerce\Commerce;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Cart extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['abandoned_email_sent_at' => 'datetime', 'converted_at' => 'datetime',
        'recovery_stopped_at' => 'datetime', 'recovered_at' => 'datetime', 'destination' => 'array'];

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /** Abandoned-cart reminders sent for this basket (oldest step first). */
    public function recoveryEmails(): HasMany
    {
        return $this->hasMany(CartRecoveryEmail::class)->orderBy('step');
    }

    /** The order a reminder won back (Services\Recovery\AbandonedCartRecovery::markRecovered()). */
    public function recoveredOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'recovered_order_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Commerce::userModel());
    }
}
