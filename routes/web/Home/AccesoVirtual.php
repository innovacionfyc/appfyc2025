<?php

use App\Http\Controllers\AccesoVirtualPublicController;
use Illuminate\Support\Facades\Route;

Route::get('/acceso/{slug}', [AccesoVirtualPublicController::class, 'show'])
    ->name('acceso-virtual.show');
