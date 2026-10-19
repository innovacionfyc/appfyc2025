<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Models\CredentialFlow\ContactoQa;
use App\Support\CredentialFlow\Qa\AdministradorContactosQa;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Pantalla «Contactos QA»: los contactos de control oficiales del equipo, su verificación y las bases donde está cada uno. Todo cambio pide motivo y
 * confirmación y queda en la bitácora sin datos personales completos. No depende de comandos ni de SSH.
 */
class QaContactosController extends Controller
{
    public function __construct(private readonly AdministradorContactosQa $contactos) {}

    public function index(Request $request): Response
    {
        $disponible = ServicioQa::disponible();
        $panorama = $this->contactos->panorama();
        $catalogoVacio = $panorama === [];

        $candidatas = $disponible && $catalogoVacio ? $this->contactos->basesQaCandidatas() : [];
        $seleccion = array_values(array_filter(array_map('intval', (array) $request->query('bases', []))));

        return Inertia::render('CredentialFlow/Qa/Contactos', [
            'disponible' => $disponible,
            'catalogo' => $this->contactos->catalogoListo(),
            'contactos' => $panorama,
            'resumen' => [
                'total' => count($panorama),
                'activos' => count(array_filter($panorama, fn ($c) => $c['activo'])),
                'verificados' => count(array_filter($panorama, fn ($c) => $c['verificado'])),
                'esperados' => AdministradorContactosQa::esperados(),
            ],
            'inicializacion' => [
                'disponible' => $disponible && $catalogoVacio,
                'candidatas' => $candidatas,
                'seleccion' => $seleccion,
                // La verificación se calcula al elegir las bases (no escribe nada); sin selección no hay veredicto.
                'verificacion' => $seleccion === [] ? null : $this->contactos->verificarBases($seleccion),
            ],
        ]);
    }

    public function update(Request $request, ContactoQa $contacto): RedirectResponse
    {
        $d = $request->validate([
            'nombre_completo' => ['required', 'string', 'max:200'], 'documento' => ['required', 'string', 'max:100'], 'correo' => ['required', 'string', 'max:300'],
            'motivo' => ['required', 'string', 'max:255'], 'confirmo' => ['accepted'],
        ], ['confirmo.accepted' => 'Confirma el cambio para continuar.']);

        try {
            $this->contactos->actualizar($contacto, $d['nombre_completo'], $d['documento'], $d['correo'], $d['motivo'], true, $request->user()?->id);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'El contacto se corrigió. Las bases que ya lo tienen no cambian; las nuevas usarán estos datos.');
    }

    public function activo(Request $request, ContactoQa $contacto): RedirectResponse
    {
        $d = $request->validate(['activo' => ['required', 'boolean'], 'motivo' => ['required', 'string', 'max:255'], 'confirmo' => ['accepted']], ['confirmo.accepted' => 'Confirma el cambio para continuar.']);

        try {
            $this->contactos->cambiarActivo($contacto, (bool) $d['activo'], $d['motivo'], true);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $d['activo'] ? 'El contacto se activó: entrará a las bases nuevas.' : 'El contacto se desactivó: ya no entrará a las bases nuevas (las existentes no cambian).');
    }

    public function inicializar(Request $request): RedirectResponse
    {
        $d = $request->validate(['bases' => ['required', 'array', 'min:1'], 'bases.*' => ['integer'], 'motivo' => ['required', 'string', 'max:255'], 'confirmo' => ['accepted']], ['confirmo.accepted' => 'Confirma el cambio para continuar.']);

        try {
            $r = $this->contactos->inicializar($d['bases'], $d['motivo'], true);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return to_route('credential-flow.qa.contactos')->with('success', "Se cargaron {$r['creados']} contactos de control desde las bases oficiales.");
    }
}
