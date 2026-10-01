<?php

namespace Pine\Commerce\Models;

use Pine\Commerce\Commerce;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Post extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['published_at' => 'datetime'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(PostCategory::class, 'post_category_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Commerce::userModel(), 'author_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    public function getUrlAttribute(): string
    {
        return url('blog/'.$this->slug); // SlashUrlGenerator appends the trailing slash
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->featured_image ? media_url($this->featured_image) : null;
    }
}
