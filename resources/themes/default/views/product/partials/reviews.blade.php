{{-- Approved reviews + review form (ProductController@review; reviews are approved by staff). --}}
@php
    $S = \Pine\Commerce\Theme\Storefront::class;
    $avg = $reviews->isNotEmpty() ? round((float) $reviews->avg('rating'), 1) : 0;
@endphp
<section class="product-section reviews" id="reviews">
    <h2 class="section-title">Reviews</h2>
    <div class="reviews__grid">
        <div>
            @if ($reviews->isEmpty())
                <p class="muted">There are no reviews yet. Be the first to review “{{ $product->name }}”.</p>
            @else
                <p class="reviews__summary"><strong>{{ number_format($avg, 1) }}</strong> out of 5 · {{ $reviews->count() }} {{ \Illuminate\Support\Str::plural('review', $reviews->count()) }}</p>
                <ol class="review-list">
                    @foreach ($reviews as $review)
                        <li class="review">
                            <p class="rating" aria-label="Rated {{ $review->rating }} out of 5">@for ($i = 1; $i <= 5; $i++)<span class="rating__star {{ $i <= $review->rating ? 'is-on' : '' }}">{!! $S::icon('star', 14) !!}</span>@endfor</p>
                            <p class="review__meta"><strong>{{ $review->name }}</strong>@if ($review->is_verified_owner) <span class="badge badge--soft">Verified owner</span>@endif · <time datetime="{{ $review->created_at?->toDateString() }}">{{ $review->created_at?->format('j F Y') }}</time></p>
                            <div class="review__text">{!! nl2br(e($review->content)) !!}</div>
                            @if (filled($review->reply))
                                <div class="review__reply"><p class="review__reply-by">Response from {{ setting('store.name', config('app.name')) }}</p><div class="review__reply-text">{!! nl2br(e($review->reply)) !!}</div></div>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
        <form class="form card review-form" method="post" action="{{ route('product.review', $product) }}">
            @csrf
            <h3 class="card__title">Write a review</h3>
            <p class="sr-only"><label for="review-website">Leave empty</label><input id="review-website" type="text" name="website" tabindex="-1" autocomplete="off"></p>
            <fieldset class="star-input">
                <legend>Your rating <span class="req" aria-hidden="true">*</span></legend>
                @for ($i = 5; $i >= 1; $i--)
                    <input type="radio" id="rating-{{ $i }}" name="rating" value="{{ $i }}" @checked((int) old('rating') === $i) required>
                    <label for="rating-{{ $i }}" title="{{ $i }} {{ \Illuminate\Support\Str::plural('star', $i) }}">{!! $S::icon('star', 24) !!}<span class="sr-only">{{ $i }} {{ \Illuminate\Support\Str::plural('star', $i) }}</span></label>
                @endfor
            </fieldset>
            <div class="field"><label for="review-content">Your review <span class="req" aria-hidden="true">*</span></label><textarea id="review-content" name="content" rows="4" required maxlength="5000">{{ old('content') }}</textarea></div>
            <div class="form-grid">
                <div class="field"><label for="review-name">Name <span class="req" aria-hidden="true">*</span></label><input id="review-name" name="name" type="text" required maxlength="100" value="{{ old('name', auth()->user()?->first_name) }}" autocomplete="name"></div>
                <div class="field"><label for="review-email">Email <span class="req" aria-hidden="true">*</span></label><input id="review-email" name="email" type="email" required maxlength="190" value="{{ old('email', auth()->user()?->email) }}" autocomplete="email"></div>
            </div>
            <button type="submit" class="btn btn--primary">Submit review</button>
        </form>
    </div>
</section>
