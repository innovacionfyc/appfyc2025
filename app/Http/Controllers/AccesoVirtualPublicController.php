<?php

namespace App\Http\Controllers;

use App\Models\AccesoVirtual;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class AccesoVirtualPublicController extends Controller
{
    public function show(string $slug)
    {
        $acceso = AccesoVirtual::with('estado')
            ->where('slug', $slug)
            ->firstOrFail();

        return Inertia::render('Home/AccesoVirtual', [
            'acceso' => [
                'nombre'            => $acceso->nombre,
                'descripcion'       => $acceso->descripcion,
                'fecha'             => $acceso->fecha ? $acceso->fecha->format('Y-m-d') : null,
                'hora'              => $acceso->hora,
                'url_zoom'          => $acceso->url_zoom,
                'estado_id'         => $acceso->estado_id,
                'estado'            => $acceso->estado,
                'imagen_banner_url' => $acceso->imagen_banner
                    ? Storage::url($acceso->imagen_banner)
                    : null,
            ],
        ]);
    }
}
