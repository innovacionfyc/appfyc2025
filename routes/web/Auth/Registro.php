<?php


use App\Http\Controllers\Auth\RegisterController;
use Illuminate\Support\Facades\Route;



Route::get('/register', [RegisterController::class, 'show'])->name('home.register');
Route::middleware('auth')->group(function () {

});