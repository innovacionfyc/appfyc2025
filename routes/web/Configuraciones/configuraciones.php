<?php


use App\Http\Controllers\Configuraciones\ConfiguracionesController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rol:super-admin,admin'])->prefix('admin')->group(function () {
    Route::get('/configuracion', [ConfiguracionesController::class, 'show'])->name('eventos.configuraciones');
});