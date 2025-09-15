<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

class HomeController extends Controller
{
    /**
     * Muestra la página de bienvenida.
     */
    public function show()
    {
        // La función Inertia::render() reemplaza a la función view() de Blade.
        // El primer parámetro 'Welcome' corresponde al archivo .vue en resources/js/Pages/Welcome.vue
        return Inertia::render('Home/Welcome', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('register'),
            'laravelVersion' => Application::VERSION,
            'phpVersion' => PHP_VERSION,
        ]);
    }
}