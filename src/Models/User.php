<?php

namespace Pine\Commerce\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    public const ROLES = [
        'admin' => 'Administrator',
        'manager' => 'Shop manager',
        'customer' => 'Customer',
    ];

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token'];

    protected static function booted(): void
    {
        // a deleted account leaves no password-reset token behind (the broker's table, keyed by email)
        static::deleted(function (self $user) {
            static::forgetPasswordResetTokens((string) $user->email);
        });
    }

    /** Delete the password-reset tokens of an email address (table of the default password broker). */
    public static function forgetPasswordResetTokens(string $email): void
    {
        if ($email === '') {
            return;
        }
        $broker = (string) config('auth.defaults.passwords', 'users');
        $table = (string) config("auth.passwords.{$broker}.table", 'password_reset_tokens');
        $connection = config("auth.passwords.{$broker}.connection");
        try {
            $db = \Illuminate\Support\Facades\DB::connection($connection);
            if ($db->getSchemaBuilder()->hasTable($table)) {
                $db->table($table)->where('email', $email)->delete();
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'marketing_opt_in' => 'boolean',
        ];
    }

    /** Can this user sign in to the /admin back office? */
    public function canAccessAdmin(): bool
    {
        return $this->is_active && in_array($this->role, ['admin', 'manager'], true);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isStaff(): bool
    {
        return in_array($this->role, ['admin', 'manager'], true);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function billingAddress()
    {
        return $this->addresses()->where('type', 'billing')->where('is_default', true)->first();
    }

    public function shippingAddress()
    {
        return $this->addresses()->where('type', 'shipping')->where('is_default', true)->first();
    }

    public function wishlist(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name) ?: $this->name;
    }
}
