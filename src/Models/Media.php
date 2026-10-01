<?php

namespace Pine\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Pine\Commerce\Services\Media\Images;

class Media extends Model
{
    protected $table = 'media';

    protected $guarded = ['id'];

    public function getUrlAttribute(): string
    {
        return media_url($this->path);
    }

    /** URL of the best existing variant of a size ('thumbnail', 'card' … or an int N = fit inside N×N). */
    public function sizeUrl(string|int|null $size = null): string
    {
        return media_url($this->path, $size);
    }

    /** srcset of the existing variants with the shape of $size (see image_srcset()). */
    public function srcset(string|int|null $size = null, ?string $format = null): string
    {
        return image_srcset($this->path, $size, $format);
    }

    /**
     * Existing size variants on disk, smallest first.
     *
     * @return list<array{path:string,width:int,height:int}>
     */
    public function variants(): array
    {
        return Images::variants(ltrim((string) $this->path, '/'));
    }
}
