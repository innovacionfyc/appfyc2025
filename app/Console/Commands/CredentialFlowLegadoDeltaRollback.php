<?php

namespace App\Console\Commands;

use App\Support\CredentialFlow\Migracion\RollbackDelta;
use App\Support\CredentialFlow\Migracion\RollbackNoPermitido;
use Illuminate\Console\Command;
use Throwable;

class CredentialFlowLegadoDeltaRollback extends Command
{
    protected $signature = 'credential-flow:legado:delta-rollback {id : Id de la corrida delta}
        {--confirmar : Confirma el rollback}
        {--confirmo-produccion= : Excepción PRODUCTIVA (exige además la variable de proceso CREDENTIAL_FLOW_ALLOW_PRODUCTION_MIGRATION con el mismo valor exacto: «appfyc»)}';

    protected $description = 'ROLLBACK TÉCNICO de una corrida DELTA de certificados (solo si no hay actividad posterior). No toca la base ni el staging.';

    public function handle(): int
    {
        if (! $this->option('confirmar')) {
            $this->warn('Esto borra SOLO lo creado por la corrida delta #'.$this->argument('id').' (eventos, plantillas, certificados, correos y descargas del delta). Repite con --confirmar para continuar.');

            return self::FAILURE;
        }

        try {
            $confirmo = $this->option('confirmo-produccion') ? (string) $this->option('confirmo-produccion') : null;
            $r = (new RollbackDelta($confirmo, 'credential-flow:legado:delta-rollback'))->revertirLegado((int) $this->argument('id'));
        } catch (RollbackNoPermitido $e) {
            $this->error($e->getMessage());
            $this->line('No se tocó nada.');

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('No se pudo revertir ('.$e::class.'). No se tocó nada.');

            return self::FAILURE;
        }

        $this->info('Corrida delta #'.$this->argument('id').' revertida.');
        foreach ($r as $tabla => $n) {
            $this->line(sprintf('  %-24s %d', $tabla, $n));
        }

        return self::SUCCESS;
    }
}
