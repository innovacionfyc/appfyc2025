<?php

use App\Http\Controllers\CredentialFlow\CredentialFlowController;
use App\Http\Controllers\CredentialFlow\PlantillaController;
use Illuminate\Support\Facades\Route;

// F&C Credential Flow: módulo exclusivamente administrativo (no existe ninguna ruta pública).
// Independiente de Academia > Certificados (routes/web/Certificados/certificadosWeb.php).
Route::middleware(['auth', 'rol:super-admin,admin'])->prefix('admin/credential-flow')->name('credential-flow.')->group(function () {
    Route::get('/', [CredentialFlowController::class, 'index'])->name('index');

    // Fase 1: plantillas
    Route::get('/plantillas', [PlantillaController::class, 'index'])->name('plantillas.index');
    Route::post('/plantillas', [PlantillaController::class, 'store'])->name('plantillas.store');
    Route::delete('/plantillas/{plantilla}', [PlantillaController::class, 'destroy'])->name('plantillas.destroy');
});
