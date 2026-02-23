<?php

namespace App\Http\Controllers\Conferencistas;

use App\Http\Controllers\Controller;
use App\Models\PerfilConferencista;
use Illuminate\Http\Request;

class ConferencistaController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'primer_nombre' => 'required|string|max:50',
            'segundo_nombre' => 'nullable|string|max:50',
            'primer_apellido' => 'required|string|max:50',
            'segundo_apellido' => 'nullable|string|max:50',
            'telefono' => 'required|string|max:20',
            'correo' => 'required|email|unique:perfil_conferencistas,correo',
            'area_encargada_id' => 'required|exists:areas_formacion,id',
            'biografia' => 'nullable|string|max:1000',
            'foto' => 'nullable|image|max:2048', // Max 2MB
            'url_hv' => 'nullable|file|mimes:pdf|max:5120', // PDF Max 5MB
        ]);

        // Procesamiento de Archivos
        if ($request->hasFile('foto')) {
            $validated['foto'] = $request->file('foto')->store('conferencistas/fotos', 'public');
        }

        if ($request->hasFile('url_hv')) {
            $validated['url_hv'] = $request->file('url_hv')->store('conferencistas/hvs', 'public');
        }

        // Guardar directamente en la base de datos
        PerfilConferencista::create($validated);

        // Al usar redirect()->back(), Inertia recarga automáticamente la variable 
        // 'conferencistas' en tu Dashboard sin refrescar la página.
        return redirect()->back()->with('success', 'Conferencista registrado con éxito.');
    }
}