<?php

namespace App\Http\Controllers\Equipo;

use App\Http\Controllers\Controller;
use App\Models\PerfilOrganizador;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\RedirectResponse;


class EquipoController extends Controller
{


    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'primer_nombre' => 'required|string|max:50',
            'segundo_nombre' => 'nullable|string|max:50',
            'primer_apellido' => 'required|string|max:50',
            'segundo_apellido' => 'nullable|string|max:50',
            'tipo_documento_id' => 'required|exists:tipos_documento,id',
            'numero_documento' => 'required|string|max:20|unique:usuarios,numero_documento|unique:perfil_organizadores,numero_documento',
            'foto' => 'nullable|image|mimes:jpeg,png,jpg,webp',

            'correo_principal' => 'required|email|max:100|unique:usuarios,correo_principal',
            'correo_corporativo' => 'nullable|email|max:100',
            'telefono_personal' => 'required|string|max:15',
            'telefono_corporativo' => 'nullable|string|max:15',

            'cargo' => 'required|string|max:100',
            'rol_id' => 'required|exists:roles,id',
            'equipo_id' => 'required|exists:equipos_fyc,id',
            'area_encargada_id' => 'required|exists:areas_formacion,id',

            'contrasena' => 'required|string|min:8',
            'estado_id' => 'required|exists:estados,id',
        ]);

        $fotoPath = null;
        if ($request->hasFile('foto')) {
            $fotoPath = $request->file('foto')->store('equipo_fyc', 'public');
        }

        try {
            DB::transaction(function () use ($validated, $fotoPath) {

                $usuario = Usuario::create([
                    'estado_id' => $validated['estado_id'],
                    'perfil_completo' => true,
                    'correo_principal' => $validated['correo_principal'],
                    'numero_documento' => $validated['numero_documento'],
                    'contrasena' => Hash::make($validated['contrasena']),
                    'created_by' => auth()->id(),
                ]);

                PerfilOrganizador::create([
                    'usuario_id' => $usuario->id,
                    'primer_nombre' => $validated['primer_nombre'],
                    'segundo_nombre' => $validated['segundo_nombre'],
                    'primer_apellido' => $validated['primer_apellido'],
                    'segundo_apellido' => $validated['segundo_apellido'],
                    'foto' => $fotoPath,
                    'telefono_personal' => $validated['telefono_personal'],
                    'telefono_corporativo' => $validated['telefono_corporativo'],
                    'correo_corporativo' => $validated['correo_corporativo'],
                    'cargo' => $validated['cargo'],
                    'numero_documento' => $validated['numero_documento'],
                    'rol_id' => $validated['rol_id'],
                    'area_encargada_id' => $validated['area_encargada_id'],
                    'equipo_id' => $validated['equipo_id'],
                    'tipo_documento_id' => $validated['tipo_documento_id'],
                    'created_by' => auth()->id(),
                ]);

            });

            return back()->with('success', 'Miembro del equipo registrado exitosamente.');

        } catch (\Exception $e) {

            if ($fotoPath) {
                Storage::disk('public')->delete($fotoPath);
            }

            return back()->withErrors(['error' => 'Error al guardar el registro: ' . $e->getMessage()])->withInput();
        }
    }

    public function update(Request $request, $id): RedirectResponse
{
    $organizador = PerfilOrganizador::findOrFail($id);
    $usuario = $organizador->usuario;

    $validated = $request->validate([
        'primer_nombre'        => 'required|string|max:50',
        'segundo_nombre'       => 'nullable|string|max:50',
        'primer_apellido'      => 'required|string|max:50',
        'segundo_apellido'     => 'nullable|string|max:50',
        'tipo_documento_id'    => 'required|exists:tipos_documento,id',
        'numero_documento'     => 'required|string|max:20|unique:usuarios,numero_documento,' . $usuario->id . '|unique:perfil_organizadores,numero_documento,' . $organizador->id,
        'foto'                 => 'nullable|image|mimes:jpeg,png,jpg,webp',

        'correo_principal'     => 'required|email|max:100|unique:usuarios,correo_principal,' . $usuario->id,
        'correo_corporativo'   => 'nullable|email|max:100',
        'telefono_personal'    => 'required|string|max:15',
        'telefono_corporativo' => 'nullable|string|max:15',

        'cargo'                => 'required|string|max:100',
        'rol_id'               => 'required|exists:roles,id',
        'equipo_id'            => 'required|exists:equipos_fyc,id',
        'area_encargada_id'    => 'required|exists:areas_formacion,id',

        'contrasena'           => 'nullable|string|min:8',
        'estado_id'            => 'required|exists:estados,id',
    ]);

    $fotoPath = $organizador->foto;
    if ($request->hasFile('foto')) {
        if ($organizador->foto) {
            Storage::disk('public')->delete($organizador->foto);
        }
        $fotoPath = $request->file('foto')->store('equipo_fyc', 'public');
    }

    try {
        DB::transaction(function () use ($request, $validated, $fotoPath, $usuario, $organizador) {
            
            $usuarioData = [
                'estado_id'        => $validated['estado_id'],
                'correo_principal' => $validated['correo_principal'],
                'numero_documento' => $validated['numero_documento'],
                'update_by'        => auth()->id(),
            ];

            if ($request->filled('contrasena')) {
                $usuarioData['contrasena'] = Hash::make($validated['contrasena']);
            }

            $usuario->update($usuarioData);

            $organizador->update([
                'primer_nombre'        => $validated['primer_nombre'],
                'segundo_nombre'       => $validated['segundo_nombre'],
                'primer_apellido'      => $validated['primer_apellido'],
                'segundo_apellido'     => $validated['segundo_apellido'],
                'foto'                 => $fotoPath,
                'telefono_personal'    => $validated['telefono_personal'],
                'telefono_corporativo' => $validated['telefono_corporativo'],
                'correo_corporativo'   => $validated['correo_corporativo'],
                'cargo'                => $validated['cargo'],
                'numero_documento'     => $validated['numero_documento'],
                'rol_id'               => $validated['rol_id'],
                'area_encargada_id'    => $validated['area_encargada_id'],
                'equipo_id'            => $validated['equipo_id'],
                'tipo_documento_id'    => $validated['tipo_documento_id'],
                'update_by'            => auth()->id(),
            ]);
        });

        return back()->with('success', 'Miembro del equipo actualizado exitosamente.');

    } catch (\Exception $e) {
        return back()->withErrors(['error' => 'Error al actualizar: ' . $e->getMessage()])->withInput();
    }
}

    public function destroy($id): RedirectResponse
{
    $organizador = PerfilOrganizador::findOrFail($id);

    $usuario = $organizador->usuario;

    $organizador->delete();

    if ($usuario) {
        $usuario->delete();
    }

    return back()->with('success', 'Miembro del equipo eliminado correctamente.');
}

}