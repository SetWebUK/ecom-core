<?php

use Pine\Commerce\Http\Controllers\Admin\CouponController;
use Pine\Commerce\Http\Controllers\Admin\DashboardController;
use Pine\Commerce\Http\Controllers\Admin\MediaUploadController;
use Pine\Commerce\Http\Controllers\Admin\ProfileController;
use Pine\Commerce\Http\Controllers\Admin\SearchController;
use Pine\Commerce\Http\Controllers\Admin\UpdateController;
use Pine\Commerce\Http\Controllers\Admin\WooApiImportController;
use Illuminate\Support\Facades\Route;
use Pine\Commerce\Http\Middleware\RequireFeature;

/*
|--------------------------------------------------------------------------
| Core back office   prefix /admin, names admin.*, middleware web + admin (staff)
|--------------------------------------------------------------------------
| Home, global search, JSON lookups for the picker components, media upload, discounts and the profile page.
| JSON endpoints live under /admin/api/… so they never clash with an area's resource routes.
*/

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

// Global search (top bar) + lookups used by <x-admin.product-picker>, <x-admin.category-picker>, <x-admin.customer-picker>
Route::get('search', [SearchController::class, 'index'])->name('search');
Route::prefix('api')->middleware('throttle:240,1')->group(function () {
    Route::get('search', [SearchController::class, 'suggest'])->name('search.suggest');
    Route::get('products', [SearchController::class, 'products'])->name('api.products');
    Route::get('categories', [SearchController::class, 'categories'])->name('api.categories');
    Route::get('customers', [SearchController::class, 'customers'])->name('api.customers');
    Route::get('media', [MediaUploadController::class, 'index'])->name('api.media');
});

// Image upload for <x-admin.image-picker> and the rich-text editor
Route::post('media/upload', [MediaUploadController::class, 'store'])->middleware('throttle:60,1')->name('media.upload');

// Discounts (feature switch "coupons")
Route::middleware(RequireFeature::for('coupons'))->group(function () {
    Route::post('coupons/bulk', [CouponController::class, 'bulk'])->name('coupons.bulk');
    Route::resource('coupons', CouponController::class)->except('show');
});

// Your profile
Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
Route::put('profile/password', [ProfileController::class, 'password'])->middleware('throttle:10,1')->name('profile.password');

// Updates (administrators only, feature switch "updater"): check, approve & install pine/commerce releases, skeleton
// files, audit history. The status endpoint is polled by the run page while an update runs in the background.
Route::middleware(['admin:admin', RequireFeature::for('updater')])->prefix('updates')->name('updates.')->group(function () {
    Route::get('/', [UpdateController::class, 'index'])->name('index');
    Route::post('check', [UpdateController::class, 'check'])->middleware('throttle:6,1')->name('check');
    Route::post('install', [UpdateController::class, 'install'])->middleware('throttle:6,1')->name('install');
    Route::post('skeleton/compare', [UpdateController::class, 'compareSkeleton'])->middleware('throttle:6,1')->name('skeleton.compare');
    Route::post('skeleton/{update}/apply', [UpdateController::class, 'applySkeleton'])->whereNumber('update')->middleware('throttle:6,1')->name('skeleton.apply');
    Route::get('{update}', [UpdateController::class, 'show'])->whereNumber('update')->name('show');
    Route::get('{update}/status', [UpdateController::class, 'status'])->whereNumber('update')->middleware('throttle:120,1')->name('status');
});

// Import › WooCommerce API (administrators only, feature switch "woo_api_import"): saved connection (encrypted secrets),
// connection test, background import runs with a live progress page (polls status), cancel / resume, history, logs.
Route::middleware(['admin:admin', RequireFeature::for('woo_api_import')])->prefix('import/woocommerce')->name('import.woo.')->group(function () {
    Route::get('/', [WooApiImportController::class, 'index'])->name('index');
    Route::post('connection', [WooApiImportController::class, 'saveConnection'])->middleware('throttle:20,1')->name('connection');
    Route::post('start', [WooApiImportController::class, 'start'])->middleware('throttle:6,1')->name('start');
    Route::get('{import}', [WooApiImportController::class, 'show'])->whereNumber('import')->name('show');
    Route::get('{import}/status', [WooApiImportController::class, 'status'])->whereNumber('import')->middleware('throttle:120,1')->name('status');
    Route::post('{import}/cancel', [WooApiImportController::class, 'cancel'])->whereNumber('import')->name('cancel');
    Route::post('{import}/resume', [WooApiImportController::class, 'resume'])->whereNumber('import')->middleware('throttle:6,1')->name('resume');
    Route::get('{import}/log', [WooApiImportController::class, 'log'])->whereNumber('import')->name('log');
});

// Living style guide of every admin component (linked from docs/ADMIN_UI.md, not from the menu)
Route::get('ui-kit', [DashboardController::class, 'uiKit'])->name('ui-kit');

// Unknown /admin/… URLs: admin-styled 404 for staff, sign-in page for guests (fallbacks are matched after every other route)
Route::fallback(fn () => abort(404));
