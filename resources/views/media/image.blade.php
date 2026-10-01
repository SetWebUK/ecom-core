@php($image = $image())
@if ($image['webp'])<picture><source type="image/webp" srcset="{{ $image['webp'] }}"@if ($sizes) sizes="{{ $sizes }}"@endif>@endif<img {{ $attributes->merge($imgAttributes($image)) }}>@if ($image['webp'])</picture>@endif
