<?php

namespace App\Console\Commands;

use App\Support\CredentialFlow\Encuestas\VersionPortal;
use Illuminate\Console\Command;
use Throwable;

class CredentialFlowEncuestasVersionPortal extends Command
{
    protected $signature = 'credential-flow:encuestas:version-portal {--simular : Muestra el plan sin escribir nada} {--confirmar : Crea y activa la versión del portal}';

    protected $description = 'Prepara la versión de la encuesta del portal como COPIA FIEL de la versión estructural más reciente (conserva todo el histórico).';

    public function handle(): int
    {
        $simular = (bool) $this->option('simular');
        if ($simular === (bool) $this->option('confirmar')) {
            $this->warn('Indica exactamente una opción: --simular (plan sin cambios) o --confirmar (ejecución real).');

            return self::FAILURE;
        }

        $servicio = new VersionPortal;
        try {
            $plan = $servicio->plan();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf('Origen: versión %d (id %d, %d respuestas) · preguntas %d · opciones %d', $plan['origen_numero'], $plan['origen_version_id'], $plan['respuestas_origen'], $plan['preguntas'], $plan['opciones']));
        if ($plan['accion'] === 'sin_cambios') {
            $this->info("Ya existe la versión {$plan['numero']} del portal (id {$plan['existente']}). No se hizo ningún cambio.");

            return self::SUCCESS;
        }
        $this->line("Se creará la versión {$plan['numero']} (copia fiel), publicada y activa, con origen de respuestas nuevas «credential_flow».");
        if ($simular) {
            $this->info('SIMULACIÓN: no se escribió nada.');

            return self::SUCCESS;
        }

        try {
            $v = $servicio->aplicar();
        } catch (Throwable $e) {
            $this->error('No se pudo crear la versión: '.$e->getMessage().' No se tocó nada.');

            return self::FAILURE;
        }
        $this->info("Versión {$v->numero} creada y activa (id {$v->id}).");

        return self::SUCCESS;
    }
}
