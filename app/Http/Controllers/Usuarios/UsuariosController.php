<?php

namespace App\Http\Controllers\Usuarios;

use App\Http\Controllers\Controller;
use App\Models\AreaFormacion;
use App\Models\EquipoFyc;
use App\Models\Estado;
use App\Models\Evento;
use App\Models\PerfilConferencista;
use App\Models\PerfilOrganizador;
use App\Models\Rol;
use App\Models\TipoDocumento;
use Inertia\Inertia;
use Inertia\Response;


class UsuariosController extends Controller
{


public function show(): Response
{

    $organizadores = PerfilOrganizador::with(['usuario', 'rol', 'equipo'])->get();
    

    $conferencistas = PerfilConferencista::with('areaEncargada')->get();
    
    $estados = Estado::all();
    $areas = AreaFormacion::all();

    return Inertia::render('Usuarios/Usuarios', [
        'organizadores' => $organizadores,
        'conferencistas' => $conferencistas,
        'estados' => $estados,
        'areas' => $areas,
        'stats_counts' => [ 
            'total_equipo' => $organizadores->count(),
            'total_conferencistas' => $conferencistas->count(),
            'eventos_activos' => Evento::where('estado_id', 1)->count(),
        ],
        'roles'          => Rol::all(),
        'equipos'        => EquipoFyc::all(),
        'tiposDocumento' => TipoDocumento::all(),
    ]);
}

}