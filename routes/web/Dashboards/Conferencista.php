<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Conferencistas\ConferencistaController;

Route::middleware(['auth', 'verified'])->group(function () {

    Route::middleware(['rol:conferencista'])->prefix('speaker')->group(function () {
        Route::get('/perfil', [ConferencistaController::class, 'show'])->name('speaker.dashboard');
    });
});
