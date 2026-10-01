<?php

use Pine\Commerce\Http\Controllers\Admin\MediaController;
use Pine\Commerce\Http\Controllers\Admin\MenuController;
use Pine\Commerce\Http\Controllers\Admin\NewsletterController;
use Pine\Commerce\Http\Controllers\Admin\PageController;
use Pine\Commerce\Http\Controllers\Admin\PostCategoryController;
use Pine\Commerce\Http\Controllers\Admin\PostController;
use Pine\Commerce\Http\Controllers\Admin\RedirectController;
use Pine\Commerce\Http\Controllers\Admin\SettingsController;
use Pine\Commerce\Http\Controllers\Admin\ShippingMethodController;
use Pine\Commerce\Http\Controllers\Admin\ShippingZoneController;
use Pine\Commerce\Http\Controllers\Admin\TaxController;
use Pine\Commerce\Http\Controllers\Admin\StaffController;
use Pine\Commerce\Http\Controllers\Admin\SubmissionController;
use Pine\Commerce\Http\Controllers\Admin\SystemController;
use Illuminate\Support\Facades\Route;
use Pine\Commerce\Http\Middleware\RequireFeature;

/*
|--------------------------------------------------------------------------
| Content, inbox & settings   prefix /admin, names admin.*, middleware web + admin (staff)
|--------------------------------------------------------------------------
| Pages (incl. the home page builder), blog, menus, media library, redirects, form submissions, newsletter,
| store settings, shipping methods and (administrators only) payment settings + staff accounts.
*/

// Link search for URL fields (menus, home page buttons, redirects): pages, categories, products, posts
Route::get('api/links', [MenuController::class, 'links'])->middleware('throttle:240,1')->name('api.links');

// Pages
Route::post('pages/bulk', [PageController::class, 'bulk'])->name('pages.bulk');
Route::post('pages/{page}/duplicate', [PageController::class, 'duplicate'])->name('pages.duplicate');
Route::resource('pages', PageController::class)->except('show');

// Blog (feature switch "blog")
Route::middleware(RequireFeature::for('blog'))->group(function () {
    Route::post('posts/bulk', [PostController::class, 'bulk'])->name('posts.bulk');
    Route::resource('posts', PostController::class)->except('show');
    Route::resource('post-categories', PostCategoryController::class)->except('show')->parameters(['post-categories' => 'postCategory']);
});

// Menus (edit = drag-and-drop builder; update saves the whole tree)
Route::resource('menus', MenuController::class)->except('show');

// Media library (upload endpoint admin.media.upload lives in core.php)
Route::get('media', [MediaController::class, 'index'])->name('media.index');
Route::post('media/scan', [MediaController::class, 'scan'])->middleware('throttle:10,1')->name('media.scan');
Route::post('media/bulk', [MediaController::class, 'bulk'])->name('media.bulk');
Route::get('media/{media}', [MediaController::class, 'show'])->whereNumber('media')->name('media.show');
Route::put('media/{media}', [MediaController::class, 'update'])->whereNumber('media')->name('media.update');
Route::delete('media/{media}', [MediaController::class, 'destroy'])->whereNumber('media')->name('media.destroy');

// Redirects (feature switch "redirects")
Route::middleware(RequireFeature::for('redirects'))->group(function () {
    Route::get('redirects/export', [RedirectController::class, 'export'])->name('redirects.export');
    Route::post('redirects/import', [RedirectController::class, 'import'])->middleware('throttle:10,1')->name('redirects.import');
    Route::post('redirects/bulk', [RedirectController::class, 'bulk'])->name('redirects.bulk');
    Route::resource('redirects', RedirectController::class)->except('show');
});

// Inbox: form submissions (feature switch "contact_form") + newsletter (feature switch "newsletter")
Route::middleware(RequireFeature::for('contact_form'))->group(function () {
    Route::get('form-submissions/export', [SubmissionController::class, 'export'])->name('form-submissions.export');
    Route::post('form-submissions/bulk', [SubmissionController::class, 'bulk'])->name('form-submissions.bulk');
    Route::patch('form-submissions/{submission}/unread', [SubmissionController::class, 'unread'])->name('form-submissions.unread');
    Route::resource('form-submissions', SubmissionController::class)->only(['index', 'show', 'destroy'])->parameters(['form-submissions' => 'submission']);
});

