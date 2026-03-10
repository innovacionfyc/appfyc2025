<?php

namespace App\Http\Controllers\Usuarios;

use App\Http\Controllers\Controller;
use App\Models\AreaFormacion;
use App\Models\Estado;
use App\Models\Evento;
use App\Models\PerfilConferencista;
use App\Models\PerfilOrganizador;
use Inertia\Inertia;
use Inertia\Response;


class UsuariosController extends Controller
{
   // App/Http/Controllers/Usuarios/UsuariosController.php

public function show(): Response
{
    // Equipo Interno
    $organizadores = PerfilOrganizador::with(['usuario', 'rol', 'equipo'])->get();
    
    // Conferencistas
    $conferencistas = PerfilConferencista::with('areaEncargada')->get();
    
    $estados = Estado::all();
    $areas = AreaFormacion::all();

    return Inertia::render('Usuarios/Usuarios', [
        'organizadores' => $organizadores,
        'conferencistas' => $conferencistas,
        'estados' => $estados,
        'areas' => $areas,
        // Agregamos stats para la vista
        'stats_counts' => [ 
            'total_equipo' => $organizadores->count(),
            'total_conferencistas' => $conferencistas->count(),
            'eventos_activos' => Evento::where('estado_id', 1)->count(),
        ]
    ]);
}

}