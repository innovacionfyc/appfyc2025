<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AreaFormacion;
use App\Models\Estado;
use App\Models\Evento;
use App\Models\FormularioBase;
use App\Models\Movimiento;
use App\Models\PerfilConferencista;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;


class AdminController extends Controller
{
    public function show(): Response
    {
        $stats_counts = [
            'eventos_activos' => Evento::where('estado_id', '1')->count(),
            'conferencistas' => PerfilConferencista::count(),
            'inscripciones' => DB::table('formularios_inscripcion')->count(),
            'eventos_recientes' => Evento::where('created_at', '>=', now()->subDays(7))->count()
        ];

        $movimientos = Movimiento::with([
            'usuario:id',
            'usuario.perfilOrganizador:id,usuario_id,primer_nombre'
        ])
            ->latest()
            ->take(5)
            ->get()
            ->map(fn($m) => [
                'id' => $m->id,
                'user' => ($m->usuario && $m->usuario->perfilOrganizador)
                    ? $m->usuario->perfilOrganizador->primer_nombre
                    : 'Sistema',
                'descripcion' => $m->descripcion,
                'tiempo' => $m->created_at->diffForHumans(),
                'tipo' => $m->tipo
            ]);

        $estados = Estado::all();
        $areas = AreaFormacion::all();
        return Inertia::render('Dashboard/SuperAdmin', [
            'estados' => $estados,
            'areas' => $areas,
            'conferencistas' => PerfilConferencista::with('areaEncargada')->get(),
            'formularios' => FormularioBase::all(),
            'stats_counts' => $stats_counts,
            'movimientos' => $movimientos
        ]);
    }

}