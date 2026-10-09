<?php

namespace App\Console\Commands;

use App\Support\CredentialFlow\Conciliaciones\RollbackConciliaciones;
use App\Support\CredentialFlow\Migracion\RollbackNoPermitido;
use Illuminate\Console\Command;
use Throwable;

class CredentialFlowConciliacionesRollback extends Command
{
    protected $signature = 'credential-flow:conciliaciones:rollback {--confirmar : Confirma el rollback}';

    protected $description = 'ROLLBACK TÉCNICO de la detección (Fase 10A): elimina los casos detectados SOLO si no hay decisiones ni eventos posteriores. No toca el histórico.';

    public function handle(RollbackConciliaciones $rollback): int
    {
        if (! $this->option('confirmar')) {
            $this->warn('Esto elimina TODOS los casos de conciliación detectados (casos, certificados afectados y bitácora). No toca el histórico. Repite con --confirmar para continuar.');

            return self::FAILURE;
        }

        try {
            $r = $rollback->revertir();
        } catch (RollbackNoPermitido $e) {
            $this->error($e->getMessage());
            $this->line('No se tocó nada.');

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('No se pudo revertir ('.$e::class.'). No se tocó nada.');

            return self::FAILURE;
        }

        $this->info('Conciliaciones revertidas.');
        foreach ($r as $tabla => $n) {
            $this->line(sprintf('  %-14s %d', $tabla, $n));
        }

        return self::SUCCESS;
    }
}
