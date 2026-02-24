<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AreaFormacion;
use App\Models\Estado;
use App\Models\FormularioBase;
use App\Models\PerfilConferencista;
use Inertia\Inertia;
use Inertia\Response;


class AdminController extends Controller
{
    public function show(): Response
    {
        $estados = Estado::all();
        $areas = AreaFormacion::all();
        return Inertia::render('Dashboard/SuperAdmin', [
            'estados' => $estados,
            'areas' => $areas,
            'conferencistas' => PerfilConferencista::with('areaEncargada')->get(),
            'formularios' => FormularioBase::all(),
        ]);
    }

}