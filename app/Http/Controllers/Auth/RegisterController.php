<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Auth\Events\Registered;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Inertia\Response;
use Illuminate\Validation\ValidationException;


class RegisterController extends Controller
{

    public function showRegisterForm(): Response
    {
        return Inertia::render('Auth/Register');
    }


    public function registerStore(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        // Blindaje: Asignamos el rol más básico automáticamente
        $defaultRole = Role::where('name', 'cliente')->first();

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role_id' => $defaultRole->id,
            'is_active' => true,
        ]);

        event(new Registered($user)); // Dispara el envío de email de verificación

        auth()->login($user);

        return redirect('/dashboard');
    }
}