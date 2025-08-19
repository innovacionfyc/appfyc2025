<?php

namespace App\Http\Traits\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

trait ValidatesAndRedirectsUsers
{
    /**
     * Valida un usuario ya autenticado y lo redirige a su destino.
     *
     * @param \App\Models\User $usuario El usuario autenticado.
     * @return \Illuminate\Http\RedirectResponse
     */
    protected function validateAndRedirect(User $usuario)
    {
        // Cargamos las relaciones necesarias de una sola vez para ser eficientes.
        $usuario->load('perfilUsuario.rol', 'perfilEmpleado.establecimientos.aplicacionWeb', 'perfilEmpleado.establecimientos.token');

        if ($usuario->estado_id !== 1) { // 1 = Activo
            Auth::logout();
             return redirect()->route('login.auth')->with('error', 'Tu cuenta está inactiva o suspendida.');
        }

        if (!$usuario->perfilUsuario || !$usuario->perfilUsuario->rol) {
            Auth::logout();
           return redirect()->route('login.auth')->with('error',  'Tu usuario no tiene un perfil o rol asignado.');
        }

        $rol = $usuario->perfilUsuario->rol;
        $nombreAplicacion = null;

        if (in_array($rol->id, [1, 2, 4])) { // Admin, Empleado, SuperAdmin
            $perfilEmpleado = $usuario->perfilEmpleado;

            if (!$perfilEmpleado || !$perfilEmpleado->establecimientos) {
                Auth::logout();
                return redirect()->route('login.auth')->with('error', 'No tienes un rol válido asignado en ningún establecimientos.');
            }

            $establecimientos = $perfilEmpleado->establecimientos;

            if ($establecimientos->estado_id !== 1) {
                Auth::logout();
                return redirect()->route('login.auth')->with('error', 'El establecimientos al que perteneces está inactivo.');
            }

            if ($establecimientos->token?->estado_id !== 1) {
                Auth::logout();
               return redirect()->route('login.auth')->with('error', 'El token de tu establecimientos está inactivo.');
            }

            $ultimaFactura = $establecimientos->facturas()->latest('fecha_pago')->first();
            if ($ultimaFactura && $ultimaFactura->estado_id === 16) { // 16 = Pendiente
                Auth::logout();
               return redirect()->route('login.auth')->with('error', 'Tu establecimientos tiene un pago pendiente. Por favor, ponte al día.');
            }

            $nombreAplicacion = $establecimientos->aplicacionWeb?->nombre_app;
        } elseif ($rol->id === 3) { // Cliente
            $nombreAplicacion = 'ClienteApp';
        } else {
            Auth::logout();
            return redirect()->route('login.auth')->with('error', 'Tu rol no tiene un destino definido.');
        }

        if (!$nombreAplicacion) {
            Auth::logout();
           return redirect()->route('login.auth')->with('error', 'No se pudo determinar la aplicación para tu rol.');
        }

        $nombreCompletoUsuario = $usuario->perfilUsuario->primer_nombre . ' ' . $usuario->perfilUsuario->primer_apellido;

        return redirect()->route('aplicacion.dashboard', [
            'aplicacion' => $nombreAplicacion,
            'rol' => $rol->tipo_rol,
        ])->with('success', '¡Bienvenido nuevamente, ' . trim($nombreCompletoUsuario) . '!');
    }
}