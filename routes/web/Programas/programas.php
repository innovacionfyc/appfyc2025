<?php


use App\Http\Controllers\Programas\ProgramasController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rol:super-admin,admin'])->prefix('admin')->group(function () {
    Route::get('/programas', [ProgramasController::class, 'show'])->name('eventos.programas');
});