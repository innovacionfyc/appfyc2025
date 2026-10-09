<?php

namespace App\Console\Commands;

use App\Support\CredentialFlow\Legado\PreflightCodigosHistoricos;
use Illuminate\Console\Command;
use Throwable;

class CredentialFlowCodigosHistoricosPreflight extends Command
{
    protected $signature = 'credential-flow:codigos-historicos:preflight';

    protected $description = 'Preflight del cutover (solo lectura): confirma que ningún código legado entró en el rango reservado a Credential Flow y que no hay colisiones.';

    public function handle(PreflightCodigosHistoricos $preflight): int
    {
        try {
            $r = $preflight->verificar();
        } catch (Throwable $e) {
            $this->error('No se pudo ejecutar el preflight ('.$e::class.').');

            return self::FAILURE;
        }

        foreach ($r['comprobaciones'] as $c) {
            $this->line(sprintf('[%s] %s — %s', $c['ok'] ? ' OK ' : 'FALLA', $c['nombre'], $c['detalle']));
        }
        $r['ok'] ? $this->info('PREFLIGHT DE CÓDIGOS HISTÓRICOS: OK') : $this->error('PREFLIGHT DE CÓDIGOS HISTÓRICOS: FALLÓ (no hacer el cutover).');

        return $r['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
