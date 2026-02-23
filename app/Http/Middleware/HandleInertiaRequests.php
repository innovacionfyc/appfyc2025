<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $usuario = $request->user();
        $rol = null;

        if ($usuario) {
            $usuario->loadMissing([
                'estado',
                'perfilOrganizador.rol',
                'perfilOrganizador.equipo',
                'perfilOrganizador.areaEncargada',
                'perfilOrganizador.tipoDocumento',
                'perfilConferencista.areaEncargada',
            ]);

            if ($usuario->perfilOrganizador && $usuario->perfilOrganizador->rol) {
                $rol = $usuario->perfilOrganizador->rol->slug;
            } elseif ($usuario->perfilConferencista) {
                $rol = 'conferencista';
            }

            $usuario->rol = $rol;
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
        ];
    }
}
