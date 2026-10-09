<?php

use App\Http\Controllers\CredentialFlow\CasoEspecialController;
use App\Http\Controllers\CredentialFlow\ConciliacionAccionController;
use App\Http\Controllers\CredentialFlow\ConciliacionController;
use App\Http\Controllers\CredentialFlow\CredentialFlowController;
use App\Http\Controllers\CredentialFlow\EliminacionDefinitivaController;
use App\Http\Controllers\CredentialFlow\EmisionController;
use App\Http\Controllers\CredentialFlow\EnvioController;
use App\Http\Controllers\CredentialFlow\HistoricoController;
use App\Http\Controllers\CredentialFlow\HistoricoPdfController;
use App\Http\Controllers\CredentialFlow\IdentidadDecisionController;
use App\Http\Controllers\CredentialFlow\LoteController;
use App\Http\Controllers\CredentialFlow\ParticipanteController;
use App\Http\Controllers\CredentialFlow\PlantillaController;
use App\Http\Controllers\CredentialFlow\PlantillaEditorController;
use App\Http\Controllers\CredentialFlow\ReemplazoController;
use App\Http\Middleware\CabecerasAdminSensible;
use Illuminate\Support\Facades\Route;

// F&C Credential Flow: módulo exclusivamente administrativo (no existe ninguna ruta pública).
// Independiente de Academia > Certificados (routes/web/Certificados/certificadosWeb.php).
Route::middleware(['auth', 'rol:super-admin,admin'])->prefix('admin/credential-flow')->name('credential-flow.')->group(function () {
    Route::get('/', [CredentialFlowController::class, 'index'])->name('index');

    // Fase 1: plantillas
    Route::get('/plantillas', [PlantillaController::class, 'index'])->name('plantillas.index');
    Route::post('/plantillas', [PlantillaController::class, 'store'])->middleware('throttle:10,1,cf-plantilla-crear')->name('plantillas.store');
    Route::delete('/plantillas/{plantilla}', [PlantillaController::class, 'destroy'])->middleware('throttle:20,1,cf-plantilla-borrar')->name('plantillas.destroy');

    // Fase 2: editor visual (el PDF base es privado y solo se sirve por esta ruta autenticada)
    Route::get('/plantillas/{plantilla}/editor', [PlantillaEditorController::class, 'show'])->name('plantillas.editor');
    Route::get('/plantillas/{plantilla}/pdf', [PlantillaEditorController::class, 'pdf'])->name('plantillas.pdf');
    // Fase 5: PDF de prueba con el diseño guardado y un dataset QA fijo (sin participantes, sin persistencia)
    Route::get('/plantillas/{plantilla}/pdf-prueba', [PlantillaEditorController::class, 'pdfPrueba'])
        ->middleware('throttle:6,1')
        ->name('plantillas.pdf-prueba');
    Route::put('/plantillas/{plantilla}/diseno', [PlantillaEditorController::class, 'update'])->middleware('throttle:60,1,cf-plantilla-diseno')->name('plantillas.diseno.update');

    // Fase 6: lotes y participantes (importación XLSX/CSV validada en el backend, PDF individual)
    Route::get('/lotes', [LoteController::class, 'index'])->name('lotes.index');
    Route::get('/lotes/nuevo', [LoteController::class, 'nuevo'])->name('lotes.nuevo');
    Route::get('/lotes/plantilla-excel', [LoteController::class, 'plantillaExcel'])->name('lotes.plantilla-excel');
    Route::post('/lotes/validar', [LoteController::class, 'validar'])->middleware('throttle:30,1')->name('lotes.validar');
    Route::post('/lotes', [LoteController::class, 'store'])->middleware('throttle:30,1')->name('lotes.store');
    Route::get('/lotes/{lote}', [LoteController::class, 'show'])->name('lotes.show');
    Route::put('/lotes/{lote}', [LoteController::class, 'update'])->middleware('throttle:30,1,cf-lote-editar')->name('lotes.update');
    Route::delete('/lotes/{lote}', [LoteController::class, 'destroy'])->middleware('throttle:20,1,cf-lote-borrar')->name('lotes.destroy');

    Route::post('/lotes/{lote}/participantes', [ParticipanteController::class, 'store'])->middleware('throttle:60,1,cf-participante-crear')->name('participantes.store');
    Route::put('/lotes/{lote}/participantes/{participante}', [ParticipanteController::class, 'update'])->middleware('throttle:60,1,cf-participante-editar')->name('participantes.update');
    Route::delete('/lotes/{lote}/participantes/{participante}', [ParticipanteController::class, 'destroy'])->middleware('throttle:60,1,cf-participante-borrar')->name('participantes.destroy');
    Route::get('/lotes/{lote}/participantes/{participante}/pdf', [ParticipanteController::class, 'pdf'])
        ->middleware('throttle:20,1')
        ->name('participantes.pdf');

    // Fase 7: emisiones oficiales (PDF persistido, snapshot inmutable, revocación, reemisión, ZIP)
    Route::post('/lotes/{lote}/participantes/{participante}/emitir', [EmisionController::class, 'emitir'])->middleware('throttle:30,1')->name('participantes.emitir');
    Route::get('/lotes/{lote}/emision/resumen', [EmisionController::class, 'resumen'])->name('lotes.emision.resumen');
    Route::post('/lotes/{lote}/emitir', [EmisionController::class, 'emitirLote'])->middleware('throttle:6,1')->name('lotes.emitir');
    Route::get('/lotes/{lote}/zip', [EmisionController::class, 'zip'])->middleware('throttle:10,1')->name('lotes.zip');
    Route::get('/lotes/{lote}/emisiones', [EmisionController::class, 'historial'])->name('lotes.emisiones');
    Route::get('/emisiones/{emision}/descargar', [EmisionController::class, 'descargar'])->middleware('throttle:60,1')->name('emisiones.descargar');
    Route::post('/emisiones/{emision}/revocar', [EmisionController::class, 'revocar'])->middleware('throttle:30,1')->name('emisiones.revocar');
    Route::post('/emisiones/{emision}/reemitir', [EmisionController::class, 'reemitir'])->middleware('throttle:30,1')->name('emisiones.reemitir');

    // Histórico (evaluaciones legado): SOLO LECTURA. Únicamente rutas GET; no hay ninguna que escriba en las tablas históricas.
    Route::prefix('historico')->name('historico.')->middleware(CabecerasAdminSensible::class)->group(function () {
        Route::get('/', [HistoricoController::class, 'eventos'])->name('index');
        Route::get('/eventos/{evento}', [HistoricoController::class, 'evento'])->whereNumber('evento')->name('eventos.show');
        Route::get('/certificados/{certificado}', [HistoricoController::class, 'certificado'])->whereNumber('certificado')->name('certificados.show');
        Route::get('/plantillas', [HistoricoController::class, 'plantillas'])->name('plantillas.index');
        Route::get('/encuestas', [HistoricoController::class, 'encuestas'])->name('encuestas');
        Route::get('/buscar', [HistoricoController::class, 'buscar'])->middleware('throttle:60,1')->name('buscar');
        // Fase 10A: casos de conciliación por revisar. bandeja y detalle de SOLO LECTURA (GET).
        Route::get('/casos', [ConciliacionController::class, 'index'])->name('casos.index');
        Route::get('/casos/{caso}', [ConciliacionController::class, 'show'])->whereNumber('caso')->name('casos.show');
        // Fase 10B-1: resolución de casos de PLANTILLA (únicas rutas de escritura del módulo de casos; motivo y confirmación obligatorios).
        Route::post('/casos/{caso}/aprobar-candidata', [ConciliacionAccionController::class, 'aprobarCandidata'])->whereNumber('caso')->middleware('throttle:20,1')->name('casos.aprobar-candidata');
        Route::post('/casos/{caso}/confirmar-renderizable', [ConciliacionAccionController::class, 'confirmarRenderizable'])->whereNumber('caso')->middleware('throttle:20,1')->name('casos.confirmar-renderizable');
        Route::post('/casos/{caso}/consolidar-codigo', [ConciliacionAccionController::class, 'consolidarCodigo'])->whereNumber('caso')->middleware('throttle:20,1')->name('casos.consolidar-codigo');
        // Fase 10B-2B-1: consolidar variantes (correo y variación de nombre) y gestión de casos sin evidencia (soporte / descarte).
        Route::post('/casos/{caso}/consolidar-variantes', [ConciliacionAccionController::class, 'consolidarVariantes'])->whereNumber('caso')->middleware('throttle:20,1')->name('casos.consolidar-variantes');
        Route::post('/casos/{caso}/consolidar-nombre', [ConciliacionAccionController::class, 'consolidarNombre'])->whereNumber('caso')->middleware('throttle:20,1')->name('casos.consolidar-nombre');
        Route::post('/casos/{caso}/requiere-soporte', [ConciliacionAccionController::class, 'requiereSoporte'])->whereNumber('caso')->middleware('throttle:20,1')->name('casos.requiere-soporte');
        Route::post('/casos/{caso}/descartar', [ConciliacionAccionController::class, 'descartar'])->whereNumber('caso')->middleware('throttle:20,1')->name('casos.descartar');
        Route::post('/casos/{caso}/aportar-plantilla', [ConciliacionAccionController::class, 'aportarPlantilla'])->whereNumber('caso')->middleware('throttle:10,1')->name('casos.aportar-plantilla');

        // Decisiones de identidad (10B-3A): REGISTRAN, no autorizan (el portal no cambia). Cada POST con su PROPIO contador de límite.
        Route::post('/casos/{caso}/identidad/decisiones', [IdentidadDecisionController::class, 'crear'])->whereNumber('caso')->middleware('throttle:20,1,cf-identidad-decision')->name('casos.identidad.crear');
        Route::post('/casos/{caso}/identidad/decisiones/{decision}/revocar', [IdentidadDecisionController::class, 'revocar'])->whereNumber(['caso', 'decision'])->middleware('throttle:20,1,cf-identidad-revocar')->name('casos.identidad.revocar');

        // Casos especiales (10B-3C-4): evidencia EXTERNA (registrar / invalidar) y reapertura de un soporte con evidencia nueva. No conceden acceso al portal.
        Route::post('/casos/{caso}/especial/evidencia', [CasoEspecialController::class, 'registrarEvidencia'])->whereNumber('caso')->middleware('throttle:20,1,cf-especial-evidencia')->name('casos.especial.evidencia');
        Route::post('/casos/{caso}/especial/evidencia/{evento}/invalidar', [CasoEspecialController::class, 'invalidarEvidencia'])->whereNumber(['caso', 'evento'])->middleware('throttle:20,1,cf-especial-invalidar')->name('casos.especial.invalidar-evidencia');
        Route::post('/casos/{caso}/especial/reabrir', [CasoEspecialController::class, 'reabrir'])->whereNumber('caso')->middleware('throttle:20,1,cf-especial-reabrir')->name('casos.especial.reabrir');

        // Autorización masiva con doble control (10B-3C-3): segunda aprobación por un administrador DISTINTO y revocación de solo la aprobación.
        Route::post('/casos/{caso}/identidad/decisiones/{decision}/aprobar-masiva', [IdentidadDecisionController::class, 'aprobarMasiva'])->whereNumber(['caso', 'decision'])->middleware('throttle:20,1,cf-identidad-aprobar')->name('casos.identidad.aprobar-masiva');
        Route::post('/casos/{caso}/identidad/decisiones/{decision}/revocar-aprobacion', [IdentidadDecisionController::class, 'revocarAprobacion'])->whereNumber(['caso', 'decision'])->middleware('throttle:20,1,cf-identidad-revocar-aprobacion')->name('casos.identidad.revocar-aprobacion');

        // Emitir certificado corregido (10B-2B-2B): asistente de reemplazo de un certificado histórico por una emisión moderna. Cada POST lleva su PROPIO
        // contador de límite (tercer parámetro de throttle): sin prefijo, todas las rutas `throttle:N,1` comparten el contador por usuario y varias vistas
        // previas seguidas podrían bloquear la emisión con un 429.
        Route::get('/casos/{caso}/reemplazo', [ReemplazoController::class, 'show'])->whereNumber('caso')->name('casos.reemplazo');
        Route::post('/casos/{caso}/reemplazo/clon', [ReemplazoController::class, 'prepararClon'])->whereNumber('caso')->middleware('throttle:10,1,cf-reemplazo-clon')->name('casos.reemplazo.clon');
        Route::post('/casos/{caso}/reemplazo/diseno', [ReemplazoController::class, 'confirmarDiseno'])->whereNumber('caso')->middleware('throttle:20,1,cf-reemplazo-diseno')->name('casos.reemplazo.diseno');
        Route::post('/casos/{caso}/reemplazo/preview', [ReemplazoController::class, 'preview'])->whereNumber('caso')->middleware('throttle:30,1,cf-reemplazo-preview')->name('casos.reemplazo.preview');
        Route::post('/casos/{caso}/reemplazo/emitir', [ReemplazoController::class, 'emitir'])->whereNumber('caso')->middleware('throttle:10,1,cf-reemplazo-emitir')->name('casos.reemplazo.emitir');
        // Prueba administrativa del lazy freeze (SIN botón en la interfaz): genera una vez y congela; después verifica y reutiliza.
        Route::get('/certificados/{certificado}/pdf', [HistoricoPdfController::class, 'pdf'])->whereNumber('certificado')->middleware('throttle:20,1')->name('certificados.pdf');
    });

    // Fase 9: envíos de correo (códigos de acceso al portal). SOLO LECTURA: una única ruta GET, sin reenvíos ni acciones masivas.
    Route::get('/envios', [EnvioController::class, 'index'])->name('envios.index');

    // Eliminación definitiva (acción aparte del «Eliminar» normal). Incluye registros ya eliminados (soft delete).
    Route::get('/lotes/{lote}/eliminacion-definitiva', [EliminacionDefinitivaController::class, 'resumenLote'])->withTrashed()->name('lotes.eliminacion.resumen');
    Route::delete('/lotes/{lote}/definitivamente', [EliminacionDefinitivaController::class, 'lote'])->withTrashed()->middleware('throttle:10,1,cf-eliminacion')->name('lotes.destroy-definitivo');
    Route::get('/plantillas/{plantilla}/eliminacion-definitiva', [EliminacionDefinitivaController::class, 'resumenPlantilla'])->withTrashed()->name('plantillas.eliminacion.resumen');
    Route::delete('/plantillas/{plantilla}/definitivamente', [EliminacionDefinitivaController::class, 'plantilla'])->withTrashed()->middleware('throttle:10,1,cf-eliminacion')->name('plantillas.destroy-definitivo');
});
