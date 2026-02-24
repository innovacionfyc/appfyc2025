<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\HomeController;

Route::get('/', [HomeController::class, 'show'])->name('home.index');


Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
});

// ¡ESTA ES LA RUTA DE LA PLANTILLA DEL EVENTO!
Route::get('/evento/{id}', [HomeController::class, 'showPlantilla'])->name('evento.show');
