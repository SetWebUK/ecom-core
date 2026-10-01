<?php

namespace Pine\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Menu extends Model
{
    protected $guarded = ['id'];

    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class)->orderBy('sort_order');
    }

    public function rootItems(): HasMany
    {
        return $this->items()->whereNull('parent_id')->with('children.children');
    }

    /** Cached tree of a menu by location, for front-end rendering. */
    public static function tree(string $location)
    {
        // Memoised per request only: Laravel 13's cache store refuses to unserialize Eloquent objects
        // (config/cache.php serializable_classes = false), so a cached collection came back as
        // __PHP_Incomplete_Class. The storefront nav (Pine\Commerce\View\Components\MenuComponent) caches plain arrays.
        static $trees = [];

        return $trees[$location] ??= static::where('location', $location)->first()?->rootItems()->get() ?? collect();
    }

    protected static function booted(): void
    {
        static::saved(fn ($menu) => cache()->forget('menu.'.$menu->location));
    }
}
