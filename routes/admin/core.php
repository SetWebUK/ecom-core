<?php

use Pine\Commerce\Http\Controllers\Admin\CouponController;
use Pine\Commerce\Http\Controllers\Admin\DashboardController;
use Pine\Commerce\Http\Controllers\Admin\MediaUploadController;
use Pine\Commerce\Http\Controllers\Admin\ProfileController;
use Pine\Commerce\Http\Controllers\Admin\SearchController;
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

// Living style guide of every admin component (linked from docs/ADMIN_UI.md, not from the menu)
Route::get('ui-kit', [DashboardController::class, 'uiKit'])->name('ui-kit');

// Unknown /admin/… URLs: admin-styled 404 for staff, sign-in page for guests (fallbacks are matched after every other route)
Route::fallback(fn () => abort(404));
