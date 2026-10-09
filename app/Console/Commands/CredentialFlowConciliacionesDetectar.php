<?php

namespace App\Console\Commands;

use App\Support\CredentialFlow\Conciliaciones\DetectorConciliaciones;
use Illuminate\Console\Command;
use Throwable;

class CredentialFlowConciliacionesDetectar extends Command
{
    protected $signature = 'credential-flow:conciliaciones:detectar {--simular : Calcula los casos sin escribir nada}';

    protected $description = 'Detecta los casos de conciliación histórica (idempotente). Escribe solo en cf_conciliaciones*; no modifica el histórico.';

    public function handle(DetectorConciliaciones $detector): int
    {
        $simular = (bool) $this->option('simular');
        try {
            $r = $detector->ejecutar($simular);
        } catch (Throwable $e) {
            $this->error('No se pudo completar la detección ('.$e::class.').');

            return self::FAILURE;
        }

        $this->info($simular ? 'SIMULACIÓN (no se escribió nada).' : 'Detección completada.');
        $this->table(['tipo', 'detectados', 'nuevos', 'existentes', 'relaciones agregadas'], collect($r['por_tipo'])->map(fn ($v, $t) => [$t, $v['detectados'], $v['nuevos'], $v['existentes'], $v['relaciones_agregadas']])->values()->all());
        if ($r['omitidos']['solo_codigo_nulo_vs_valor'] > 0) {
            $this->line(sprintf('Omitidos (variantes que solo difieren en código NULL contra valor; no son conflicto de contenido): %d, de los cuales %d ya tenían un caso (no se tocan).', $r['omitidos']['solo_codigo_nulo_vs_valor'], $r['omitidos']['con_caso_existente']));
        }
        $this->line(sprintf('Total: %d detectados · %d nuevos · %d existentes · %d relaciones agregadas', $r['total']['detectados'], $r['total']['nuevos'], $r['total']['existentes'], $r['total']['relaciones_agregadas']));

        return self::SUCCESS;
    }
}
