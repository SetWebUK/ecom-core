@extends('account.frame')

@section('account_content')
<h1 class="page-title">Wishlist</h1>
@php $products = $items->map->product->filter(); @endphp
@if ($products->isEmpty())
    <div class="empty"><p class="empty__title">Your wishlist is empty</p><p class="muted">Tap the heart on any product to save it here.</p><a class="btn btn--primary" href="{{ url('shop') }}/">Browse products</a></div>
@else
    <div class="product-grid product-grid--3">
        @foreach ($products as $product)
            <div class="wishlist-item">
                @include('partials.product-card', ['product' => $product])
                <form method="post" action="{{ route('wishlist.toggle') }}">
                    @csrf
                    <input type="hidden" name="product_id" value="{{ $product->id }}">
                    <button type="submit" class="btn btn--ghost btn--sm btn--block">Remove</button>
                </form>
            </div>
        @endforeach
    </div>
@endif
@endsection
