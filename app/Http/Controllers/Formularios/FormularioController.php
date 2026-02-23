<?php

namespace App\Http\Controllers\Formularios;

use App\Http\Controllers\Controller;
use App\Models\FormularioBase;
use App\Models\FormularioInscripcion;
use Illuminate\Http\Request;

class FormularioController extends Controller
{
    public function store(Request $request)
    {
        // Validamos la configuración de la plantilla
        $validated = $request->validate([
            'nombre_plantilla'     => 'required|string|max:255',
            'tipo_persona'         => 'required|string|in:Natural,Jurídica,Ambas',
            
            // Configuraciones de campos (booleanos)
            'solicitar_cargo'      => 'boolean',
            'solicitar_empresa'    => 'boolean',
            'solicitar_correo_corp'=> 'boolean',
            'solicitar_soporte'    => 'boolean',
            'politica_datos'       => 'boolean',
        ]);

        // Aseguramos que los booleanos que no vengan en el request sean false
        $configuracion = [
            'nombre_plantilla'      => $validated['nombre_plantilla'],
            'tipo_persona'          => $validated['tipo_persona'],
            'solicitar_cargo'       => $request->boolean('solicitar_cargo'),
            'solicitar_empresa'     => $request->boolean('solicitar_empresa'),
            'solicitar_correo_corp' => $request->boolean('solicitar_correo_corp'),
            'solicitar_soporte'     => $request->boolean('solicitar_soporte'),
            'politica_datos'        => true, // Obligatorio por ley
        ];

        FormularioBase::create($configuracion);

        return redirect()->back()->with('success', 'Plantilla de formulario creada con éxito.');
    }
}