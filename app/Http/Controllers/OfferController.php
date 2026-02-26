<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

class OfferController extends Controller
{
    /**
     * Muestra la página Oferta.
     */
    public function index()
    {
        return Inertia::render('Home/Offer', [
            // Opcional: si ya lo usas en el layout para mostrar login/register
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('register'),

            // Opcional: si no lo necesitas, bórralo sin miedo
            'laravelVersion' => Application::VERSION,
            'phpVersion' => PHP_VERSION,
        ]);
    }
}
