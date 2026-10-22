<?php

namespace App\Console\Commands;

use App\Support\CredentialFlow\Plantillas\CreadorVarianteQa;
use App\Support\CredentialFlow\Plantillas\DuplicacionException;
use Illuminate\Console\Command;
use Throwable;

/**
 * Crea la variante de PRUEBA INTERNA de una plantilla institucional (mismo diseño + «PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA» + «C.C. » antes del documento),
 * la designa como plantilla de pruebas y la asigna como variante QA del original. Por defecto solo SIMULA; con --ejecutar la crea. El original no cambia.
 */
class CredentialFlowPlantillaVarianteQa extends Command
{
    protected $signature = 'credential-flow:plantilla-variante-qa
        {origen : Id de la plantilla institucional (por ejemplo, la #13)}
        {--nombre= : Nombre de la variante (por omisión «PRUEBA INTERNA — <nombre del original>»)}
        {--ejecutar : Crea la variante de verdad (sin esta opción solo se muestra qué se haría)}';

    protected $description = 'Crea la variante de PRUEBA INTERNA (QA) de una plantilla institucional; por defecto solo simula';

    public function handle(CreadorVarianteQa $creador): int
    {
        $origen = (int) $this->argument('origen');
        $nombre = $this->option('nombre') !== null ? (string) $this->option('nombre') : null;
        $ejecutar = (bool) $this->option('ejecutar');

        try {
            $r = $ejecutar ? $creador->crear($origen, $nombre) : $creador->planear($origen, $nombre);
        } catch (DuplicacionException $e) {
            $this->error("No se creó nada: {$e->getMessage()}");

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('No se creó nada: ocurrió un error inesperado ('.class_basename($e).').');
            report($e);

            return self::FAILURE;
        }

        $o = $r['origen'];
        $v = $r['variante'];
        $this->line($ejecutar ? 'VARIANTE QA CREADA' : 'SIMULACIÓN (no se escribió nada)');
        $this->line("Original: #{$o['id']} «{$o['nombre']}» · {$o['elementos']} elementos · huella ".substr($o['hash'], 0, 12)." · {$o['bases']} bases y {$o['emisiones']} emisiones (no se tocan).");
        $this->line("Variante: «{$v['nombre']}»");
        $this->line('  Advertencia «PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA»: '.($v['agrega_advertencia'] ? 'se agrega arriba, visible' : 'ya la trae'));
        $this->line('  Documento: '.($v['prefijo_documento'] ? 'se antepone «C.C. » (los contactos QA guardan solo el número)' : 'sin cambios (ya tiene texto antes)'));
        $this->line("  Se designa como plantilla de pruebas internas y se asigna como variante QA de la #{$v['asignada_a']}.");
        if ($ejecutar) {
            $this->info("Listo: variante #{$r['variante_id']}. Revisa su vista previa antes de emitir pruebas QA.");
        } else {
            $this->comment('Para crearla, ejecuta de nuevo con --ejecutar (requiere tu autorización).');
        }

        return self::SUCCESS;
    }
}
