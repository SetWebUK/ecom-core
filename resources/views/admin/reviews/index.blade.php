{{-- Product reviews: moderation queue (waiting / approved / all), approve, hide, reply, delete, bulk actions. --}}
@extends('commerce::admin.layouts.app', ['width' => 'wide'])

@section('title', 'Reviews')

@section('content')
    @include('commerce::admin.products.partials.assets')

    <x-admin.page-header title="Reviews" subtitle="Customer reviews only appear on the product page once you approve them." />

    @if ($tabs['all']['count'] === 0)
        <div class="card">
            <x-admin.empty icon="star" title="No reviews yet" description="When customers review a product, it waits here for you to approve it before it shows in the shop." />
        </div>
    @else
        <x-admin.card flush>
            <x-admin.status-tabs :tabs="$tabs" :current="$status" />
            <x-admin.filters placeholder="Search by name, email, product or text" :chips="$chips" keep="status">
                <x-admin.filter-select name="rating" :options="[5 => '5 stars', 4 => '4 stars', 3 => '3 stars', 2 => '2 stars', 1 => '1 star']" placeholder="Any rating" label="Rating" />
            </x-admin.filters>

            @if ($reviews->isEmpty())
                @if ($status === 'pending' && ! request()->hasAny(['q', 'rating']))
                    <x-admin.empty icon="check-circle" title="All caught up" description="There are no reviews waiting for approval." size="sm">
                        <x-admin.button :href="route('admin.reviews.index', ['status' => 'all'])">See all reviews</x-admin.button>
                    </x-admin.empty>
                @else
                    <x-admin.empty icon="magnifying-glass" title="No reviews match" description="Try a different search or filter." size="sm">
                        <x-admin.button :href="route('admin.reviews.index', ['status' => $status])">Clear filters</x-admin.button>
                    </x-admin.empty>
                @endif
            @else
                <div x-data="bulkTable(@js($reviews->pluck('id')->map(fn ($id) => (string) $id)))" :class="{ 'has-bulk': count > 0 }">
                    <form class="bulk-bar" method="POST" action="{{ route('admin.reviews.bulk') }}" x-show="count > 0" x-cloak>
                        @csrf
                        <label class="bulk-bar__count">
                            <input type="checkbox" class="checkbox" :checked="all" x-effect="$el.indeterminate = some" @change="toggleAll()" aria-label="Select all on this page">
                            <span x-text="count + ' selected'"></span>
                        </label>
                        <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
                        <x-admin.button type="submit" name="action" value="approve" size="sm" icon="check">Approve</x-admin.button>
                        <x-admin.button type="submit" name="action" value="unapprove" size="sm" icon="eye-slash">Hide</x-admin.button>
                        <x-admin.button type="submit" name="action" value="delete" size="sm" variant="ghost-danger" icon="trash"
                                        data-confirm-title="Delete the selected reviews?" data-confirm="They’re removed for good and the products’ star ratings are recalculated." data-confirm-button="Delete reviews">Delete</x-admin.button>
                        <span class="bulk-bar__spacer"></span>
                        <button type="button" class="btn btn--ghost btn--sm" @click="clear()"><span>Clear selection</span></button>
                    </form>

                    @foreach ($reviews as $review)
                        @php $product = $review->product; @endphp
                        <article class="review" x-data="{ replying: @js($errors->getBag('reply'.$review->id)->isNotEmpty()) }">
                            <div class="table__check" style="padding:0">
                                <input type="checkbox" class="checkbox" :checked="isSelected('{{ $review->id }}')" @click="toggle('{{ $review->id }}', $event)" aria-label="Select review by {{ $review->name }}">
                            </div>
                            <div>
                                <div class="review__head">
                                    <span class="stars" role="img" aria-label="{{ $review->rating }} out of 5 stars">
                                        @for ($i = 1; $i <= 5; $i++)<x-admin.icon name="star" variant="mini" @class(['is-empty' => $i > $review->rating]) />@endfor
                                    </span>
                                    <strong>{{ $review->name }}</strong>
                                    @if ($review->is_verified_owner)<x-admin.badge size="sm" color="success" icon="check-badge">Verified buyer</x-admin.badge>@endif
                                    @if ($review->is_approved)
                                        <x-admin.badge size="sm" color="success" dot>Approved</x-admin.badge>
                                    @else
                                        <x-admin.badge size="sm" color="attention" dot>Waiting for approval</x-admin.badge>
                                    @endif
                                    <span class="text-xs text-muted"><x-admin.time :value="$review->created_at" /></span>
                                </div>
                                <div class="text-xs text-muted mt-1">
                                    @if ($review->email)<a href="mailto:{{ $review->email }}">{{ $review->email }}</a> · @endif
                                    @if ($product && ! $product->trashed())
                                        on <a href="{{ route('admin.products.edit', $product) }}">{{ $product->name }}</a>
                                        <a href="{{ $product->url }}" target="_blank" rel="noopener" class="text-muted" aria-label="View product in the shop"><x-admin.icon name="arrow-top-right-on-square" size="xs" style="display:inline;vertical-align:-2px" /></a>
                                    @else
                                        <span class="text-subtle">on {{ $product?->name ?? 'a product' }} (deleted)</span>
                                    @endif
                                </div>
                                @if ($review->content)
                                    <p class="review__text">{{ $review->content }}</p>
                                @else
                                    <p class="review__text text-subtle">(No written review – star rating only.)</p>
                                @endif

                                @if ($hasReply && $review->reply)
                                    <div class="review__reply" x-show="!replying">
                                        <div class="text-xs text-muted mb-2">Your reply · <x-admin.time :value="$review->replied_at" /></div>
                                        {{ $review->reply }}
                                    </div>
                                @endif

                                @if ($hasReply)
                                    <form method="POST" action="{{ route('admin.reviews.reply', $review) }}" class="mt-3" x-show="replying" x-cloak>
                                        @csrf
                                        @method('PUT')
                                        <x-admin.textarea name="reply" :id="'reply-'.$review->id" label="Public reply" rows="3" counter="5000" :value="$review->reply" :error="'reply'"
                                                          help="Shown under the review on the product page. Leave empty and save to remove your reply." />
                                        @if ($errors->getBag('reply'.$review->id)->has('reply'))
                                            <p class="field__error">{{ $errors->getBag('reply'.$review->id)->first('reply') }}</p>
                                        @endif
                                        <div class="row mt-2">
                                            <x-admin.button type="submit" size="sm" variant="primary">Save reply</x-admin.button>
                                            <x-admin.button size="sm" x-on:click="replying = false">Cancel</x-admin.button>
                                        </div>
                                    </form>
                                @endif

                                <div class="review__actions" x-show="!replying">
                                    @if ($review->is_approved)
                                        <x-admin.confirm :action="route('admin.reviews.unapprove', $review)" method="POST" size="sm" icon="eye-slash" :danger="false"
                                                         title="Hide this review?" message="It disappears from the product page until you approve it again." confirm-label="Hide review">Hide</x-admin.confirm>
                                    @else
                                        <form method="POST" action="{{ route('admin.reviews.approve', $review) }}" style="display:contents">
                                            @csrf
                                            <x-admin.button type="submit" size="sm" variant="primary" icon="check">Approve</x-admin.button>
                                        </form>
                                    @endif
                                    @if ($hasReply)
                                        <x-admin.button size="sm" icon="chat-bubble-left" x-on:click="replying = true; $nextTick(() => $root.querySelector('textarea').focus())">{{ $review->reply ? 'Edit reply' : 'Reply' }}</x-admin.button>
                                    @endif
                                    <x-admin.confirm :action="route('admin.reviews.destroy', $review)" size="sm" variant="ghost-danger" icon="trash"
                                                     title="Delete this review?" message="It’s removed for good and the product’s star rating is recalculated." confirm-label="Delete review">Delete</x-admin.confirm>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
                <x-admin.pagination :paginator="$reviews" />
            @endif
        </x-admin.card>
    @endif
@endsection
