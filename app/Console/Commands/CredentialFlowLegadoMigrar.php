<?php

namespace App\Console\Commands;

use App\Support\CredentialFlow\Migracion\MigradorHistorico;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class CredentialFlowLegadoMigrar extends Command
{
    protected $signature = 'credential-flow:legado:migrar
        {--staging-bd= : Base LOCAL de staging de la que se lee (p. ej. appfyc2025_staging_ev); si se omite, la misma conexión}
        {--json : Salida en JSON}';

    protected $description = 'Migra el histórico del staging a las tablas finales de Credential Flow (corrida atómica e idempotente, solo base local de pruebas).';

    public function handle(): int
    {
        $conexion = null;
        if ($this->option('staging-bd')) {
            // La base destino se elige por proceso con DB_DATABASE; el staging se lee con una conexión aparte, del mismo servidor local.
            $base = config('database.connections.'.config('database.default'));
            config(['database.connections.staging_ev' => array_merge($base, ['database' => (string) $this->option('staging-bd')])]);
            DB::purge('staging_ev');
            $conexion = 'staging_ev';
        }

        try {
            $r = (new MigradorHistorico($conexion))->ejecutar();
        } catch (QueryException $e) {
            // Sin valores: el mensaje de SQL puede incluir datos. Solo el código SQLSTATE.
            $this->error('Error de base de datos (SQLSTATE '.($e->errorInfo[0] ?? '?').'). Se revirtió toda la corrida; revisa corrida fallida en cf_migraciones_corridas.');

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
            $this->line(sprintf('  eventos %d · contenidos %d · plantillas %d · certificados %d · correos %d · descargas %d', $t['eventos'], $t['contenidos'], $t['plantillas'], $t['certificados'], $t['correos']['total'], $t['descargas']['migradas']));
            foreach ($t['validaciones'] as $v) {
                $this->line(sprintf('  %s %-52s esperado %6d · obtenido %6d', $v['ok'] ? '✓' : '✗', $v['clave'], $v['esperado'], $v['obtenido']));
            }
            $this->line('  tokens no migrados: '.$t['tokens']['en_staging'].' · encuestas pendientes: '.$t['encuestas']['en_staging']);
        }

        return self::SUCCESS;
    }
}
