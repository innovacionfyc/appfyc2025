<?php



use App\Http\Controllers\Formularios\FormularioController;
use App\Http\Controllers\Formularios\InscripcionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rol:super-admin,admin'])->prefix('admin')->group(function () {
    Route::post('/formulariosBase/crear', [FormularioController::class, 'store'])->name('formularios.store');
});
Route::post('/formInscripcion/crear', [InscripcionController::class, 'store'])->name('inscripciones.store');