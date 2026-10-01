<?php

use Pine\Commerce\Http\Controllers\Admin\AttributeController;
use Pine\Commerce\Http\Controllers\Admin\CategoryController;
use Pine\Commerce\Http\Controllers\Admin\InventoryController;
use Pine\Commerce\Http\Controllers\Admin\ProductController;
use Pine\Commerce\Http\Controllers\Admin\ProductCsvController;
use Pine\Commerce\Http\Controllers\Admin\ProductImageController;
use Pine\Commerce\Http\Controllers\Admin\ReviewController;
use Pine\Commerce\Http\Controllers\Admin\StockAlertController;
use Pine\Commerce\Http\Controllers\Admin\VariationController;
use Illuminate\Support\Facades\Route;
use Pine\Commerce\Http\Middleware\RequireFeature;

/*
|--------------------------------------------------------------------------
| Catalogue   prefix /admin, names admin.*, middleware web + admin (staff)
|--------------------------------------------------------------------------
| Products (+ variants, photos, inventory), categories, attributes, reviews and back-in-stock alerts.
| Resource routes bind models by id. JSON endpoints answer Admin.fetch() calls from the pages' Alpine components.
*/

// Products › Import / Export – the full product CSV (feature switch "product_csv"; before the resource routes)
Route::middleware(RequireFeature::for('product_csv'))->prefix('products/csv')->name('products.csv')->group(function () {
    Route::get('/', [ProductCsvController::class, 'index']);
    Route::get('export', [ProductCsvController::class, 'export'])->middleware('throttle:30,1')->name('.export');
    Route::post('import', [ProductCsvController::class, 'upload'])->middleware('throttle:20,1')->name('.upload');
    Route::get('import/{token}', [ProductCsvController::class, 'mapping'])->name('.mapping');
    Route::post('import/{token}', [ProductCsvController::class, 'map'])->name('.map');
    Route::delete('import/{token}', [ProductCsvController::class, 'destroy'])->name('.destroy');
    Route::get('import/{token}/run', [ProductCsvController::class, 'run'])->name('.run');
    Route::post('import/{token}/step', [ProductCsvController::class, 'step'])->middleware('throttle:600,1')->name('.step');
    Route::post('import/{token}/start', [ProductCsvController::class, 'start'])->name('.start');
    Route::get('import/{token}/report', [ProductCsvController::class, 'report'])->name('.report');
});

// Products
Route::get('products/export', [ProductController::class, 'export'])->name('products.export');
Route::post('products/bulk', [ProductController::class, 'bulk'])->name('products.bulk');
Route::post('products/price-preview', [ProductController::class, 'pricePreview'])->middleware('throttle:120,1')->name('products.price-preview');
Route::post('products/images', [ProductImageController::class, 'upload'])->middleware('throttle:120,1')->name('products.images.store');
Route::patch('products/{product}/quick', [ProductController::class, 'quickUpdate'])->middleware('throttle:240,1')->name('products.quick');
Route::post('products/{product}/featured', [ProductController::class, 'toggleFeatured'])->middleware('throttle:240,1')->name('products.featured');
Route::post('products/{product}/duplicate', [ProductController::class, 'duplicate'])->name('products.duplicate');
Route::post('products/{product}/restore', [ProductController::class, 'restore'])->whereNumber('product')->name('products.restore');
Route::resource('products', ProductController::class)->except('show');

// Variants (the product editor saves variants with the product; these serve the Inventory page)
Route::patch('variations/{variation}', [VariationController::class, 'update'])->middleware('throttle:240,1')->name('variations.update');
Route::delete('variations/{variation}', [VariationController::class, 'destroy'])->name('variations.destroy');

// Inventory (names under admin.products.* so the Products menu stays open)
Route::get('inventory', [InventoryController::class, 'index'])->name('products.inventory');
Route::get('inventory/export', [InventoryController::class, 'export'])->name('products.inventory.export');
Route::post('inventory/import', [InventoryController::class, 'import'])->middleware('throttle:20,1')->name('products.inventory.import');
Route::get('inventory/import/{token}', [InventoryController::class, 'preview'])->name('products.inventory.preview');
Route::post('inventory/import/{token}', [InventoryController::class, 'apply'])->name('products.inventory.apply');

// Categories
Route::post('categories/reorder', [CategoryController::class, 'reorder'])->middleware('throttle:120,1')->name('categories.reorder');
Route::post('categories/{category}/visibility', [CategoryController::class, 'visibility'])->name('categories.visibility');
Route::resource('categories', CategoryController::class)->except('show');

// Attributes + values
Route::post('attributes/{attribute}/values/reorder', [AttributeController::class, 'reorderValues'])->name('attributes.values.reorder');
Route::post('attributes/{attribute}/values/merge', [AttributeController::class, 'mergeValues'])->name('attributes.values.merge');
Route::post('attributes/{attribute}/values', [AttributeController::class, 'storeValue'])->middleware('throttle:240,1')->name('attributes.values.store');
Route::patch('attributes/{attribute}/values/{value}', [AttributeController::class, 'updateValue'])->scopeBindings()->name('attributes.values.update');
Route::delete('attributes/{attribute}/values/{value}', [AttributeController::class, 'destroyValue'])->scopeBindings()->name('attributes.values.destroy');
Route::resource('attributes', AttributeController::class)->except('show');

// Reviews (feature switch "reviews")
Route::middleware(RequireFeature::for('reviews'))->group(function () {
    Route::get('reviews', [ReviewController::class, 'index'])->name('reviews.index');
    Route::post('reviews/bulk', [ReviewController::class, 'bulk'])->name('reviews.bulk');
    Route::post('reviews/{review}/approve', [ReviewController::class, 'approve'])->name('reviews.approve');
    Route::post('reviews/{review}/unapprove', [ReviewController::class, 'unapprove'])->name('reviews.unapprove');
    Route::put('reviews/{review}/reply', [ReviewController::class, 'reply'])->name('reviews.reply');
    Route::delete('reviews/{review}', [ReviewController::class, 'destroy'])->name('reviews.destroy');
});

// Back-in-stock alerts (feature switch "stock_alerts")
Route::middleware(RequireFeature::for('stock_alerts'))->group(function () {
    Route::get('stock-alerts', [StockAlertController::class, 'index'])->name('stock-alerts.index');
    Route::post('stock-alerts/notify', [StockAlertController::class, 'notify'])->middleware('throttle:30,1')->name('stock-alerts.notify');
    Route::post('stock-alerts/bulk', [StockAlertController::class, 'bulk'])->middleware('throttle:30,1')->name('stock-alerts.bulk');
    Route::delete('stock-alerts/{alert}', [StockAlertController::class, 'destroy'])->name('stock-alerts.destroy');
});
