<?php



use App\Http\Controllers\Conferencistas\ConferencistaController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rol:super-admin,admin'])->prefix('admin')->group(function () {
    Route::post('/conferencista/crear', [ConferencistaController::class, 'store'])->name('conferencistas.store');
});