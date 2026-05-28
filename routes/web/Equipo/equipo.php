<?php

use App\Http\Controllers\Equipo\EquipoController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rol:super-admin,admin'])->prefix('admin')->group(function () {
    Route::post('/equipo/crear', [EquipoController::class, 'store'])->name('equipo.store');
    Route::put('/equipo/{id}', [EquipoController::class, 'update'])->name('equipo.update');
    Route::delete('/equipo/delete/{id}', [EquipoController::class, 'destroy'])->name('equipo.destroy');
});