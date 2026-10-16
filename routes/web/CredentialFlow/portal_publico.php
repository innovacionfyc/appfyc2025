<?php

use App\Http\Controllers\CredentialFlow\PortalEncuestaController;
use App\Http\Controllers\CredentialFlow\PortalPublicoController;
use App\Http\Middleware\CabecerasPortal;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\IniciarSesionPortal;
use App\Http\Middleware\LimitarEncuestaPortal;
use App\Http\Middleware\PortalAutenticado;
use App\Http\Middleware\PortalHabilitado;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

// F&C Credential Flow · portal PÚBLICO de certificados históricos (documento + correo + OTP). Sesión PROPIA (cookie
// `cf_portal_session`, solo para /certificados), CSRF activo en todos los POST, sin Inertia y sin nada del administrador.
// `PortalHabilitado` (11C.1) es la puerta única: con `credential_flow.portal_enabled` apagado ninguna de estas rutas funciona (ni sesión, ni OTP, ni correo).
Route::prefix('certificados')->name('portal.')
    ->middleware([CabecerasPortal::class, PortalHabilitado::class])
    ->withoutMiddleware([StartSession::class, HandleInertiaRequests::class])
    ->group(function () {
        Route::middleware(IniciarSesionPortal::class)->group(function () {
            Route::get('/', [PortalPublicoController::class, 'inicio'])->name('inicio');
            Route::post('/solicitar', [PortalPublicoController::class, 'solicitar'])->middleware('throttle:cf-portal-solicitud')->name('solicitar');
            Route::get('/codigo', [PortalPublicoController::class, 'codigo'])->name('codigo');
            Route::post('/codigo', [PortalPublicoController::class, 'validar'])->middleware('throttle:cf-portal-validacion')->name('validar');
            Route::post('/reenviar', [PortalPublicoController::class, 'reenviar'])->middleware('throttle:cf-portal-solicitud')->name('reenviar');
            Route::post('/salir', [PortalPublicoController::class, 'salir'])->name('salir');

            Route::middleware(PortalAutenticado::class)->group(function () {
                Route::get('/mis-certificados', [PortalPublicoController::class, 'panel'])->name('panel');
                Route::get('/{certificado}/descargar', [PortalPublicoController::class, 'descargar'])->whereNumber('certificado')->middleware('throttle:cf-portal-descarga')->name('descargar');
                // Certificados MODERNOS (emisiones oficiales): id de EMISIÓN, en un espacio de rutas propio; jamás se confunde con el id de un histórico.
                Route::get('/emision/{emision}/descargar', [PortalPublicoController::class, 'descargarModerno'])->whereNumber('emision')->middleware('throttle:cf-portal-descarga')->name('descargar-moderno');

                // Encuesta OPCIONAL (Fase 13A): nunca bloquea la descarga; mismo alcance de sesión que el panel y límite propio.
                Route::middleware(LimitarEncuestaPortal::class)->prefix('encuesta')->group(function () {
                    Route::get('/{certificado}', [PortalEncuestaController::class, 'mostrar'])->whereNumber('certificado')->name('encuesta');
                    Route::post('/{certificado}', [PortalEncuestaController::class, 'guardar'])->whereNumber('certificado')->name('encuesta.guardar');
                    Route::post('/{certificado}/mas-tarde', [PortalEncuestaController::class, 'masTarde'])->whereNumber('certificado')->name('encuesta.despues');
                });
            });
        });
    });
