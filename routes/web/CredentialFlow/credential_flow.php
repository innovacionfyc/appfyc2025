<?php

use App\Http\Controllers\CredentialFlow\CredentialFlowController;
use App\Http\Controllers\CredentialFlow\LoteController;
use App\Http\Controllers\CredentialFlow\ParticipanteController;
use App\Http\Controllers\CredentialFlow\PlantillaController;
use App\Http\Controllers\CredentialFlow\PlantillaEditorController;
use Illuminate\Support\Facades\Route;

// F&C Credential Flow: módulo exclusivamente administrativo (no existe ninguna ruta pública).
// Independiente de Academia > Certificados (routes/web/Certificados/certificadosWeb.php).
Route::middleware(['auth', 'rol:super-admin,admin'])->prefix('admin/credential-flow')->name('credential-flow.')->group(function () {
    Route::get('/', [CredentialFlowController::class, 'index'])->name('index');

    // Fase 1: plantillas
    Route::get('/plantillas', [PlantillaController::class, 'index'])->name('plantillas.index');
    Route::post('/plantillas', [PlantillaController::class, 'store'])->name('plantillas.store');
    Route::delete('/plantillas/{plantilla}', [PlantillaController::class, 'destroy'])->name('plantillas.destroy');

    // Fase 2: editor visual (el PDF base es privado y solo se sirve por esta ruta autenticada)
    Route::get('/plantillas/{plantilla}/editor', [PlantillaEditorController::class, 'show'])->name('plantillas.editor');
    Route::get('/plantillas/{plantilla}/pdf', [PlantillaEditorController::class, 'pdf'])->name('plantillas.pdf');
    // Fase 5: PDF de prueba con el diseño guardado y un dataset QA fijo (sin participantes, sin persistencia)
    Route::get('/plantillas/{plantilla}/pdf-prueba', [PlantillaEditorController::class, 'pdfPrueba'])
        ->middleware('throttle:6,1')
        ->name('plantillas.pdf-prueba');
    Route::put('/plantillas/{plantilla}/diseno', [PlantillaEditorController::class, 'update'])->name('plantillas.diseno.update');

    // Fase 6: lotes y participantes (importación XLSX/CSV validada en el backend, PDF individual)
    Route::get('/lotes', [LoteController::class, 'index'])->name('lotes.index');
    Route::get('/lotes/nuevo', [LoteController::class, 'nuevo'])->name('lotes.nuevo');
    Route::get('/lotes/plantilla-excel', [LoteController::class, 'plantillaExcel'])->name('lotes.plantilla-excel');
    Route::post('/lotes/validar', [LoteController::class, 'validar'])->middleware('throttle:30,1')->name('lotes.validar');
    Route::post('/lotes', [LoteController::class, 'store'])->middleware('throttle:30,1')->name('lotes.store');
    Route::get('/lotes/{lote}', [LoteController::class, 'show'])->name('lotes.show');
    Route::put('/lotes/{lote}', [LoteController::class, 'update'])->name('lotes.update');
    Route::delete('/lotes/{lote}', [LoteController::class, 'destroy'])->name('lotes.destroy');

    Route::post('/lotes/{lote}/participantes', [ParticipanteController::class, 'store'])->name('participantes.store');
    Route::put('/lotes/{lote}/participantes/{participante}', [ParticipanteController::class, 'update'])->name('participantes.update');
    Route::delete('/lotes/{lote}/participantes/{participante}', [ParticipanteController::class, 'destroy'])->name('participantes.destroy');
    Route::get('/lotes/{lote}/participantes/{participante}/pdf', [ParticipanteController::class, 'pdf'])
        ->middleware('throttle:20,1')
        ->name('participantes.pdf');
});
