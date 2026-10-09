<?php

namespace App\Console\Commands;

use App\Support\CredentialFlow\Migracion\RollbackEncuestas;
use App\Support\CredentialFlow\Migracion\RollbackNoPermitido;
use Illuminate\Console\Command;
use Throwable;

class CredentialFlowEncuestasRollbackCorrida extends Command
{
    protected $signature = 'credential-flow:encuestas:rollback-corrida {id : Id de la corrida de encuestas} {--confirmar : Confirma el rollback}';

    protected $description = 'ROLLBACK TÉCNICO de una corrida de encuestas históricas completada (solo si no hay actividad posterior). No toca el staging ni los certificados.';

    public function handle(): int
    {
        if (! $this->option('confirmar')) {
            $this->warn('Esto borra TODO lo creado por la corrida de encuestas #'.$this->argument('id').' (respuestas, detalles, versiones, preguntas y opciones históricas). Repite con --confirmar para continuar.');

            return self::FAILURE;
        }

        try {
            $r = (new RollbackEncuestas)->revertir((int) $this->argument('id'));
        } catch (RollbackNoPermitido $e) {
            $this->error($e->getMessage());
            $this->line('No se tocó nada.');

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('No se pudo revertir ('.$e::class.'). No se tocó nada.');

            return self::FAILURE;
        }

        $this->info('Corrida de encuestas #'.$this->argument('id').' revertida.');
        foreach ($r as $tabla => $n) {
            $this->line(sprintf('  %-24s %d', $tabla, $n));
        }

        return self::SUCCESS;
    }
}
