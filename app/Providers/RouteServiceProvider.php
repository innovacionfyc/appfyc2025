<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/dashboard';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
{
    $this->configureRateLimiting();

    $this->routes(function () {
        Route::middleware('api')
            ->prefix('api')
            ->group(base_path('routes/api.php'));

        // Aquí cargamos TODO lo que necesita el middleware 'web' (sesiones, cookies, etc.)
        Route::middleware('web')
            ->group(function () {
                // Carga las rutas generales (welcome, auth, etc.)
                require base_path('routes/web.php');

                // Carga las rutas de tu módulo Core
                // ASEGÚRATE DE QUE ESTA RUTA SEA LA CORRECTA PARA TU ARCHIVO
                require base_path('routes/web/Apps/Core/EstructuraRutas/Dashboard.php');

                // Carga las rutas de tu módulo RePOS
                // ASEGÚRATE DE QUE ESTA RUTA SEA LA CORRECTA
                require base_path('routes/web/Apps/RePOS/EstructuraRutas/Dashboard.php');
            });
    });
}

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(optional($request->user())->id ?: $request->ip());
        });
    }
}