Route::middleware(RequireFeature::for('newsletter'))->group(function () {
    Route::get('newsletter', [NewsletterController::class, 'index'])->name('newsletter.index');
    Route::get('newsletter/export', [NewsletterController::class, 'export'])->name('newsletter.export');
    Route::post('newsletter/bulk', [NewsletterController::class, 'bulk'])->name('newsletter.bulk');
    Route::patch('newsletter/{subscriber}', [NewsletterController::class, 'toggle'])->name('newsletter.toggle');
    Route::delete('newsletter/{subscriber}', [NewsletterController::class, 'destroy'])->name('newsletter.destroy');
});

// Settings
Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');

// Settings › Tax: classes, rates (+ CSV); the options are the generic settings form (group "tax")
Route::post('settings/tax/classes', [TaxController::class, 'storeClass'])->name('tax.classes.store');
Route::delete('settings/tax/classes/{taxClass}', [TaxController::class, 'destroyClass'])->name('tax.classes.destroy');
Route::put('settings/tax/rates/{class}', [TaxController::class, 'saveRates'])->where('class', '[a-z0-9-]+')->name('tax.rates.save');
Route::get('settings/tax/rates/export', [TaxController::class, 'export'])->name('tax.export');
Route::post('settings/tax/rates/import', [TaxController::class, 'import'])->name('tax.import');

// Settings › Shipping: zones (ordered), their methods, shipping classes, countries sold to
Route::post('shipping/zones/reorder', [ShippingZoneController::class, 'reorder'])->name('shipping.zones.reorder');
Route::resource('shipping/zones', ShippingZoneController::class)->except(['show', 'index'])->parameters(['zones' => 'shippingZone'])->names('shipping.zones');
Route::post('shipping/zones/{shippingZone}/methods/reorder', [ShippingZoneController::class, 'reorderMethods'])->name('shipping.zones.methods.reorder');
Route::post('shipping/classes', [ShippingZoneController::class, 'storeClass'])->name('shipping.classes.store');
Route::put('shipping/classes/{shippingClass}', [ShippingZoneController::class, 'updateClass'])->name('shipping.classes.update');
Route::delete('shipping/classes/{shippingClass}', [ShippingZoneController::class, 'destroyClass'])->name('shipping.classes.destroy');
Route::put('shipping/countries', [ShippingZoneController::class, 'updateCountries'])->name('shipping.countries.update');
Route::post('shipping/reorder', [ShippingMethodController::class, 'reorder'])->name('shipping.reorder');
Route::resource('shipping', ShippingMethodController::class)->except('show')->parameters(['shipping' => 'shippingMethod']);

Route::middleware('admin:admin')->group(function () {
    Route::get('settings/payments', [SettingsController::class, 'payments'])->name('payments.edit');
    Route::put('settings/payments', [SettingsController::class, 'updatePayments'])->name('payments.update');

    // Settings › System: version, theme, feature switches, commerce:doctor checks, last import report (read-only)
    Route::get('settings/system', [SystemController::class, 'index'])->name('settings.system');

    Route::post('staff/{staff}/password', [StaffController::class, 'password'])->middleware('throttle:10,1')->name('staff.password');
    Route::resource('staff', StaffController::class)->except(['show', 'destroy'])->parameters(['staff' => 'staff']);
});

// Settings › Theme (switching the active theme is limited to administrators inside the controller)
Route::get('settings/theme', [Pine\Commerce\Http\Controllers\Admin\ThemeSettingsController::class, 'edit'])->name('settings.theme');
Route::put('settings/theme', [Pine\Commerce\Http\Controllers\Admin\ThemeSettingsController::class, 'update'])->name('settings.theme.update');

// generic settings form: the core groups + client groups (Commerce::settings()); SettingsController 404s any other key
// (a pattern, not a fixed list, so client groups registered after these routes – and cached routes – work)
Route::get('settings/{group}', [SettingsController::class, 'edit'])->where('group', '[a-z0-9_-]+')->name('settings.edit');
Route::put('settings/{group}', [SettingsController::class, 'update'])->where('group', '[a-z0-9_-]+')->name('settings.update');
