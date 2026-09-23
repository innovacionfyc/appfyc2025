<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Fase 0: prototipo visual con datos mock. Se conectará a datos reales en Fase 3.
Route::get('/podcast', function () {
    return Inertia::render('Home/Podcast');
})->name('podcast.index');
