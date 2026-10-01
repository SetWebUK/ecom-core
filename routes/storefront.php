<?php

use Pine\Commerce\Http\Controllers\AccountController;
use Pine\Commerce\Http\Controllers\Auth\AuthController;
use Pine\Commerce\Http\Controllers\BlogController;
use Pine\Commerce\Http\Controllers\CartController;
use Pine\Commerce\Http\Controllers\CartRecoveryController;
use Pine\Commerce\Http\Controllers\CatalogController;
use Pine\Commerce\Http\Controllers\CheckoutController;
use Pine\Commerce\Http\Controllers\ContactController;
use Pine\Commerce\Http\Controllers\FeedController;
use Pine\Commerce\Http\Controllers\HomeController;
use Pine\Commerce\Http\Controllers\InvoiceController;
use Pine\Commerce\Http\Controllers\PaymentWebhookController;
use Pine\Commerce\Http\Controllers\ProductController;
use Pine\Commerce\Http\Controllers\ResolveController;
use Pine\Commerce\Http\Controllers\SearchController;
use Pine\Commerce\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;
use Pine\Commerce\Http\Middleware\RequireFeature;

/*
|--------------------------------------------------------------------------
| Storefront routes  (pine/commerce – loaded by CommerceServiceProvider in the 'web' group)
|--------------------------------------------------------------------------
| URLs mirror the legacy WordPress/WooCommerce site exactly (trailing slashes,
| /{category-path}/{product-slug}/ product URLs, /blog/{slug}/ posts, etc.).
| Anything not matched explicitly falls through to ResolveController, which
| looks up pages, categories, products and redirects by path.
*/

// Feature switches (config commerce.features.*, Pine\Commerce\Support\Features): a switched-off feature's routes keep
// their names (theme views can still build URLs, route:cache stays valid) but answer 404 – RequireFeature::for('blog').

Route::get('/', [HomeController::class, 'index'])->name('home'); // also handles legacy /?s=term search

// Catalogue
Route::get('shop', [CatalogController::class, 'shop'])->name('shop');
Route::get('shop/page/{page}', [CatalogController::class, 'shop'])->whereNumber('page')->name('shop.page');
Route::get('search', [SearchController::class, 'index'])->name('search');
Route::get('ajax/search', [SearchController::class, 'suggest'])->middleware('throttle:120,1,search-suggest')->name('search.suggest');
Route::post('product/{product}/review', [ProductController::class, 'review'])->middleware(RequireFeature::for('reviews'))->name('product.review');
Route::post('product/{product}/notify', [ProductController::class, 'notify'])->middleware(RequireFeature::for('stock_alerts'))->name('product.notify');
Route::get('product/{product}/quick-view', [ProductController::class, 'quickView'])->middleware(RequireFeature::for('quick_view'))->name('product.quick-view');

// Blog
Route::middleware(RequireFeature::for('blog'))->group(function () {
    Route::get('blog', [BlogController::class, 'index'])->name('blog.index');
    Route::get('blog/page/{page}', [BlogController::class, 'index'])->whereNumber('page')->name('blog.page');
    Route::get('blog/category/{slug}', [BlogController::class, 'category'])->name('blog.category');
    Route::get('blog/category/{slug}/page/{page}', [BlogController::class, 'category'])->whereNumber('page')->name('blog.category.page');
    Route::get('blog/{slug}', [BlogController::class, 'show'])->name('blog.show');
});

// Basket (legacy WP page slug "basket" – redirects to checkout when not empty, Shopify-style)
Route::get('basket', [CartController::class, 'show'])->name('cart');
Route::post('cart/add', [CartController::class, 'add'])->name('cart.add');
Route::post('cart/update', [CartController::class, 'update'])->name('cart.update');
Route::post('cart/remove', [CartController::class, 'remove'])->name('cart.remove');
Route::post('cart/coupon', [CartController::class, 'applyCoupon'])->middleware([RequireFeature::for('coupons'), 'throttle:10,1,coupon'])->name('cart.coupon'); // no coupon-code guessing
Route::delete('cart/coupon', [CartController::class, 'removeCoupon'])->middleware(RequireFeature::for('coupons'))->name('cart.coupon.remove');
Route::get('cart/fragment', [CartController::class, 'fragment'])->name('cart.fragment'); // side-cart HTML + count JSON
// Abandoned-cart reminder links (signed; CartRecoveryController). The unsubscribe POST is CSRF-exempt (RFC 8058 one-click).
Route::get('basket/restore/{cart}', [CartRecoveryController::class, 'restore'])->whereNumber('cart')
    ->middleware([RequireFeature::for('abandoned_carts'), 'signed', 'throttle:30,1,cart-recover'])->name('cart.recover');
Route::match(['get', 'post'], 'basket/unsubscribe/{cart}', [CartRecoveryController::class, 'unsubscribe'])->whereNumber('cart')
    ->middleware(['signed', 'throttle:30,1,cart-unsubscribe'])->name('cart.recover.unsubscribe');

