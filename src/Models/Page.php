<?php

namespace Pine\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Page extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['blocks' => 'array', 'noindex' => 'boolean'];

    protected static function booted(): void
    {
        static::saving(function (Page $page) {
            if ($page->template === 'home') {
                $page->path = '';

                return;
            }
            $parent = $page->parent_id ? static::find($page->parent_id) : null;
            $page->path = ($parent && $parent->path !== '' ? $parent->path.'/' : '').$page->slug;
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Page::class, 'parent_id');
    }

    public function getUrlAttribute(): string
    {
        return $this->path === '' ? url('/') : url($this->path); // SlashUrlGenerator appends the trailing slash
    }

    /** Read a structured block value, e.g. $page->block('hero.title'). */
    public function block(string $key, $default = null)
    {
        return data_get($this->blocks, $key, $default);
    }
}
