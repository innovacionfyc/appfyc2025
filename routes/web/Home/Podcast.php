<?php

use App\Http\Controllers\PodcastComentarioPublicController;
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

// Comentarios públicos: solo aprobados al listar (paginado); todo envío nuevo queda pendiente de moderación.
Route::get('/podcast/{slug}/comentarios', [PodcastComentarioPublicController::class, 'index'])
    ->where('slug', '[a-z0-9-]+')
    ->middleware('throttle:60,1')
    ->name('podcast.comentarios.index');

Route::post('/podcast/{slug}/comentarios', [PodcastComentarioPublicController::class, 'store'])
    ->where('slug', '[a-z0-9-]+')
    ->middleware('throttle:podcast-comentarios')
    ->name('podcast.comentarios.store');
