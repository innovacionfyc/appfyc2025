<?php

namespace App\Providers;

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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

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
