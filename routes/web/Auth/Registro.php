<?php


use App\Http\Controllers\Auth\RegisterController;
use Illuminate\Support\Facades\Route;



Route::get('/register', [\App\Http\Controllers\RegisterController::class, 'show'])->name('home.register');
Route::middleware('auth')->group(function () {

});