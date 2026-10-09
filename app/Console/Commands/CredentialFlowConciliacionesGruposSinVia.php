<?php

namespace App\Console\Commands;

use App\Support\CredentialFlow\Conciliaciones\DetectorGruposSinVia;
use Illuminate\Console\Command;
use Throwable;

/** Detecta (idempotente) los casos administrativos de grupos sin vía de documentos parcialmente accesibles. Solo escribe en cf_conciliaciones*; NO concede acceso. */
class CredentialFlowConciliacionesGruposSinVia extends Command
{
    protected $signature = 'credential-flow:conciliaciones:grupos-sin-via {--simular : Calcula los casos sin escribir nada}';

    protected $description = 'Detecta los casos de grupos sin vía (correo compartido, evidencia externa, sin correo). Idempotente; no modifica el histórico ni concede acceso.';

    public function handle(DetectorGruposSinVia $detector): int
    {
        $simular = (bool) $this->option('simular');
        try {
            $r = $detector->ejecutar($simular);
        } catch (Throwable $e) {
            $this->error('No se pudo completar la detección ('.$e::class.').');

            return self::FAILURE;
        }
        $this->info($simular ? 'SIMULACIÓN (no se escribió nada).' : 'Detección completada.');
        $this->table(['motivo', 'casos detectados', 'nuevos', 'existentes', 'grupos', 'relaciones agregadas'], collect($r['por_motivo'])->map(fn ($v, $m) => [$m, $v['detectados'], $v['nuevos'], $v['existentes'], $v['grupos'], $v['relaciones_agregadas']])->values()->all());
        $this->line('Sin caso de identidad (dependen de su plantilla, flujo 10B-1): '.$r['sin_caso']['sin_fila_habilitante'].' grupo(s).');

        return self::SUCCESS;
    }
}
