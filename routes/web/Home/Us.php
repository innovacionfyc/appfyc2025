<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UsController;

Route::get('/nosotros', [UsController::class, 'index'])
    ->name('nosotros');
