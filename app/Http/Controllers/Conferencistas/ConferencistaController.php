<?php

namespace App\Http\Controllers\Conferencistas;

use App\Http\Controllers\Controller;
use App\Models\PerfilConferencista;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ConferencistaController extends Controller
{
    public function show(): Response
    {
        $usuario = Auth::user();
        $perfil = PerfilConferencista::where('usuario_id', $usuario->id)->first();

        return Inertia::render('Dashboard/Conferencista', [
            'auth' => ['user' => $usuario],
            'perfil' => $perfil,
        ]);
    }

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
                'areas_encargadas' => 'required|array|min:1',
                'areas_encargadas.*' => 'exists:areas_formacion,id',
                'biografia' => 'nullable|string|max:1000',
                'foto' => 'nullable|image',
                'url_hv' => 'required|string|max:200',
            ]);

            if ($request->hasFile('foto')) {
                $validated['foto'] = $request->file('foto')->store('conferencistas/fotos', 'public');
            }

            if ($request->hasFile('url_hv')) {
                $validated['url_hv'] = $request->file('url_hv')->store('conferencistas/hvs', 'public');
            }

            $validated['areas_encargadas'] = array_map('intval', $validated['areas_encargadas']);

            PerfilConferencista::create($validated);

            return redirect()->back()->with('success', 'Conferencista registrado con éxito.');

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error al guardar conferencista: ' . $e->getMessage());

            return back()->withErrors(['general' => 'Ocurrió un error interno al guardar. Revisa los logs.']);
        }
    }

    public function update(Request $request, $id): RedirectResponse
    {
        $conferencista = PerfilConferencista::findOrFail($id);

        $validated = $request->validate([
            'primer_nombre' => 'required|string|max:30',
            'segundo_nombre' => 'nullable|string|max:30',
            'primer_apellido' => 'required|string|max:30',
            'segundo_apellido' => 'nullable|string|max:30',
            'telefono' => 'required|string|max:10',
            'correo' => 'required|email|max:60|unique:perfil_conferencistas,correo,' . $conferencista->id,

            'areas_encargadas' => 'required|array|min:1',
            'areas_encargadas.*' => 'integer|exists:areas_formacion,id',

            'biografia' => 'required|string|max:500',
            'url_hv' => 'nullable|string|max:200',
            'foto' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        if ($request->hasFile('foto')) {
            if ($conferencista->foto) {
                Storage::disk('public')->delete($conferencista->foto);
            }

            $path = $request->file('foto')->store('conferencistas', 'public');
            $validated['foto'] = $path;
        } else {
            unset($validated['foto']);
        }

        $validated['areas_encargadas'] = array_map('intval', $validated['areas_encargadas']);

        if ($request->hasFile('foto')) {
            if ($conferencista->foto) {
                Storage::disk('public')->delete($conferencista->foto);
            }
            $path = $request->file('foto')->store('conferencistas', 'public');
            $validated['foto'] = $path;
        } else {
            unset($validated['foto']);
        }

        $validated['update_by'] = auth()->id();

        $conferencista->update($validated);

        return back()->with('success', 'Perfil del conferencista actualizado exitosamente.');
    }

    public function destroy($id): RedirectResponse
    {
        $conferencista = PerfilConferencista::findOrFail($id);

        $conferencista->delete();


        return back()->with('success', 'Conferencista eliminado correctamente.');
    }
}