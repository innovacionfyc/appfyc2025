<?php

use App\Http\Controllers\AccesosVirtuales\AccesoVirtualController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rol:super-admin,admin'])->prefix('admin')->group(function () {
    Route::get('/accesos-virtuales/data',                [AccesoVirtualController::class, 'index'])  ->name('accesos-virtuales.index');
    Route::post('/accesos-virtuales/crear',              [AccesoVirtualController::class, 'store'])  ->name('accesos-virtuales.store');
    Route::put('/accesos-virtuales/{accesoVirtual}/update', [AccesoVirtualController::class, 'update'])->name('accesos-virtuales.update');
    Route::delete('/accesos-virtuales/{accesoVirtual}',  [AccesoVirtualController::class, 'destroy'])->name('accesos-virtuales.destroy');
    Route::post('/accesos-virtuales/{id}/restore',       [AccesoVirtualController::class, 'restore'])->name('accesos-virtuales.restore');
});
