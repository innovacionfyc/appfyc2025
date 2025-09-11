<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// --- RUTAS DEL PANEL DE ADMINISTRADOR ---
Route::middleware(['auth', 'isAdmin'])->prefix('admin')->name('admin.')->group(function () {
    
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Aquí pondremos más rutas de admin en el futuro, por ejemplo:
    // Route::get('/users', [UserController::class, 'index'])->name('users.index');
    // Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    
});

// routes/web.php

// Dentro del grupo de rutas de admin:
Route::get('/test-flash', [DashboardController::class, 'testFlash'])->name('test.flash');

require __DIR__.'/auth.php';
