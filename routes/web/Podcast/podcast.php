<?php

use App\Http\Controllers\Podcast\PodcastEpisodioController;
use App\Http\Controllers\Podcast\PodcastTemporadaController;
use Illuminate\Support\Facades\Route;

// Administración del podcast "Íntimamente Hablando". La ruta pública vive en routes/web/Home/Podcast.php.
Route::middleware(['auth', 'rol:super-admin,admin'])->prefix('admin/podcast')->name('podcast.')->group(function () {

    Route::get('/temporadas', [PodcastTemporadaController::class, 'index'])->name('temporadas.index');
    Route::post('/temporadas', [PodcastTemporadaController::class, 'store'])->name('temporadas.store');
    Route::put('/temporadas/{temporada}', [PodcastTemporadaController::class, 'update'])->name('temporadas.update');
    Route::delete('/temporadas/{temporada}', [PodcastTemporadaController::class, 'destroy'])->name('temporadas.destroy');
    Route::post('/temporadas/{id}/restore', [PodcastTemporadaController::class, 'restore'])->name('temporadas.restore');

    Route::get('/episodios', [PodcastEpisodioController::class, 'index'])->name('episodios.index');
    Route::post('/episodios', [PodcastEpisodioController::class, 'store'])->name('episodios.store');
    Route::put('/episodios/{episodio}', [PodcastEpisodioController::class, 'update'])->name('episodios.update');
    Route::delete('/episodios/{episodio}', [PodcastEpisodioController::class, 'destroy'])->name('episodios.destroy');
    Route::post('/episodios/{id}/restore', [PodcastEpisodioController::class, 'restore'])->name('episodios.restore');

    // Solo parsea la URL pegada (sin HTTP externo); sirve para el preview en vivo del modal.
    Route::post('/episodios/validar-youtube', [PodcastEpisodioController::class, 'validarYoutube'])->name('episodios.validar-youtube');
});
