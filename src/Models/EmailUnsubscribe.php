<?php

namespace Pine\Commerce\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An address that asked for no more emails of one kind ("list"), e.g. abandoned-cart reminders. Addresses are stored
 * lower-cased. Order/account emails never check this table – they are transactional.
 */
class EmailUnsubscribe extends Model
{
    public const ABANDONED_CART = 'abandoned_cart';

    protected $guarded = ['id'];

    public static function has(string $email, string $list): bool
    {
        return static::query()->where('email', mb_strtolower(trim($email)))->where('list', $list)->exists();
    }

    public static function add(string $email, string $list, ?string $source = null): self
    {
        return static::query()->firstOrCreate(['email' => mb_strtolower(trim($email)), 'list' => $list], ['source' => $source]);
    }
}
