<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ResponsabilidadSocialController;

Route::get('/responsabilidad-social', [ResponsabilidadSocialController::class, 'index'])->name('responsabilidad-social');

