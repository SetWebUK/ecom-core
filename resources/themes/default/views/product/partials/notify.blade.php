{{-- Back-in-stock alert sign-up (ProductController@notify). --}}
<form class="notify card" id="stock-notify" method="post" action="{{ route('product.notify', $product) }}">
    @csrf
    <p class="notify__title"><strong>Email me when it’s back</strong></p>
    <p class="sr-only"><label for="notify-website">Leave empty</label><input id="notify-website" type="text" name="website" tabindex="-1" autocomplete="off"></p>
    <input type="hidden" name="variation_id" value="" data-notify-variation>
    <div class="inline-form">
        <label class="sr-only" for="notify-email">Email address</label>
        <input id="notify-email" type="email" name="email" required maxlength="190" placeholder="Your email address" autocomplete="email" value="{{ auth()->user()?->email }}">
        <button type="submit" class="btn btn--outline">Notify me</button>
    </div>
</form>
