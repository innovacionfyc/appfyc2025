<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Comercial\ComercialController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\PasswordResetController;
use Inertia\Inertia;

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('login', [LoginController::class, 'login']);

    Route::get('register', [RegisterController::class, 'showRegisterForm'])->name('register');
    Route::post('register', [RegisterController::class, 'registerStore']);


    // Route::get('forgot-password', [PasswordResetController::class, 'create'])->name('password.request');
    // Route::post('forgot-password', [PasswordResetController::class, 'store'])->name('password.email');
    // Route::get('reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    // Route::post('reset-password', [PasswordResetController::class, 'update'])->name('password.update');
});


Route::middleware(['auth', 'verified'])->group(function () {

    Route::get('/dashboard', [LoginController::class, 'redirectByRole'])->name('dashboard');

    
    Route::middleware(['role:super-admin'])
        ->prefix('admin') 
        ->group(function () {
            Route::get('/dashboard', [AdminController::class, 'show'])->name('admin.dashboard');
        });

    Route::middleware(['role:comercial'])
        ->prefix('comercial')
        ->group(function () {
            Route::get('/dashboard', [ComercialController::class, 'show'])->name('commercial.dashboard');
        });

    Route::post('logout', [LoginController::class, 'logout'])->name('logout');
});