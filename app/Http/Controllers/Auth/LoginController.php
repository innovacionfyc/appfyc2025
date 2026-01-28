<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function showLoginForm(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'password' => ['required', 'string'],
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            
            if (!Auth::user()->is_active) {
                Auth::logout();
                throw ValidationException::withMessages([
                    'email' => 'Cuenta inactiva.',
                ]);
            }

            $request->session()->regenerate();

            // AQUÍ LLAMAS AL MÉTODO CON $this->
            return $this->redirectByRole();
        }

        throw ValidationException::withMessages([
            'email' => __('auth.failed'),
        ]);
    }

   
    public function redirectByRole()
    {
        $user = Auth::user();

        if (!$user || !$user->role) {
            return redirect()->route('login');
        }

        return match ($user->role->name) {
            'super-admin'   => redirect()->route('admin.dashboard'),
            'comercial'     => redirect()->route('commercial.dashboard'),
            'conferencista' => redirect()->route('speaker.dashboard'),
            default         => redirect()->route('home.index'),
        };
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/');
    }
}