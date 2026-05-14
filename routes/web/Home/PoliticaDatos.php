<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PoliticaDatosController;

Route::get('/politica-datos', [PoliticaDatosController::class, 'index'])->name('politica-datos');

