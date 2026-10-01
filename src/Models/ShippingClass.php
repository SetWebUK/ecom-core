<?php

namespace Pine\Commerce\Models;

use Illuminate\Database\Eloquent\Model;

/** Groups products that ship alike (e.g. "Bulky", "Small parcel"); flat-rate methods may charge per class. */
class ShippingClass extends Model
{
    protected $guarded = ['id'];

    /** @return array<int, string> id => name */
    public static function options(): array
    {
        try {
            return static::query()->orderBy('name')->pluck('name', 'id')->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
