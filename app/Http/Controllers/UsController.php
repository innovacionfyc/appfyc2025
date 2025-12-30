<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

class UsController extends Controller
{
    /**
     * Muestra la página Nosotros.
     */
    public function index()
    {
        return Inertia::render('Home/Us', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('register'),

            'laravelVersion' => Application::VERSION,
            'phpVersion' => PHP_VERSION,
        ]);
    }
}
