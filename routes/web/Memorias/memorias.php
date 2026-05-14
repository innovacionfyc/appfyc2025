<?php


use App\Http\Controllers\Memorias\MemoriasController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rol:super-admin,admin'])->prefix('admin')->group(function () {
    Route::get('/memorias', [MemoriasController::class, 'show'])->name('eventos.memorias');
});