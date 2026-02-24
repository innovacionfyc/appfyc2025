<?php

namespace App\Http\Controllers\Formularios;


use App\Http\Controllers\Controller;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InscripcionController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'evento_id'           => 'required|exists:eventos,id',
            'tipo_persona'        => 'required|string|in:Natural,Jurídica',
            'nombres'             => 'required|string|max:255',
            'apellidos'           => 'required|string|max:255',
            'cedula'              => 'required|string|max:20',
            'cargo'               => 'nullable|string|max:255',
            'entidad_empresa'     => 'nullable|string|max:255',
            'celular'             => 'required|string|max:20',
            'ciudad'              => 'required|string|max:100',
            'correo_personal'     => 'required|email|max:255',
            'correo_corporativo'  => 'nullable|email|max:255',
            'modo_asistencia'     => 'required|string',
            'politica_datos'      => 'accepted', // Obligatorio aceptar
            'medio_reconocimiento'=> 'nullable|string',
        ]);

        try {
            DB::table('formularios_inscripcion')->insert([
                'evento_id'          => $validated['evento_id'], // Ajustado para vincular
                'tipo_persona'       => $validated['tipo_persona'],
                'nombres'            => $validated['nombres'],
                'apellidos'          => $validated['apellidos'],
                'cedula'             => $validated['cedula'],
                'cargo'              => $validated['cargo'],
                'entidad_empresa'    => $validated['entidad_empresa'],
                'celular'            => $validated['celular'],
                'ciudad'             => $validated['ciudad'],
                'correo_personal'    => $validated['correo_personal'],
                'correo_corporativo' => $validated['correo_corporativo'],
                'modo_asistencia'    => $validated['modo_asistencia'],
                'politica_datos'     => true,
                'medio_reconocimiento' => $validated['medio_reconocimiento'],
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);

            return redirect()->back()->with('success', '¡Inscripción realizada con éxito! Pronto nos contactaremos.');

        } catch (\Exception $e) {
            Log::error("Error en inscripción: " . $e->getMessage());
            return back()->withErrors(['general' => 'Hubo un error al procesar tu inscripción.']);
        }
    }
}