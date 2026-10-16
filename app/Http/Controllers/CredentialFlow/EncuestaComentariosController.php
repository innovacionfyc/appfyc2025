<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Support\CredentialFlow\Encuestas\InformeEncuestas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vista RESTRINGIDA de comentarios completos (texto libre). La ruta exige el rol super-admin. Cada consulta deja un registro de acceso (usuario, filtros y página)
 * SIN el contenido de los comentarios. No muestra documentos, correos ni identificadores de la persona: solo evento, versión, pregunta, fecha y el texto.
 */
class EncuestaComentariosController extends Controller
{
    public function __construct(private readonly InformeEncuestas $informe) {}

    public function index(Request $request): Response
    {
        $f = InformeEncuestas::filtros($request->query());
        $pagina = max(1, (int) $request->query('page', 1));
        $comentarios = $this->informe->comentarios($f, $pagina)->withPath(route('credential-flow.encuestas.comentarios'))->appends($request->query());

        Log::info('Credential Flow: consulta de comentarios de encuestas.', [
            'usuario' => $request->user()?->getAuthIdentifier(), 'pagina' => $pagina, 'filtros' => array_filter($f, fn ($v) => $v !== null), 'resultados_en_pagina' => count($comentarios->items()),
        ]);

        return Inertia::render('CredentialFlow/Encuestas/Comentarios', ['filtros' => $f, 'opciones' => $this->informe->opciones(), 'comentarios' => $comentarios]);
    }
}
