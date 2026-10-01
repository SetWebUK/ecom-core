{{-- Product gallery: main image with thumbnails (scroll-snap on mobile). $product --}}
@php
    $P = commerce_presenter();
    $images = $product->images->values();
@endphp
<div class="gallery" data-gallery>
    <div class="gallery__main" data-gallery-track>
        @forelse ($images as $i => $image)
            <figure class="gallery__slide" id="gallery-{{ $i }}">
                <a href="{{ media_url($image->path) }}" data-gallery-zoom>
                    <x-media-image :path="$image->path" size="large" sizes="(min-width: 900px) 50vw, 100vw" :alt="$image->alt ?: $product->name" :priority="$i === 0" data-gallery-image />
                </a>
            </figure>
        @empty
            <figure class="gallery__slide"><img src="{{ media_url(null) }}" alt="" data-gallery-image></figure>
        @endforelse
    </div>
    @if ($images->count() > 1)
        <div class="gallery__thumbs" role="tablist" aria-label="Product images">
            @foreach ($images as $i => $image)
                <button type="button" class="gallery__thumb {{ $i === 0 ? 'is-active' : '' }}" role="tab" aria-selected="{{ $i === 0 ? 'true' : 'false' }}" aria-label="Image {{ $i + 1 }} of {{ $images->count() }}" data-gallery-thumb="{{ $i }}">
                    <x-media-image :path="$image->path" size="thumbnail" sizes="80px" :width="80" :height="80" :picture="false" />
                </button>
            @endforeach
        </div>
    @endif
</div>
