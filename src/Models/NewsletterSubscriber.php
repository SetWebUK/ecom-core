<?php

namespace Pine\Commerce\Models;

use Illuminate\Database\Eloquent\Model;

class NewsletterSubscriber extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['unsubscribed_at' => 'datetime'];
}
