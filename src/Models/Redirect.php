<?php

namespace Pine\Commerce\Models;

use Illuminate\Database\Eloquent\Model;

class Redirect extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean', 'last_hit_at' => 'datetime'];
}
