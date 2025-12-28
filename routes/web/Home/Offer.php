<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\OfferController;

Route::get('/oferta', [OfferController::class, 'index'])->name('oferta');

