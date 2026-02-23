<?php

namespace App\Http\Controllers\Evento;

use App\Http\Controllers\Controller;
use App\Models\Evento;
use App\Models\ContenidoTematico;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class EventoController extends Controller
{
    // Guardar el evento en la base de datos
    public function store(Request $request)
    {
        // 1. Validación de todos los campos, incluyendo el objeto dinámico de Contenido Temático
        $validated = $request->validate([
            'titulo' => 'required|string|max:255',
            'subtitulo' => 'nullable|string|max:255',
            'area_formacion_id' => 'required|exists:areas_formacion,id',
            'estado_id' => 'required|exists:estados,id',
            
            'modalidad' => 'required|string|in:Presencial,Virtual,Híbrido',
            'fecha_hora' => 'required|date',
            'ubicacion' => 'nullable|string|max:255',
            'precio_jornada' => 'nullable|numeric|min:0',
            'precio_modulo' => 'nullable|numeric|min:0',

            // Validamos que exista la plantilla base en la nueva tabla
            'formulario_inscripcion_id' => 'required|exists:formularios_base,id',
            
            // Validamos el array de IDs de conferencistas
            'conferencistas' => 'required|array', 
            'conferencistas.*' => 'exists:perfil_conferencistas,id',

            // Validamos la estructura del contenido temático dinámico
            'contenido_tematico' => 'required|array',
            'contenido_tematico.tema' => 'required|string|max:255',
            'contenido_tematico.subtemas' => 'required|array|min:1',
            'contenido_tematico.subtemas.*' => 'required|string|max:255',

            'color_hex_secundario' => 'nullable|string|max:7',
            'texto_dinamico' => 'nullable|string',
            'imagen_relacionada' => 'nullable|file|image|max:5120', // Max 2MB
            'url_folleto' => 'nullable|file|mimes:pdf|max:5120', // PDF Max 5MB
        ]);

        // 2. Manejo de Archivos (Imágenes y Folletos)
        $rutaImagen = null;
        $rutaFolleto = null;

        if ($request->hasFile('imagen_relacionada')) {
            $rutaImagen = $request->file('imagen_relacionada')->store('eventos/imagenes', 'public');
        }
        if ($request->hasFile('url_folleto')) {
            $rutaFolleto = $request->file('url_folleto')->store('eventos/folletos', 'public');
        }

        // 3. Transacción de Base de Datos
        // Garantiza que se cree el contenido temático Y el evento, o se revierta todo si hay un error.
       DB::transaction(function () use ($validated, $rutaImagen, $rutaFolleto) {
            
            // A. Creamos primero el Contenido Temático
            $contenido = ContenidoTematico::create([
                'tema' => $validated['contenido_tematico']['tema'],
                'subtemas' => $validated['contenido_tematico']['subtemas'], 
            ]);

            // B. Preparamos los datos del Evento Principal
            $evento = Evento::create([
                'organizador_id' => Auth::id(),
                'contenido_tematico_id' => $contenido->id, 
                
                // ¡AQUÍ ESTÁ LA MAGIA! Mapeamos el dato de Vue a la nueva columna de la BD
                'formulario_base_id' => $validated['formulario_inscripcion_id'], 
                
                'area_formacion_id' => $validated['area_formacion_id'],
                'estado_id' => $validated['estado_id'],
                
                'titulo' => $validated['titulo'],
                'subtitulo' => $validated['subtitulo'] ?? null,
                'modalidad' => $validated['modalidad'],
                'fecha_hora' => $validated['fecha_hora'],
                'ubicacion' => $validated['ubicacion'] ?? null,
                'precio_jornada' => $validated['precio_jornada'] ?? null,
                'precio_modulo' => $validated['precio_modulo'] ?? null,
                
                'texto_dinamico' => $validated['texto_dinamico'] ?? null,
                'color_hex_secundario' => $validated['color_hex_secundario'] ?? null,
                
                'imagen_relacionada' => $rutaImagen,
                'url_folleto' => $rutaFolleto,
            ]);

            // C. Guardamos la relación de Conferencistas en la tabla Pivote
            if (!empty($validated['conferencistas'])) {
                $evento->conferencistas()->sync($validated['conferencistas']);
            }
        });

        // 4. Retorno al Frontend
        return redirect()->back()->with('success', 'Evento corporativo creado exitosamente junto con su contenido temático.');
    }
}