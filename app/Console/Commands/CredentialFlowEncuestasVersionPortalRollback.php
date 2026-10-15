<?php

namespace App\Console\Commands;

use App\Support\CredentialFlow\Encuestas\VersionPortal;
use Illuminate\Console\Command;
use Throwable;

class CredentialFlowEncuestasVersionPortalRollback extends Command
{
    protected $signature = 'credential-flow:encuestas:version-portal-rollback {--confirmar : Confirma la reversión}';

    protected $description = 'Revierte la versión de la encuesta del portal SOLO si no tiene respuestas ni versiones posteriores. No toca el histórico.';

    public function handle(): int
    {
        if (! $this->option('confirmar')) {
            $this->warn('Esto elimina la versión del portal (sus preguntas y opciones copiadas) y devuelve la encuesta a su estado previo. Repite con --confirmar para continuar.');

            return self::FAILURE;
        }

        try {
            $r = (new VersionPortal)->revertir();
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            $this->line('No se tocó nada.');

            return self::FAILURE;
        }

        $this->info("Versión {$r['numero']} revertida (preguntas {$r['preguntas']}, opciones {$r['opciones']}).");

        return self::SUCCESS;
    }
}
