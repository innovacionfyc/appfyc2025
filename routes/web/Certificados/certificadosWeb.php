<?php


use App\Http\Controllers\Certificados\CertificadosController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rol:super-admin,admin'])->prefix('admin')->group(function () {
    Route::get('/certificadosWeb', [CertificadosController::class, 'show'])->name('eventos.certificados');
});