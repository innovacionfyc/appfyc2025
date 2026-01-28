<?php

use App\Http\Controllers\Comercial\ComercialController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {

    Route::middleware(['role:comercial,super-admin'])->prefix('comercial')->group(function () {
        Route::get('/dashboard', [ComercialController::class, 'show'])->name('commercial.dashboard');
    });

});

   