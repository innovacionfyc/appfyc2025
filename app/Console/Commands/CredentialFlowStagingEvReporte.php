<?php

namespace App\Console\Commands;

use App\Support\CredentialFlow\StagingEv\GuardiaStaging;
use App\Support\CredentialFlow\StagingEv\ReporteConciliacion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CredentialFlowStagingEvReporte extends Command
{
    protected $signature = 'credential-flow:staging-ev:reporte {--json : Salida en JSON}';

    protected $description = 'Reporte de conciliación del staging histórico (solo lectura, SIN datos personales).';

    public function handle(): int
    {
        try {
            GuardiaStaging::exigirDestinoLocal();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if (! Schema::hasTable('stg_ev_snapshots')) {
            $this->error('Faltan las tablas de staging. Instálalas con: php artisan migrate --path=database/staging/ev --force');

            return self::FAILURE;
        }

        $reporte = (new ReporteConciliacion)->generar();
        $this->line($this->option('json')
            ? json_encode($reporte, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : ReporteConciliacion::aTexto($reporte));

        return self::SUCCESS;
    }
}
