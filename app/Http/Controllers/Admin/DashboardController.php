<?php

// app/Http/Controllers/Admin/DashboardController.php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        // Ya no pasamos props, Pinia se encargará de todo.
    return Inertia::render('Admin/Dashboard');
    }

    // app/Http/Controllers/Admin/DashboardController.php

// ...
public function testFlash()
{
    // Simula una operación exitosa
    return redirect()
        ->route('admin.dashboard')
        ->with('success', '¡La operación de prueba fue exitosa!');

    // Para probar un error, usarías:
    // ->with('error', 'Hubo un problema en la operación.');
}

}