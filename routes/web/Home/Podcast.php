<?php

use App\Http\Controllers\PodcastPublicController;
use App\Http\Controllers\PodcastReaccionController;
use Illuminate\Support\Facades\Route;

Route::get('/podcast', [PodcastPublicController::class, 'index'])->name('podcast.index');

Route::get('/podcast/{slug}', [PodcastPublicController::class, 'show'])
    ->where('slug', '[a-z0-9-]+')
    ->name('podcast.show');

// "Me gusta" anónimo: intención explícita (dar/quitar), protegido por CSRF y límite de peticiones.
Route::post('/podcast/{slug}/reaccion', [PodcastReaccionController::class, 'store'])
    ->where('slug', '[a-z0-9-]+')
    ->middleware('throttle:podcast-reacciones')
    ->name('podcast.reaccion');
