<?php

namespace Pine\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $guarded = ['id'];

    /** Per-request copy of the settings cache (setting() is called many times per page). */
    protected static ?array $memo = null;

    public static function allCached(): array
    {
        return static::$memo ??= Cache::rememberForever('settings.all', fn () => static::pluck('value', 'key')->all());
    }

    public static function flushMemo(): void
    {
        static::$memo = null;
    }

    public static function get(string $key, $default = null)
    {
        $value = static::allCached()[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        $decoded = json_decode($value, true);

        return (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_bool($decoded))) ? $decoded : $value;
    }

    public static function set(string $key, $value, ?string $group = null): void
    {
        static::updateOrCreate(
            ['key' => $key],
            array_filter([
                'value' => is_array($value) || is_bool($value) ? json_encode($value) : $value,
                'group' => $group ?? explode('.', $key)[0],
            ], fn ($v) => $v !== null)
        );
        Cache::forget('settings.all');
        static::$memo = null;
    }

    protected static function booted(): void
    {
        static::saved(function () {
            Cache::forget('settings.all');
            static::$memo = null;
        });
        static::deleted(function () {
            Cache::forget('settings.all');
            static::$memo = null;
        });
    }
}
