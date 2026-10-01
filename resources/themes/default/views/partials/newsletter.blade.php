{{-- Footer newsletter sign-up (ContactController@newsletter). --}}
@php
    $newsErrors = isset($errors) ? $errors->getBag('newsletter') : new \Illuminate\Support\MessageBag;
    $newsStatus = session('newsletter_status');
@endphp
<section class="newsletter" aria-labelledby="newsletter-title">
    <div class="container newsletter__inner">
        <div>
            <h2 class="newsletter__title" id="newsletter-title">{{ setting('store.newsletter_heading', 'Join our newsletter') }}</h2>
            <p class="newsletter__text">{{ setting('store.newsletter_text', 'New arrivals, offers and news – straight to your inbox.') }}</p>
        </div>
        <form class="newsletter__form" method="post" action="{{ route('newsletter.subscribe') }}" data-newsletter id="form-field-email">
            @csrf
            <input type="hidden" name="page_url" value="{{ url()->current() }}">
            <p class="sr-only"><label for="nl-website">Leave this empty</label><input id="nl-website" type="text" name="website" tabindex="-1" autocomplete="off"></p>
            <label class="sr-only" for="newsletter-email">Email address</label>
            <input id="newsletter-email" type="email" name="email" required maxlength="190" placeholder="Your email address" autocomplete="email" value="{{ old('email') }}">
            <button type="submit" class="btn btn--primary">Subscribe</button>
            <p class="newsletter__status" role="status" data-newsletter-status>@if ($newsStatus){{ $newsStatus }}@elseif ($newsErrors->any()){{ $newsErrors->first() }}@endif</p>
        </form>
    </div>
</section>
