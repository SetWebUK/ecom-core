<?php

namespace Pine\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['is_visible' => 'boolean', 'show_in_menu' => 'boolean'];

    protected static function booted(): void
    {
        // Keep the URL path in sync with slug + parent
        static::saving(function (Category $category) {
            $parent = $category->parent_id ? static::find($category->parent_id) : null;
            $category->path = ($parent ? $parent->path.'/' : '').$category->slug;
        });
        static::saved(function (Category $category) {
            if ($category->wasChanged('path')) {
                $category->children->each->save();
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }

    /** IDs of this category and all descendants. */
    public function descendantIds(): array
    {
        $ids = [$this->id];
        foreach ($this->children as $child) {
            $ids = array_merge($ids, $child->descendantIds());
        }

        return $ids;
    }

    /** Ancestors from root to direct parent. */
    public function ancestors(): array
    {
        $chain = [];
        $node = $this->parent;
        while ($node) {
            array_unshift($chain, $node);
            $node = $node->parent;
        }

        return $chain;
    }

    public function getUrlAttribute(): string
    {
        return url($this->path); // SlashUrlGenerator appends the trailing slash
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image ? media_url($this->image) : null;
    }
}
