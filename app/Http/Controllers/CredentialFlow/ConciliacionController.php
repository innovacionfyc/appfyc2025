<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Models\CredentialFlow\Conciliacion;
use App\Support\CredentialFlow\Conciliaciones\ConsultaConciliaciones;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Histórico → Casos por revisar (Fase 10A): SOLO LECTURA. Dos rutas GET (bandeja y detalle); ninguna escribe, ni siquiera en las tablas de
 * conciliación. Mismo permiso que el resto de Credential Flow (super-admin o admin, en el grupo de rutas). El listado no muestra datos
 * personales; el detalle los muestra enmascarados.
 */
class ConciliacionController extends Controller
{
    public function __construct(private readonly ConsultaConciliaciones $consulta) {}

    public function index(Request $request): Response
    {
        $f = $request->validate([
            'tipo' => ['nullable', 'in:'.implode(',', Conciliacion::TIPOS)],
            'estado' => ['nullable', 'in:'.implode(',', Conciliacion::ESTADOS)],
            'evento' => ['nullable', 'integer', 'min:1'],
        ], [
            'tipo.in' => 'Elige un tipo de caso de la lista.',
            'estado.in' => 'Elige un estado de la lista.',
            'evento.integer' => 'Elige un evento de la lista.',
        ]);

        return Inertia::render('CredentialFlow/Historico/Casos', [
            'casos' => $this->consulta->listar($f)->withQueryString(),
            'resumen' => $this->consulta->resumen($f),
            'filtros' => $f + ['tipo' => '', 'estado' => '', 'evento' => ''],
            'opciones' => $this->consulta->opciones(),
        ]);
    }

    public function show(Conciliacion $caso): Response
    {
        return Inertia::render('CredentialFlow/Historico/Caso', ['caso' => $this->consulta->detalle($caso)]);
    }
}
