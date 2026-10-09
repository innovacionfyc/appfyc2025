<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Models\CredentialFlow\Envio;
use App\Support\CredentialFlow\Envios\CatalogoEnvios;
use App\Support\CredentialFlow\Envios\ConsultaEnvios;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Credential Flow → Envíos: consulta ADMINISTRATIVA de SOLO LECTURA de los correos enviados (hoy, los códigos de acceso al portal).
 * Una única ruta GET. Mismo permiso que el resto del módulo (super-admin o admin, en el grupo de rutas). Sin reenvíos, sin acciones
 * masivas, sin edición, y sin mostrar direcciones completas, códigos, documentos ni el cuerpo de los mensajes.
 */
class EnvioController extends Controller
{
    public function index(Request $request, ConsultaEnvios $consulta): Response
    {
        $f = $request->validate([
            'estado' => ['nullable', 'in:'.implode(',', Envio::ESTADOS)],
            'tipo' => ['nullable', 'in:'.implode(',', array_keys(CatalogoEnvios::TIPOS))],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ], [
            'estado.in' => 'Elige un estado de la lista.',
            'tipo.in' => 'Elige un tipo de correo de la lista.',
            'desde.date_format' => 'La fecha inicial no es válida.',
            'hasta.date_format' => 'La fecha final no es válida.',
            'hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
        ]);

        return Inertia::render('CredentialFlow/Envios/Index', [
            'envios' => $consulta->listar($f)->withQueryString(),
            'resumen' => $consulta->resumen($f),
            'filtros' => $f + ['estado' => '', 'tipo' => '', 'desde' => '', 'hasta' => ''],
            'opciones' => [
                'estados' => collect(CatalogoEnvios::ESTADOS)->map(fn ($e, $clave) => ['valor' => $clave, 'etiqueta' => $e['etiqueta'], 'tono' => $e['tono'], 'ayuda' => $e['ayuda']])->values()->all(),
                'tipos' => collect(CatalogoEnvios::TIPOS)->map(fn ($etiqueta, $clave) => ['valor' => $clave, 'etiqueta' => $etiqueta])->values()->all(),
            ],
        ]);
    }
}
