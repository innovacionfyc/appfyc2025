<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ContactoController;

Route::get('/contacto', [ContactoController::class, 'index'])->name('contacto');

