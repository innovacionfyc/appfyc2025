<?php

namespace App\Console\Commands;

use App\Models\CredentialFlow\ContactoQa;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Configuración ÚNICA (una sola vez) de los contactos de control (QA) automáticos: siembra la lista oficial desde una base de pruebas internas que ya existe
 * y designa la plantilla de pruebas internas. Por defecto solo MUESTRA lo que haría (conteos, sin datos personales); con --ejecutar lo aplica.
 * Después, cada base nueva incorpora los contactos sola y no hace falta ningún comando más.
 */
class CredentialFlowQaContactos extends Command
{
    protected $signature = 'credential-flow:qa-contactos
        {--desde-lote= : Id de una base de pruebas internas que ya tiene los contactos QA (se reutilizan; no se inventan)}
        {--plantilla= : Id de la plantilla de pruebas internas («PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA»)}
        {--incorporar= : Id de una base ya existente a la que se le agregan los contactos QA que le falten}
        {--ejecutar : Aplica el cambio (sin esta opción solo se muestra)}';

    protected $description = 'Configura los contactos QA automáticos (siembra, plantilla de pruebas internas, incorporar a una base); por defecto solo muestra';

    public function handle(ServicioQa $qa): int
    {
        if (! ServicioQa::disponible()) {
            $this->error('Falta la tabla cf_contactos_qa o la columna es_control_qa (migraciones pendientes).');

            return self::FAILURE;
        }
        $ejecutar = (bool) $this->option('ejecutar');
        $hizoAlgo = false;

        if (($id = $this->option('desde-lote')) !== null) {
            $hizoAlgo = true;
            $lote = Lote::query()->find((int) $id);
            if ($lote === null) {
                $this->error('No existe esa base.');

                return self::FAILURE;
            }
            $r = $qa->sembrarDesdeLote($lote, $ejecutar);
            $this->line("Contactos desde la base #{$lote->id}: {$r['nuevos']} nuevos, {$r['existentes']} ya estaban, {$r['sin_correo']} sin correo válido (omitidos).");
        }

        if (($id = $this->option('plantilla')) !== null) {
            $hizoAlgo = true;
            $p = Plantilla::query()->find((int) $id);
            if ($p === null) {
                $this->error('No existe esa plantilla.');

                return self::FAILURE;
            }
            if (! ServicioQa::tieneAdvertenciaVisible($p)) {
                $this->error('El diseño de la plantilla debe incluir, como texto visible, «'.ServicioQa::ADVERTENCIA.'».');

                return self::FAILURE;
            }
            if ($ejecutar) {
                try {
                    $qa->designarPlantilla($p, true);
                } catch (InvalidArgumentException $e) {
                    $this->error($e->getMessage());

                    return self::FAILURE;
                }
            }
            $this->line("Plantilla de pruebas internas: #{$p->id} ".($ejecutar ? 'designada.' : '(vista previa).'));
        }

        if (($id = $this->option('incorporar')) !== null) {
            $hizoAlgo = true;
            $lote = Lote::query()->find((int) $id);
            if ($lote === null) {
                $this->error('No existe esa base.');

                return self::FAILURE;
            }
            if ($ejecutar) {
                $r = $qa->incorporar($lote);
                $this->line("Base #{$lote->id}: {$r['creados']} contactos QA agregados, {$r['existentes']} ya estaban como QA, {$r['omitidos']} omitidos (su documento ya está como participante real).");
            } else {
                $faltan = $qa->contactos()->filter(fn ($c) => ! $lote->participantes()->where('documento_clave', $c->documento_clave)->exists())->count();
                $this->line("Base #{$lote->id}: se agregarían {$faltan} contactos QA (vista previa).");
            }
        }

        if (! $hizoAlgo) {
            $this->line('Contactos QA activos: '.ContactoQa::query()->where('activo', true)->count().' · plantilla de pruebas internas: '.($qa->plantilla() ? '#'.$qa->plantilla()->id : 'sin designar'));
        } elseif (! $ejecutar) {
            $this->line('Vista previa: no se escribió nada. Repite con --ejecutar para aplicar.');
        }

        return self::SUCCESS;
    }
}
