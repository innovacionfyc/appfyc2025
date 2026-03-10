<?php

namespace App\Http\Controllers\Conferencistas;

use App\Http\Controllers\Controller;
use App\Models\PerfilConferencista;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log; // IMPORTANTE
use Illuminate\Validation\ValidationException;

class ConferencistaController extends Controller
{
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'primer_nombre' => 'required|string|max:50',
                'segundo_nombre' => 'nullable|string|max:50',
                'primer_apellido' => 'required|string|max:50',
                'segundo_apellido' => 'nullable|string|max:50',
                'telefono' => 'required|string|max:20',
                'correo' => 'required|email|unique:perfil_conferencistas,correo',
                'area_encargada_id' => 'required|exists:areas_formacion,id',
                'biografia' => 'nullable|string|max:1000',
                'foto' => 'nullable|image|max:2048', 
                // 'url_hv' => 'nullable|file|mimes:pdf|max:5120', 
                'url_hv' => 'required|string|max:200', 
            ]);

            // Procesamiento de Archivos
            if ($request->hasFile('foto')) {
                $validated['foto'] = $request->file('foto')->store('conferencistas/fotos', 'public');
            }

            if ($request->hasFile('url_hv')) {
                $validated['url_hv'] = $request->file('url_hv')->store('conferencistas/hvs', 'public');
            }

            PerfilConferencista::create($validated);

            return redirect()->back()->with('success', 'Conferencista registrado con éxito.');
            
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error al guardar conferencista: ' . $e->getMessage());
            
            return back()->withErrors(['general' => 'Ocurrió un error interno al guardar. Revisa los logs.']);
        }
    }
}