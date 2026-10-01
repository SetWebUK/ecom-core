<?php

namespace Pine\Commerce\Models;

use Illuminate\Database\Eloquent\Model;

class FormSubmission extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['data' => 'array', 'read_at' => 'datetime'];
}
