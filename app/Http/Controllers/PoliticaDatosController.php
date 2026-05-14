<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

class PoliticaDatosController extends Controller
{
    /**
     * Muestra la página Oferta.
     */
    public function index()
    {
        return Inertia::render('Home/PoliticaDatos', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('register'),
            'laravelVersion' => Application::VERSION,
            'phpVersion' => PHP_VERSION,
        ]);
    }
}
