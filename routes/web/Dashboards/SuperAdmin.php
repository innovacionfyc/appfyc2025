<?php

use App\Http\Controllers\Admin\AdminController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {

    Route::middleware(['rol:super-admin,admin'])->prefix('admin')->group(function () {
        Route::get('/dashboard', [AdminController::class, 'show'])->name('admin.dashboard');
    });
});