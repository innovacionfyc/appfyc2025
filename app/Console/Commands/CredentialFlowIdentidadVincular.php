<?php

namespace App\Console\Commands;

use App\Models\Usuario;
use App\Support\CredentialFlow\Portal\VinculosIdentidadModerna;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Vincula, con evidencia verificable, el grupo de nombre de un certificado MODERNO con el grupo histórico de la MISMA persona (misma persona, grafía distinta).
 * Por defecto solo VALIDA (no escribe nada); con --ejecutar crea el vínculo. No modifica certificados, nombres ni PDF.
 */
class CredentialFlowIdentidadVincular extends Command
{
    protected $signature = 'credential-flow:identidad-vincular
        {documento : Documento de la persona}
        {correo : Correo autorizado: debe estar registrado en el grupo histórico Y en el moderno}
        {--historico= : Nombre tal como aparece en los certificados históricos}
        {--moderno= : Nombre tal como aparece en la base moderna}
        {--motivo= : Justificación (mínimo 20 caracteres)}
        {--usuario= : Id del administrador que autoriza}
        {--ejecutar : Crea el vínculo (sin esta opción solo se valida)}';

    protected $description = 'Vincula (con evidencia) el nombre moderno con el nombre histórico de la misma persona en el portal; por defecto solo valida';

    public function handle(VinculosIdentidadModerna $vinculos): int
    {
        if (! VinculosIdentidadModerna::disponible()) {
            $this->error('Falta la tabla cf_vinculos_identidad (migración pendiente).');

            return self::FAILURE;
        }
        $e = $vinculos->evaluar((string) $this->argument('documento'), (string) $this->argument('correo'), (string) $this->option('historico'), (string) $this->option('moderno'), (string) $this->option('motivo'));
        if (! $e['ok']) {
            foreach ($e['errores'] as $m) {
                $this->error($m);
            }

            return self::FAILURE;
        }
        $this->info('Evidencia verificada: el correo está registrado en el grupo histórico y en el moderno (con emisión vigente); los grupos son distintos.');
        if (! $this->option('ejecutar')) {
            $this->line('Validación solamente: no se escribió nada. Repetir con --usuario=ID --ejecutar para crear el vínculo.');

            return self::SUCCESS;
        }
        $usuario = (int) $this->option('usuario');
        if ($usuario < 1 || Usuario::query()->whereKey($usuario)->doesntExist()) {
            $this->error('--usuario debe ser el id de un administrador existente.');

            return self::FAILURE;
        }
        try {
            $id = $vinculos->crear((string) $this->argument('documento'), (string) $this->argument('correo'), (string) $this->option('historico'), (string) $this->option('moderno'), (string) $this->option('motivo'), $usuario);
        } catch (InvalidArgumentException $ex) {
            $this->error($ex->getMessage());

            return self::FAILURE;
        }
        $this->info("Vínculo #{$id} creado. Las sesiones abiertas antes de este instante no lo heredan.");

        return self::SUCCESS;
    }
}
