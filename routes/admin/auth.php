<?php

use Pine\Commerce\Http\Controllers\Admin\Auth\LoginController;
use Pine\Commerce\Http\Controllers\Admin\Auth\PasswordResetController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Back-office sign-in (guests)   prefix /admin, names admin.*
|--------------------------------------------------------------------------
| Staff only: LoginRequest rejects customers and deactivated accounts. Everything else in /admin sits
| behind the 'admin' middleware (Pine\Commerce\Http\Middleware\EnsureStaff), which redirects guests here.
*/

Route::get('login', [LoginController::class, 'show'])->name('login');
Route::post('login', [LoginController::class, 'store'])->middleware('throttle:30,1')->name('login.store');
Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

Route::get('forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
Route::post('forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:5,1')->name('password.email');
Route::get('reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
Route::post('reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:10,1')->name('password.update');
