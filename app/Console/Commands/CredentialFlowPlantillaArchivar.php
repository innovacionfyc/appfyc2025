<?php

namespace App\Console\Commands;

use App\Models\CredentialFlow\Plantilla;
use App\Models\Usuario;
use App\Support\CredentialFlow\Plantillas\ArchivoException;
use App\Support\CredentialFlow\Plantillas\ArchivoPlantillas;
use Illuminate\Console\Command;
use Throwable;

/**
 * Archiva (o restaura, con --restaurar) una plantilla. Por defecto solo SIMULA: muestra bases, certificados, variante QA, programaciones y bloqueos sin escribir
 * nada. Para ejecutar hacen falta --ejecutar, un SUPER-ADMIN (--usuario) y un motivo. Nunca borra ni modifica diseño, PDF base, bases ni certificados.
 */
class CredentialFlowPlantillaArchivar extends Command
{
    protected $signature = 'credential-flow:plantilla-archivar
        {plantilla : Id de la plantilla (cf_plantillas)}
        {--sucesora= : Id de la plantilla vigente que la reemplaza (opcional, informativo)}
        {--motivo= : Por qué se archiva o restaura (10 a 500 caracteres; queda en Movimientos)}
        {--usuario= : Id del super-admin que autoriza}
        {--restaurar : Restaura una plantilla archivada en lugar de archivarla}
        {--ejecutar : Escribe de verdad (sin esta opción solo simula)}';

    protected $description = 'Archiva o restaura una plantilla de forma segura (conserva todo; bloquea emisiones nuevas con ella); por defecto solo simula';

    public function handle(ArchivoPlantillas $archivo): int
    {
        $plantilla = Plantilla::withTrashed()->find((int) $this->argument('plantilla'));
        if ($plantilla === null) {
            $this->error('No existe esa plantilla.');

            return self::FAILURE;
        }
        $restaurar = (bool) $this->option('restaurar');

        if (! $restaurar) {
            $r = $archivo->resumen($plantilla);
            $this->line(($this->option('ejecutar') ? 'PLAN' : 'SIMULACIÓN (no se escribió nada)').": archivar la plantilla #{$plantilla->id} «{$plantilla->nombre}»");
            if (isset($r['bases'])) {
                $this->line("  Bases que la usan: {$r['bases']['total']} · certificados emitidos con ella: {$r['emisiones']['total']} (vigentes {$r['emisiones']['vigentes']}) · se conservan todos");
                $v = $r['variante'];
                $this->line('  Variante QA: '.($v === null ? '(ninguna)' : "#{$v['id']} · ".($v['se_archiva_con_ella'] ? 'se archiva con ella' : ($v['archivada'] ? 'ya archivada' : 'compartida: no se archiva'))));
                $this->line('  Programaciones activas afectadas: '.($r['programaciones_activas'] === [] ? 'ninguna' : '#'.implode(', #', $r['programaciones_activas'])));
                $this->line('  Plantillas que se archivarían: #'.implode(', #', $r['afectadas']));
            }
            foreach ($r['avisos'] as $a) {
                $this->warn("  Aviso: {$a}");
            }
            foreach ($r['bloqueos'] as $b) {
                $this->error("  BLOQUEO {$b['code']}: {$b['message']}");
            }
            if (! $this->option('ejecutar')) {
                if ($r['bloqueos'] === []) {
                    $this->comment('Para archivar: --usuario=ID --motivo="..." [--sucesora=ID] --ejecutar (requiere autorización de un super-admin). No se envía ningún correo.');
                }

                return $r['bloqueos'] === [] ? self::SUCCESS : self::FAILURE;
            }
        } elseif (! $this->option('ejecutar')) {
            $this->line("SIMULACIÓN (no se escribió nada): restaurar la plantilla #{$plantilla->id} «{$plantilla->nombre}» · ".($plantilla->estaArchivada() ? 'está archivada' : 'NO está archivada'));
            $this->comment('Para restaurar: --restaurar --usuario=ID --motivo="..." --ejecutar (requiere autorización de un super-admin).');

            return $plantilla->estaArchivada() ? self::SUCCESS : self::FAILURE;
        }

        $usuario = is_numeric($this->option('usuario')) ? Usuario::query()->with('perfilOrganizador.rol')->find((int) $this->option('usuario')) : null;
        try {
            $res = $restaurar
                ? $archivo->restaurar($plantilla, $usuario, (string) $this->option('motivo'))
                : $archivo->archivar($plantilla, $usuario, (string) $this->option('motivo'), is_numeric($this->option('sucesora')) ? (int) $this->option('sucesora') : null);
        } catch (ArchivoException $e) {
            $this->error("No se cambió nada: {$e->getMessage()}");

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('No se cambió nada: ocurrió un error inesperado ('.class_basename($e).'). Revisa el registro.');
            report($e);

            return self::FAILURE;
        }

        $ids = $res[$restaurar ? 'restauradas' : 'archivadas'];
        $this->info(($restaurar ? 'RESTAURADAS' : 'ARCHIVADAS').': #'.implode(', #', $ids)." · movimiento #{$res['movimiento_id']}. No se borró ni modificó ningún diseño, PDF, base ni certificado.");

        return self::SUCCESS;
    }
}
