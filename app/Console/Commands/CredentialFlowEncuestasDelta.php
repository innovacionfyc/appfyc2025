<?php

namespace App\Console\Commands;

use App\Support\CredentialFlow\Migracion\DeltaRechazado;
use App\Support\CredentialFlow\Migracion\MigradorDeltaEncuestas;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class CredentialFlowEncuestasDelta extends Command
{
    protected $signature = 'credential-flow:encuestas:delta
        {--staging-bd= : Base LOCAL de staging (ya actualizada con el snapshot final) de la que se lee; si se omite, la misma conexión}
        {--simular : Calcula y valida el delta sin escribir nada}
        {--confirmo-produccion= : Excepción PRODUCTIVA (exige además la variable de proceso CREDENTIAL_FLOW_ALLOW_PRODUCTION_MIGRATION con el mismo valor exacto: «appfyc»)}
        {--json : Salida en JSON}';

    protected $description = 'DELTA de encuestas: crea solo las respuestas nuevas sobre la estructura ya migrada (no cambia versiones, preguntas ni opciones).';

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
            $r = (new MigradorDeltaEncuestas($conexion, $this->option('confirmo-produccion') ? (string) $this->option('confirmo-produccion') : null, 'credential-flow:encuestas:delta'))->ejecutar((bool) $this->option('simular'));
        } catch (DeltaRechazado $e) {
            $this->error($e->getMessage());
            $this->line(json_encode(['codigo' => $e->codigo, 'detalle' => $e->detalle], JSON_UNESCAPED_UNICODE));
            $this->line('No se escribió nada.');

            return self::FAILURE;
        } catch (QueryException $e) {
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
            $this->line(sprintf('  respuestas %d · detalles %d', $t['respuestas'], $t['detalles']));
            foreach ($t['validaciones'] as $v) {
                $this->line(sprintf('  %s %-42s esperado %6d · obtenido %6d', $v['ok'] ? '✓' : '✗', $v['clave'], $v['esperado'], $v['obtenido']));
            }
        }

        return self::SUCCESS;
    }
}
