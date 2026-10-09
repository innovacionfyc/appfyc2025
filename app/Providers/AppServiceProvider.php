<?php

namespace App\Providers;

use App\Http\Controllers\CredentialFlow\VerificacionPublicaController;
use App\Support\CredentialFlow\Legado\CongeladorCertificadoLegado;
use App\Support\CredentialFlow\Legado\CongeladorLegado;
use App\Support\CredentialFlow\Legado\ResolutorPlantillaLegado;
use App\Support\CredentialFlow\Legado\ResolutorPlantillaStorage;
use App\Support\PodcastVisitante;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Credential Flow histórico: el congelado perezoso de PDF y de dónde sale la imagen de fondo (storage privado definitivo).
        $this->app->bind(ResolutorPlantillaLegado::class, ResolutorPlantillaStorage::class);
        $this->app->bind(CongeladorCertificadoLegado::class, CongeladorLegado::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Verificación pública de credenciales: límite general por IP (holgado para móviles). El límite de consultas
        // fallidas por IP vive en VerificacionPublicaController.
        RateLimiter::for('cf-verificacion', fn (Request $request) => Limit::perMinute((int) config('credential_flow.verificacion.limite_por_minuto'))
            ->by('cf-verificacion:'.$request->ip())
            ->response(fn (Request $request, array $cabeceras) => VerificacionPublicaController::limitada($request, (int) ($cabeceras['Retry-After'] ?? 60), $cabeceras)));

        // Interacciones anónimas del podcast: límite por IP y, si existe, por visitante (cookie).
        RateLimiter::for('podcast-reacciones', function (Request $request) {
            $limites = [Limit::perMinute(30)->by('ip:'.$request->ip())];

            if ($uuid = PodcastVisitante::uuidDesde($request)) {
                $limites[] = Limit::perMinute(30)->by('visitante:'.$uuid);
            }

            return $limites;
        });

        // Envío de comentarios: pocos por ventana, por IP y por visitante. Los aprobados se moderan aparte.
        RateLimiter::for('podcast-comentarios', function (Request $request) {
            $limites = [Limit::perMinutes(10, 3)->by('ip:'.$request->ip())];

            if ($uuid = PodcastVisitante::uuidDesde($request)) {
                $limites[] = Limit::perMinutes(10, 3)->by('visitante:'.$uuid);
            }

            return $limites;
        });
    }
}
