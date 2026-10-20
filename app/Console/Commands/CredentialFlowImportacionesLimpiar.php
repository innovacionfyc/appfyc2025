<?php

namespace App\Console\Commands;

use App\Support\CredentialFlow\Importacion\ServicioImportaciones;
use Illuminate\Console\Command;

/**
 * Limpieza de los borradores de la importación inteligente: vence los que pasaron su vigencia, borra los datos personales de los terminados y elimina las filas
 * (sin datos personales) con más de `conservar_metadatos_dias`. No imprime ningún dato personal. También se ejecuta sola, de forma oportunista, al analizar o listar.
 */
class CredentialFlowImportacionesLimpiar extends Command
{
    protected $signature = 'credential-flow:importaciones-limpiar';

    protected $description = 'Vence y borra los borradores de importación inteligente caducados (borra sus datos personales)';

    public function handle(ServicioImportaciones $servicio): int
    {
        $r = $servicio->limpiar();
        $this->info("Borradores vencidos: {$r['vencidos']}. Filas eliminadas: {$r['eliminados']}.");

        return self::SUCCESS;
    }
}
