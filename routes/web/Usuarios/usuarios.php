<?php

use App\Http\Controllers\Usuarios\UsuariosController;
use Illuminate\Support\Facades\Route;


Route::middleware(['auth', 'verified'])->group(function () {

    Route::middleware(['rol:super-admin'])->prefix('admin')->group(function () {
        Route::get('/usuarios_fyc', [UsuariosController::class, 'show'])->name('usuarios.index');
    });
});