<?php

namespace App\Console\Commands;

use App\Support\CredentialFlow\Programacion\EjecutorProgramado;
use Illuminate\Console\Command;

/**
 * Ejecutor de las emisiones programadas. Pensado para un cron DIRECTO cada minuto (no `schedule:run`):
 *
 *   * * * * *  cd <app> && /opt/plesk/php/8.3/bin/php artisan credential-flow:emisiones-programadas > /dev/null 2>&1
 *
 * Con el interruptor general apagado no hace nada. `--simular` solo muestra qué haría: no escribe nada ni genera ningún PDF. No imprime datos personales.
 */
class CredentialFlowEmisionesProgramadas extends Command
{
    protected $signature = 'credential-flow:emisiones-programadas
        {--simular : Solo muestra qué haría: no escribe nada ni genera ningún PDF}
        {--max=5 : Máximo de programaciones a ejecutar en esta corrida}';

    protected $description = 'Ejecuta las emisiones programadas cuya hora (de Colombia) ya llegó; recupera las interrumpidas y vence las atrasadas';

    public function handle(EjecutorProgramado $ejecutor): int
    {
        $simular = (bool) $this->option('simular');
        if ($simular) {
            $this->line('SIMULACIÓN: no se escribe nada ni se genera ningún PDF.');
        }
        foreach ($ejecutor->ejecutar($simular, max(1, (int) $this->option('max'))) as $linea) {
            $this->line($linea);
        }

        return self::SUCCESS;
    }
}
