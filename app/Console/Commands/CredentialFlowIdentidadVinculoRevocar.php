<?php

namespace App\Console\Commands;

use App\Models\Usuario;
use App\Support\CredentialFlow\Portal\VinculosIdentidadModerna;
use Illuminate\Console\Command;
use InvalidArgumentException;

/** Revoca un vínculo de identidad moderna (el nombre moderno vuelve a ser un grupo aparte). No borra nada. */
class CredentialFlowIdentidadVinculoRevocar extends Command
{
    protected $signature = 'credential-flow:identidad-vinculo-revocar {id : Id del vínculo} {--motivo= : Justificación (mínimo 20 caracteres)} {--usuario= : Id del administrador} {--ejecutar : Aplica la revocación}';

    protected $description = 'Revoca un vínculo de identidad moderna; por defecto no escribe nada';

    public function handle(VinculosIdentidadModerna $vinculos): int
    {
        if (! $this->option('ejecutar')) {
            $this->line('Sin --ejecutar no se escribe nada.');

            return self::SUCCESS;
        }
        $usuario = (int) $this->option('usuario');
        if ($usuario < 1 || Usuario::query()->whereKey($usuario)->doesntExist()) {
            $this->error('--usuario debe ser el id de un administrador existente.');

            return self::FAILURE;
        }
        try {
            $vinculos->revocar((int) $this->argument('id'), (string) $this->option('motivo'), $usuario);
        } catch (InvalidArgumentException $ex) {
            $this->error($ex->getMessage());

            return self::FAILURE;
        }
        $this->info('Vínculo revocado.');

        return self::SUCCESS;
    }
}
