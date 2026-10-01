<?php

namespace Pine\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One abandoned-cart reminder that was sent (Pine\Commerce\Services\Recovery\AbandonedCartRecovery). */
class CartRecoveryEmail extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['sent_at' => 'datetime', 'clicked_at' => 'datetime', 'step' => 'integer', 'clicks' => 'integer'];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }
}
