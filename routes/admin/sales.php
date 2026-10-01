<?php

use Pine\Commerce\Http\Controllers\Admin\AbandonedCartController;
use Pine\Commerce\Http\Controllers\Admin\CustomerController;
use Pine\Commerce\Http\Controllers\Admin\ExportController;
use Pine\Commerce\Http\Controllers\Admin\InvoicePdfController;
use Pine\Commerce\Http\Controllers\Admin\OrderController;
use Pine\Commerce\Http\Controllers\Admin\OrderNoteController;
use Pine\Commerce\Http\Controllers\Admin\PrintController;
use Pine\Commerce\Http\Controllers\Admin\RefundController;
use Pine\Commerce\Http\Controllers\Admin\ReportController;
use Illuminate\Support\Facades\Route;
use Pine\Commerce\Http\Middleware\RequireFeature;

/*
|--------------------------------------------------------------------------
| Sales   prefix /admin, names admin.*, middleware web + admin (staff)
|--------------------------------------------------------------------------
| Orders (+ notes, refunds, printing, CSV), customers, abandoned checkouts and analytics.
| Fixed paths (export, bulk, quote…) are registered before the resource routes so they never hit {order}/{customer}.
| JSON for the order form lives under /admin/api/sales/….
*/

// Orders ------------------------------------------------------------------------------------------
Route::get('orders/export', [ExportController::class, 'orders'])->name('orders.export');
Route::post('orders/bulk', [OrderController::class, 'bulk'])->name('orders.bulk');
Route::post('orders/quote', [OrderController::class, 'quote'])->middleware('throttle:240,1')->name('orders.quote');
Route::resource('orders', OrderController::class)->whereNumber('order');
Route::prefix('orders/{order}')->name('orders.')->whereNumber('order')->group(function () {
    Route::put('status', [OrderController::class, 'status'])->name('status');
    Route::post('fulfil', [OrderController::class, 'fulfil'])->name('fulfil');
    Route::put('address', [OrderController::class, 'address'])->name('address');
    Route::post('email', [OrderController::class, 'email'])->middleware('throttle:20,1')->name('email');
    Route::post('notes', [OrderNoteController::class, 'store'])->name('notes.store');
    Route::delete('notes/{note}', [OrderNoteController::class, 'destroy'])->whereNumber('note')->name('notes.destroy');
    Route::post('refunds', [RefundController::class, 'store'])->middleware('throttle:30,1')->name('refunds.store');
    Route::get('pdf/{document}', [InvoicePdfController::class, 'show'])->whereIn('document', ['invoice', 'packing-slip'])->name('pdf');
    Route::post('invoice/regenerate', [InvoicePdfController::class, 'regenerate'])->name('invoice.regenerate');
});

// Invoices & packing slips: one order or many (?orders=1,2,3), printable A4 pages in a new tab
Route::get('print/{document}', [PrintController::class, 'show'])->whereIn('document', ['invoice', 'packing-slip'])->name('print');
// PDFs of many orders (?orders=1,2,3): one merged PDF, or &format=zip for one PDF per order in a ZIP
Route::get('pdf/{document}', [InvoicePdfController::class, 'bulk'])->whereIn('document', ['invoice', 'packing-slip'])->middleware('throttle:30,1')->name('pdf');

// Customers ---------------------------------------------------------------------------------------
Route::get('customers/export', [ExportController::class, 'customers'])->name('customers.export');
Route::post('customers/bulk', [CustomerController::class, 'bulk'])->name('customers.bulk');
Route::resource('customers', CustomerController::class)->except('edit')->whereNumber('customer');
Route::prefix('customers/{customer}')->name('customers.')->whereNumber('customer')->group(function () {
    Route::put('address', [CustomerController::class, 'address'])->name('address');
    Route::put('note', [CustomerController::class, 'note'])->name('note');
    Route::post('password-reset', [CustomerController::class, 'passwordReset'])->middleware('throttle:10,1')->name('password-reset');
    Route::post('toggle-active', [CustomerController::class, 'toggleActive'])->name('toggle-active');
});

// Abandoned checkouts -----------------------------------------------------------------------------
Route::middleware(RequireFeature::for('abandoned_carts'))->group(function () {
    Route::get('carts', [AbandonedCartController::class, 'index'])->name('carts.index');
    Route::get('carts/{cart}', [AbandonedCartController::class, 'show'])->whereNumber('cart')->name('carts.show');
    Route::post('carts/{cart}/stop', [AbandonedCartController::class, 'stop'])->whereNumber('cart')->name('carts.stop');
});

// Analytics ---------------------------------------------------------------------------------------
Route::middleware(RequireFeature::for('reports'))->group(function () {
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/export', [ReportController::class, 'export'])->name('reports.export');
});

// JSON for the create / edit order form -------------------------------------------------------------
Route::prefix('api/sales')->name('api.sales.')->middleware('throttle:240,1')->group(function () {
    Route::get('products', [OrderController::class, 'products'])->name('products');
    Route::get('customers/{customer}', [CustomerController::class, 'lookup'])->whereNumber('customer')->name('customer');
});
