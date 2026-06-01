<?php

namespace App\Http\Controllers;

use App\Models\AccesoVirtual;
use Inertia\Inertia;

class AccesoVirtualPublicController extends Controller
{
    // PENDIENTE Fase 4: componente Vue 'Home/AccesoVirtual' aún no existe
    // PENDIENTE: estado_id = 1 es el estado "activo" según el patrón del proyecto.
    //            Si en el futuro los IDs de estado cambian, este valor debe actualizarse.
    public function show(string $slug)
    {
        $acceso = AccesoVirtual::with('estado')
            ->where('slug', $slug)
            ->where('estado_id', 1)
            ->firstOrFail();

        return Inertia::render('Home/AccesoVirtual', [
            'acceso' => $acceso,
        ]);
    }
}
