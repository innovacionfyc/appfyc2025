<?php

use App\Http\Controllers\CredentialFlow\VerificacionPublicaController;
use App\Http\Middleware\CabecerasVerificacionPublica;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// F&C Credential Flow · verificación PÚBLICA por código de emisión (la URL que codifica el QR opcional).
// Sin login. No debe iniciar sesión ni crear cookies: se excluyen del grupo `web` las piezas que dependen de la
// sesión (cookies cifradas/encoladas, sesión, errores de sesión, XSRF e Inertia). El resto del grupo web no cambia.
Route::get('/verificar/{codigo}', [VerificacionPublicaController::class, 'mostrar'])
    ->middleware([CabecerasVerificacionPublica::class, 'throttle:cf-verificacion'])
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        ValidateCsrfToken::class,
        HandleInertiaRequests::class,
    ])
    ->name('verificacion.publica');
