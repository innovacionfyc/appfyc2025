<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Comprobación de solo lectura previa a ejecutar la migración de SEM_DUPLA en un servidor.
 * Muestra la definición real de eventos.tipo_evento y la sentencia exacta que ejecutaría la
 * migración, sin modificar nada (solo SHOW y SELECT).
 */
class VerificarMigracionSemDupla extends Command
{
    protected $signature = 'eventos:verificar-sem-dupla';

    protected $description = 'Muestra, sin modificar nada, qué haría la migración de SEM_DUPLA sobre eventos.tipo_evento';

    private const MIGRACION = '2026_09_28_120000_add_sem_dupla_to_eventos_table';

    public function handle(): int
    {
        $migracion = require database_path('migrations/'.self::MIGRACION.'.php');

        $this->line('Base de datos: '.DB::getDatabaseName().' ('.DB::getDriverName().' '.DB::selectOne('select version() as v')->v.')');

        $ejecutada = Schema::hasTable('migrations')
            && DB::table('migrations')->where('migration', self::MIGRACION)->exists();
        $this->line('Migración ya ejecutada: '.($ejecutada ? 'sí' : 'no'));

        foreach (['precio_seminario', 'precio_seminario_virtual', 'precio_seminario_streaming'] as $columna) {
            $this->line("Columna {$columna}: ".(Schema::hasColumn('eventos', $columna) ? 'existe' : 'no existe'));
        }

        $plan = $migracion->planAmpliacion();
        $definicion = $plan['definicion'];

        $this->newLine();
        if ($definicion !== null) {
            $this->line('Definición actual (SHOW CREATE TABLE):');
            $this->line('  '.$definicion['linea']);
            $this->line('Atributos (information_schema):');
            foreach ($definicion['atributos'] as $clave => $valor) {
                $this->line("  {$clave}: ".var_export($valor, true));
            }
            $this->line('Valores actuales ('.count($definicion['valores']).'): '.implode(', ', $definicion['valores']));
        }

        $this->newLine();
        $this->line($plan['motivo']);
        if ($plan['sentencia'] !== null) {
            $this->line('Sentencia que ejecutaría up():');
            $this->line('  '.$plan['sentencia']);
        }

        $bloqueos = $migracion->bloqueosReversion();
        $this->newLine();
        $this->line('down() '.($bloqueos === [] ? 'podría revertirse sin perder datos.' : 'se negaría a revertir: '.implode('; ', $bloqueos).'.'));

        return self::SUCCESS;
    }
}
