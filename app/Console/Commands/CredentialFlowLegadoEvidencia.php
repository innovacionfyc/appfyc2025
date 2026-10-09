<?php

namespace App\Console\Commands;

use App\Support\CredentialFlow\Legado\EvidenciaPlantillas;
use App\Support\CredentialFlow\StagingEv\GuardiaStaging;
use Illuminate\Console\Command;
use Throwable;

class CredentialFlowLegadoEvidencia extends Command
{
    protected $signature = 'credential-flow:legado:evidencia {--json : Salida en JSON}';

    protected $description = 'Evidencia técnica de plantillas históricas pendientes, candidatas y huérfanas (solo lectura, sin decidir ni enlazar nada).';

    public function handle(): int
    {
        try {
            GuardiaStaging::exigirDestinoLocal();
            $e = (new EvidenciaPlantillas)->generar();
        } catch (Throwable $ex) {
            $this->error($ex->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($e, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $p = $e['eventos_pendientes'];
        $this->info("Eventos pendientes: {$p['total']} (participantes afectados: {$p['participantes_afectados']})");
        foreach ($p['detalle'] as $d) {
            $this->line(sprintf('  evento %d: %s · %d participantes', $d['old_evento_id'], $d['imagen_estado'], $d['participantes']));
        }
        $this->info('Candidatas por normalización (SIN decisión): '.count($e['candidatas']));
        foreach ($e['candidatas'] as $c) {
            $this->line(sprintf('  evento %d · %s… · similitud %s%% · %s %sx%s · %d bytes', $c['old_evento_id'], substr($c['sha256'], 0, 12), $c['similitud_nombre']['similar_text_pct'], $c['mime_real'], $c['ancho_px'], $c['alto_px'], $c['bytes']));
        }
        $this->info("Huérfanas catalogadas: {$e['huerfanas']['total']}");

        return self::SUCCESS;
    }
}
