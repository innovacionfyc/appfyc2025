<?php


use App\Http\Controllers\Evento\EventoController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rol:super-admin,admin'])->prefix('admin')->group(function () {
    Route::get('/eventos/data', [EventoController::class, 'show'])->name('eventos.dashboard');
    Route::get('/eventos/data/archivados', [EventoController::class, 'archivados'])->name('eventos.archivados');
    Route::get('/eventos/data/papelera', [EventoController::class, 'papelera'])->name('eventos.papelera');

    Route::get('/eventos/calendario', [EventoController::class, 'calendario'])->name('eventos.calendario');

    Route::post('/eventos/crear', [EventoController::class, 'store'])->name('eventos.store');
    Route::put('/eventos/{evento}/update', [EventoController::class, 'update'])->name('eventos.update');
    Route::delete('/eventos/{evento}', [EventoController::class, 'destroy'])->name('eventos.destroy');
    Route::post('/eventos/{id}/restore', [EventoController::class, 'restore'])->name('eventos.restore');
});