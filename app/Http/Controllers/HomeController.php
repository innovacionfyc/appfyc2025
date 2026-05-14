<?php

namespace App\Http\Controllers;

use App\Models\Evento;
use Inertia\Inertia;
use Carbon\Carbon;

class HomeController extends Controller
{
    /**
     * Muestra la página de bienvenida.
     */
    public function show()
    {

        $eventosDb = Evento::with(['estado', 'areaFormacion'])
            ->orderBy('fecha_hora_inicio', 'asc')
            ->get();


        $eventosMapeados = $eventosDb->map(function ($evento) {

            $fecha_inicio = Carbon::parse($evento->fecha_hora_inicio)->locale('es')->isoFormat('D MMMM YYYY');
            $fecha_inicio = ucfirst($fecha_inicio);

            $fecha_fin = Carbon::parse($evento->fecha_hora_fin)->locale('es')->isoFormat('D MMMM YYYY');
            $fecha_fin = ucfirst($fecha_fin);

            return [
                'id' => $evento->id,
                'title' => $evento->titulo,
                'mode' => $evento->modo_evento,
                'subtitle' => $evento->subtitulo ?? $evento->areaFormacion->nombre,
                'date_in' => $fecha_inicio,
                'date_on' => $fecha_fin,
                'mode_event' => $evento->modalidad === 'Virtual' ? 'Virtual' : ($evento->modalidad ?? 'Por definir'),
                'city' => $evento->modalidad === 'Virtual' ? 'Virtual' : ($evento->ubicacion ?? 'Por definir'),

                'imageThumb' => $evento->imagen_relacionada ? '/storage/' . $evento->imagen_relacionada : '/images/default-bg.webp',
                'imageBg' => $evento->imagen_relacionada ? '/storage/' . $evento->imagen_relacionada : '/images/default-bg.webp',
                'cta_text' => 'Inscribirme',
                'cta_url' => route('evento.show', $evento->id),
                'badge' => $evento->modalidad,
                'rating' => 5,
                'hex_principal' => $evento->areaFormacion->color_hex_principal,
                'area' => $evento->areaFormacion->nombre
            ];
        });


        if ($eventosMapeados->isEmpty()) {

            $eventosMapeados = [];
        }

        return Inertia::render('Home/Welcome', [
            'eventosHero' => $eventosMapeados
        ]);
    }

    public function showPlantilla($id)
    {

        $evento = Evento::with([
            'areaFormacion',
            'conferencistas',
            'contenidoTematico',
            'formularioBase',
            'organizador',
            'organizador.perfilOrganizador'

        ])->findOrFail($id);

        return Inertia::render('Home/Plantilla', [
            'evento' => $evento
        ]);
    }
}