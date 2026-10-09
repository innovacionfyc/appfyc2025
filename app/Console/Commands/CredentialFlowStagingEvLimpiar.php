<?php

namespace App\Console\Commands;

use App\Support\CredentialFlow\StagingEv\GuardiaStaging;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CredentialFlowStagingEvLimpiar extends Command
{
    protected $signature = 'credential-flow:staging-ev:limpiar {--confirmar : Confirma el borrado de TODO el staging}';

    protected $description = 'Vacía por completo el staging histórico (stg_ev_*) para poder recargarlo desde cero. No toca tablas finales.';

    /** Hijas antes que padres (las columnas de snapshot apuntan a stg_ev_snapshots). */
    private const ORDEN = [
        'stg_ev_historial', 'stg_ev_participante_correos', 'stg_ev_imagenes', 'stg_ev_opciones', 'stg_ev_preguntas', 'stg_ev_encuesta', 'stg_ev_descargas',
        'stg_ev_token', 'stg_ev_participante', 'stg_ev_duplicados', 'stg_ev_evento', 'stg_ev_snapshots',
    ];

    public function handle(): int
    {
        try {
            GuardiaStaging::exigirDestinoLocal();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if (! $this->option('confirmar')) {
            $this->warn('Esto borra TODAS las filas del staging histórico. Repite con --confirmar para continuar.');

            return self::FAILURE;
        }
        if (! Schema::hasTable('stg_ev_snapshots')) {
            $this->error('No hay tablas de staging.');

            return self::FAILURE;
        }

        DB::transaction(function () {
            foreach (self::ORDEN as $tabla) {
                $n = DB::table($tabla)->delete();
                $this->line(sprintf('  %-22s %d filas borradas', $tabla, $n));
            }
        });
        $this->info('Staging vacío. Para borrar también las tablas: php artisan migrate:rollback --path=database/staging/ev --force');

        return self::SUCCESS;
    }
}
