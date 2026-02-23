<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {

    Route::middleware(['rol:conferencista'])->prefix('speaker')->group(function () {
        Route::get('/perfil', [SpeakerController::class, 'edit'])->name('speaker.dashboard');
    });
});

   