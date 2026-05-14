<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TerminosCondicionesController;

Route::get('/terminos', [TerminosCondicionesController::class, 'index'])->name('terminos');

