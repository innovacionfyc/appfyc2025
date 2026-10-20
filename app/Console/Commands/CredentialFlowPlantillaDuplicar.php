<?php

namespace App\Console\Commands;

use App\Support\CredentialFlow\Plantillas\DuplicacionException;
use App\Support\CredentialFlow\Plantillas\DuplicadorPlantilla;
use Illuminate\Console\Command;
use Throwable;

/**
 * Copia una plantilla (PDF base + diseño) como una plantilla NUEVA e independiente, para trabajar sobre la copia sin tocar el original.
 * Por defecto solo SIMULA (no escribe nada); con --ejecutar la crea. No modifica la plantilla original, sus bases ni sus certificados emitidos.
 */
class CredentialFlowPlantillaDuplicar extends Command
{
    protected $signature = 'credential-flow:plantilla-duplicar
        {origen : Id de la plantilla que se copia (por ejemplo, la institucional «Certificado de asistencia — Virtual»)}
        {--nombre= : Nombre de la copia (obligatorio; no puede repetir el de otra plantilla vigente)}
        {--ejecutar : Crea la copia de verdad (sin esta opción solo se muestra qué se copiaría)}';

    protected $description = 'Duplica una plantilla de forma segura (PDF base y diseño) sin tocar el original; por defecto solo simula';

    public function handle(DuplicadorPlantilla $duplicador): int
    {
        $origen = (int) $this->argument('origen');
        $nombre = (string) ($this->option('nombre') ?? '');
        $ejecutar = (bool) $this->option('ejecutar');

        try {
            $informe = $ejecutar ? $duplicador->duplicar($origen, $nombre) : $duplicador->planear($origen, $nombre);
        } catch (DuplicacionException $e) {
            $this->error("No se duplicó nada: {$e->getMessage()}");

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('No se duplicó nada: ocurrió un error inesperado ('.class_basename($e).'). Revisa el registro y vuelve a intentarlo.');
            report($e);

            return self::FAILURE;
        }

        $o = $informe['origen'];
        $this->line($ejecutar ? 'DUPLICACIÓN REALIZADA' : 'SIMULACIÓN (no se escribió nada)');
        $this->line("Original: #{$o['id']} «{$o['nombre']}» · {$o['elementos']} elementos · esquema v{$o['schema_version']} · PDF {$o['bytes']} bytes · huella ".substr($o['hash'], 0, 12));
        $this->line('  Campos dinámicos: '.($o['campos'] === [] ? '(ninguno)' : implode(', ', $o['campos'])));
        $this->line('  Textos fijos: '.($o['textos_fijos'] === [] ? '(ninguno)' : '«'.implode('», «', $o['textos_fijos']).'»'));
        $this->line("  En uso: {$o['bases']} bases y {$o['emisiones']} emisiones (no se tocan).");
        $this->line("Copia: «{$informe['copia']['nombre']}» · plantilla institucional (no es de pruebas internas) · independiente del original");
        foreach ($informe['advertencias'] as $a) {
            $this->warn("Advertencia: {$a}");
        }

        if ($ejecutar) {
            $this->info("Listo: se creó la plantilla #{$informe['copia_id']}. El original no cambió. Ábrela en el editor para ajustar el diseño de la COPIA.");
        } else {
            $this->line('Se crearía: '.implode(' y ', $informe['se_escribiria']).'.');
            $this->comment('Para crear la copia, ejecuta de nuevo con --ejecutar (requiere tu autorización).');
        }

        return self::SUCCESS;
    }
}
