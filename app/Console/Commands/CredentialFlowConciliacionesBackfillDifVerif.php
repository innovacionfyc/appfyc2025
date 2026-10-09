<?php

namespace App\Console\Commands;

use App\Support\CredentialFlow\Conciliaciones\BackfillDifVerif;
use App\Support\CredentialFlow\Rehearsal\GuardiaClave;
use Illuminate\Console\Command;
use Throwable;

/** Backfill explícito, determinista e idempotente de los casos DIF_VERIF que necesita 10B-2A (Fase 11A). Escribe solo en cf_conciliaciones*; no modifica el histórico. */
class CredentialFlowConciliacionesBackfillDifVerif extends Command
{
    protected $signature = 'credential-flow:conciliaciones:backfill-dif-verif
        {--simular : Calcula los casos sin escribir nada}
        {--esperado= : Número exacto de casos esperado; distinto ⇒ NO-GO}
        {--revertir : Elimina SOLO los casos creados por este backfill que siguen intactos}
        {--json : Salida en JSON}';

    protected $description = 'Reconstruye (idempotente) los casos DIF_VERIF históricos de 10B-2A por reglas, sin ids fijos ni tocar el detector general.';

    public function handle(BackfillDifVerif $backfill): int
    {
        try {
            if ($this->option('revertir')) {
                $r = $backfill->revertir();
                $this->line($this->option('json') ? (string) json_encode($r) : 'revertidos '.$r['revertidos'].' · conservados '.$r['conservados']);

                return self::SUCCESS;
            }
            $r = $backfill->ejecutar((bool) $this->option('simular'));
            $ok = $this->option('esperado') === null || (int) $this->option('esperado') === $r['detectados'];
            $this->line($this->option('json') ? (string) json_encode($r + ['ok' => $ok]) : sprintf(
                'DIF_VERIF: %d detectados · %d nuevos · %d existentes · %d certificados · %d relaciones agregadas%s',
                $r['detectados'], $r['nuevos'], $r['existentes'], $r['certificados'], $r['relaciones_agregadas'], $ok ? '' : ' · NO-GO: se esperaban '.$this->option('esperado')
            ));

            return $ok ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->error($e->getMessage() === GuardiaClave::CODIGO ? GuardiaClave::CODIGO : 'BACKFILL_FALLIDO: '.$e::class);

            return self::FAILURE;
        }
    }
}
