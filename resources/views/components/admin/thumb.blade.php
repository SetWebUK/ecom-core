{{--
    <x-admin.thumb :src="$product->images->first()?->path" :alt="$product->name" />
    Props: src (public-disk path like uploads/2024/05/x.jpg, or absolute URL), alt, size (xs|sm|md|lg|xl), icon (placeholder), cover
--}}
@props(['src' => null, 'alt' => '', 'size' => null, 'icon' => 'photo', 'cover' => false])
<span {{ $attributes->class(['thumb', 'thumb--'.$size => $size && $size !== 'md', 'thumb--cover' => $cover]) }}>
    @if ($src)
        {{-- smallest existing proportional size that stays sharp at 2× in the largest (120px) thumb, else the original --}}
        <img src="{{ media_url($src, 240) }}" alt="{{ $alt }}" loading="lazy" decoding="async">
    @else
        <x-admin.icon :name="$icon" />
    @endif
</span>
