<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $usuario = $request->user();

        if ($usuario) {
            $usuario->loadMissing([
                'estado',
                'perfilOrganizador.rol',
                'perfilOrganizador.equipo',
                'perfilOrganizador.areaEncargada',
                'perfilOrganizador.tipoDocumento',
                'perfilConferencista.areaEncargada',
            ]);


        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $usuario,
            ],
            'ziggy' => fn() => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
            'flash' => [
                'success' => fn() => $request->session()->get('success'),
                'error' => fn() => $request->session()->get('error'),
            ],
        ];
    }
}