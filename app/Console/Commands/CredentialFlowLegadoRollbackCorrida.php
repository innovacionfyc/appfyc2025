<?php

namespace App\Console\Commands;

use App\Support\CredentialFlow\Migracion\RollbackCorrida;
use App\Support\CredentialFlow\Migracion\RollbackNoPermitido;
use Illuminate\Console\Command;
use Throwable;

class CredentialFlowLegadoRollbackCorrida extends Command
{
    protected $signature = 'credential-flow:legado:rollback-corrida {id : Id de la corrida} {--confirmar : Confirma el rollback}';

    protected $description = 'ROLLBACK TÉCNICO de una corrida de migración histórica completada (solo si no hay actividad posterior). No toca el staging.';

    public function handle(): int
    {
        if (! $this->option('confirmar')) {
            $this->warn('Esto borra TODO lo creado por la corrida #'.$this->argument('id').' (eventos, plantillas, certificados históricos, correos y descargas migrados). Repite con --confirmar para continuar.');

            return self::FAILURE;
        }

        try {
            $r = (new RollbackCorrida)->revertir((int) $this->argument('id'));
        } catch (RollbackNoPermitido $e) {
            $this->error($e->getMessage());
            $this->line('No se tocó nada.');

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('No se pudo revertir ('.$e::class.'). No se tocó nada.');

            return self::FAILURE;
        }

        $this->info('Corrida #'.$this->argument('id').' revertida.');
        foreach ($r as $tabla => $n) {
            $this->line(sprintf('  %-24s %d', $tabla, $n));
        }

        return self::SUCCESS;
    }
}
