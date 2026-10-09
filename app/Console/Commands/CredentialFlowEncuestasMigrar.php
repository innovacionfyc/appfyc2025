<?php

namespace App\Console\Commands;

use App\Support\CredentialFlow\Migracion\MigradorEncuestas;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class CredentialFlowEncuestasMigrar extends Command
{
    protected $signature = 'credential-flow:encuestas:migrar
        {--staging-bd= : Base LOCAL de staging de la que se lee (p. ej. appfyc2025_staging_ev); si se omite, la misma conexión}
        {--json : Salida en JSON}';

    protected $description = 'Migra las encuestas históricas del staging al modelo configurable (corrida atómica e idempotente, solo base local de pruebas). No toca el staging.';

    public function handle(): int
    {
        $conexion = null;
        if ($this->option('staging-bd')) {
            $base = config('database.connections.'.config('database.default'));
            config(['database.connections.staging_ev' => array_merge($base, ['database' => (string) $this->option('staging-bd')])]);
            DB::purge('staging_ev');
            $conexion = 'staging_ev';
        }

        try {
            $r = (new MigradorEncuestas($conexion))->ejecutar();
        } catch (QueryException $e) {
            // Sin valores: el mensaje de SQL puede incluir datos. Solo el código SQLSTATE.
            $this->error('Error de base de datos (SQLSTATE '.($e->errorInfo[0] ?? '?').'). Se revirtió toda la corrida.');

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode(['no_op' => $r->noOp, 'corrida_id' => $r->corridaId, 'mensaje' => $r->mensaje, 'totales' => $r->totales], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info($r->mensaje);
        $t = $r->totales;
        if (! $r->noOp) {
            $this->line(sprintf('  respuestas %d · detalles %d · versiones %d', $t['respuestas'], $t['detalles'], count($t['versiones'])));
            foreach ($t['validaciones'] as $v) {
                $this->line(sprintf('  %s %-62s esperado %6d · obtenido %6d', $v['ok'] ? '✓' : '✗', $v['clave'], $v['esperado'], $v['obtenido']));
            }
        }

        return self::SUCCESS;
    }
}
