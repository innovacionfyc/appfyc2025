<?php



use App\Http\Controllers\Conferencistas\ConferencistaController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rol:super-admin,admin'])->prefix('admin')->group(function () {
    Route::post('/conferencista/crear', [ConferencistaController::class, 'store'])->name('conferencistas.store');
    Route::put('/conferencistas/{id}', [ConferencistaController::class, 'update'])->name('conferencistas.update');
    Route::delete('/conferencistas/delete/{id}', [ConferencistaController::class, 'destroy'])->name('conferencistas.destroy');
});