// Checkout
Route::get('checkout', [CheckoutController::class, 'show'])->name('checkout');
Route::post('checkout', [CheckoutController::class, 'placeOrder'])->middleware('throttle:20,1,checkout')->name('checkout.place'); // card-testing guard
Route::post('checkout/update', [CheckoutController::class, 'update'])->name('checkout.update'); // shipping method / totals refresh
Route::get('checkout/order-received/{order}', [CheckoutController::class, 'thankYou'])->name('checkout.thankyou');
Route::post('checkout/order-received/{order}', [CheckoutController::class, 'verifyOrderEmail'])->middleware('throttle:10,1,order-verify')->name('checkout.thankyou.verify');
Route::get('checkout/order-received/{order}/invoice', [InvoiceController::class, 'guest'])->middleware('throttle:30,1,invoice')->name('checkout.invoice'); // PDF, order key
Route::get('checkout/order-pay/{order}', [CheckoutController::class, 'pay'])->name('checkout.pay');
Route::post('checkout/order-pay/{order}', [CheckoutController::class, 'payOrder'])->middleware('throttle:20,1,order-pay')->name('checkout.pay.submit');
Route::get('checkout/payment/{gateway}/return', [CheckoutController::class, 'paymentReturn'])->name('checkout.payment.return');
Route::get('checkout/payment/{gateway}/cancel', [CheckoutController::class, 'paymentCancel'])->name('checkout.payment.cancel');
Route::post('webhooks/{gateway}', [PaymentWebhookController::class, 'handle'])->name('webhooks.payment');

// Customer account (WooCommerce "my-account" endpoints)
Route::get('my-account', [AccountController::class, 'dashboard'])->name('account');
Route::post('my-account/login', [AuthController::class, 'login'])->name('login.attempt');
Route::post('my-account/register', [AuthController::class, 'register'])->middleware(RequireFeature::for('registration'))->name('register');
Route::post('my-account/logout', [AuthController::class, 'logout'])->name('logout');
Route::get('my-account/customer-logout', [AuthController::class, 'logout'])->name('logout.get');
Route::get('my-account/lost-password', [AuthController::class, 'showForgot'])->name('password.request');
Route::post('my-account/lost-password', [AuthController::class, 'sendReset'])->name('password.email');
Route::get('my-account/reset-password/{token}', [AuthController::class, 'showReset'])->name('password.reset');
Route::post('my-account/reset-password', [AuthController::class, 'reset'])->middleware('throttle:10,1,password-reset')->name('password.update');
Route::middleware('auth')->group(function () {
    Route::get('my-account/orders', [AccountController::class, 'orders'])->name('account.orders');
    Route::get('my-account/orders/{page}', [AccountController::class, 'orders'])->whereNumber('page')->name('account.orders.page');
    Route::get('my-account/view-order/{number}', [AccountController::class, 'viewOrder'])->name('account.order');
    Route::get('my-account/view-order/{number}/invoice', [InvoiceController::class, 'account'])->middleware('throttle:30,1,invoice')->name('account.order.invoice'); // PDF
    Route::get('my-account/edit-address', [AccountController::class, 'addresses'])->name('account.addresses');
    Route::get('my-account/edit-address/{type}', [AccountController::class, 'editAddress'])->whereIn('type', ['billing', 'shipping'])->name('account.address.edit');
    Route::post('my-account/edit-address/{type}', [AccountController::class, 'saveAddress'])->whereIn('type', ['billing', 'shipping'])->name('account.address.save');
    Route::get('my-account/edit-account', [AccountController::class, 'details'])->name('account.details');
    Route::post('my-account/edit-account', [AccountController::class, 'saveDetails'])->name('account.details.save');
    Route::get('my-account/wishlist', [AccountController::class, 'wishlist'])->middleware(RequireFeature::for('wishlist'))->name('account.wishlist');
    Route::get('my-account/downloads', [AccountController::class, 'downloads'])->name('account.downloads');
});
Route::post('wishlist/toggle', [AccountController::class, 'toggleWishlist'])->middleware(RequireFeature::for('wishlist'))->name('wishlist.toggle');

// Forms
Route::post('contact', [ContactController::class, 'submit'])->middleware([RequireFeature::for('contact_form'), 'throttle:10,1,contact'])->name('contact.submit');
Route::post('newsletter', [ContactController::class, 'newsletter'])->middleware([RequireFeature::for('newsletter'), 'throttle:10,1,newsletter'])->name('newsletter.subscribe');
Route::post('order-tracking', [ContactController::class, 'trackOrder'])->middleware([RequireFeature::for('order_tracking'), 'throttle:10,1,order-tracking'])->name('order.track'); // [woocommerce_order_tracking] form on /orders-and-returns/

// SEO / feeds
Route::get('sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('sitemap_index.xml', [SitemapController::class, 'index']);
Route::get('robots.txt', [SitemapController::class, 'robots'])->name('robots');
Route::get('feeds/google-shopping.xml', [FeedController::class, 'google'])->middleware(RequireFeature::for('google_feed'))->name('feed.google');
Route::get('feed', [BlogController::class, 'feed'])->middleware(RequireFeature::for('blog'))->name('feed.posts'); // legacy WordPress posts RSS feed

// Catch-all: pages, product categories (+ /page/N), products, legacy redirects
// Registered as a *fallback* route, so it always matches after every other route (package, theme or client
// routes/web.php) whatever order they were registered in. Never matches the back office or published asset dirs.
Route::get('{path}', [ResolveController::class, 'resolve'])
    ->where('path', '^(?!'.preg_quote(trim((string) config('commerce.admin.path', 'admin'), '/'), '#').'|storage|css|js|build|vendor|themes).*$')
    ->name('resolve')->fallback();
