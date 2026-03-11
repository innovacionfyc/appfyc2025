<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Movimiento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function showLoginForm(): Response
    {
        return Inertia::render('Auth/login');
    }

    public function login(Request $request)
    {

        $credentials = $request->validate([
            'correo_principal' => ['required', 'email', 'exists:usuarios,correo_principal'],
            'contrasena' => ['required', 'string'],
        ], [
            'correo_principal.required' => 'El correo es obligatorio.',
            'correo_principal.email' => 'El correo ingresado debe ser válido.',
            'correo_principal.exists' => 'Las credenciales proporcionadas no coinciden con nuestros registros.',
            'contrasena.required' => 'Debes ingresar tu contraseña.'
        ]);


        $attemptCredentials = [
            'correo_principal' => $credentials['correo_principal'],
            'password' => $credentials['contrasena'],
        ];

        if (Auth::attempt($attemptCredentials, $request->boolean('recordar'))) {
            $user = Auth::user();

            $user->loadMissing('estado');
            if ($user->estado && strtolower($user->estado->tipo_estado) !== 'activo') {
                Auth::logout();
                throw ValidationException::withMessages([
                    'correo_principal' => 'Esta cuenta se encuentra inactiva.',
                ]);
            }

            Movimiento::registrar(
                tipo: 'sistema',
                modulo: 'autenticacion',
                descripcion: "Inicio de sesión exitoso en la plataforma administrativa."
            );

            $user->update(['ultima_sesion' => now()]);
            $request->session()->regenerate();

            return $this->redirectByRole($user);
        }

        Movimiento::create([
            'user_id' => null,
            'tipo' => 'sistema',
            'modulo' => 'autenticacion',
            'descripcion' => "Intento fallido de inicio de sesión para el correo: " . $request->correo_principal,
            'metadata' => ['ip' => $request->ip(), 'ua' => $request->userAgent()]
        ]);

        return redirect('/login')->with('error', 'Las credenciales proporcionadas no coinciden con nuestros registros.');
        

    }

    public function redirectByRole($user = null)
    {
        $user = $user ?? Auth::user();

        if (!$user) {
            return redirect()->route('login');
        }

        $user->loadMissing(['perfilOrganizador.rol', 'perfilConferencista']);

        if ($user->perfilOrganizador && $user->perfilOrganizador->rol) {
            $rolSlug = $user->perfilOrganizador->rol->slug;

            return match ($rolSlug) {
                'super-admin', 'admin' => redirect()->route('admin.dashboard')
                    ->with('success', '¡Bienvenido nuevamente, ' . $user->perfilOrganizador->primer_nombre . '!'),
                'comercial' => redirect()->route('commercial.dashboard'),
                default => redirect()->route('home.index'),
            };
        }

        if ($user->perfilConferencista) {
            return redirect()->route('speaker.dashboard');
        }

        return redirect()->route('home.index');
    }

    public function logout(Request $request)
    {
        if (Auth::check()) {
            Movimiento::registrar(
                tipo: 'sistema',
                modulo: 'autenticacion',
                descripcion: "El usuario finalizó su sesión de forma segura."
            );
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login')->with('success', 'Sesión cerrada. ¡Vuelve pronto!');
    }
}