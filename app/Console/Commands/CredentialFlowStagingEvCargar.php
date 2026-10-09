<?php

namespace App\Console\Commands;

use App\Support\CredentialFlow\StagingEv\EscanerImagenes;
use App\Support\CredentialFlow\StagingEv\FuenteConexion;
use App\Support\CredentialFlow\StagingEv\GuardiaStaging;
use App\Support\CredentialFlow\StagingEv\ImportadorSnapshot;
use App\Support\CredentialFlow\StagingEv\SnapshotInfo;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CredentialFlowStagingEvCargar extends Command
{
    protected $signature = 'credential-flow:staging-ev:cargar
        {--dump= : Dump del sistema viejo (.sql o .sql.gz): solo se lee para registrar su SHA-256 y su cabecera}
        {--origen-bd= : Base LOCAL donde ya está restaurado ese dump (su nombre debe contener «snapshot» u «origen»)}
        {--etiqueta= : Etiqueta única del snapshot (por defecto, el nombre del dump)}
        {--imagenes= : Carpeta con las imágenes del sistema viejo (certImages): se inventarían, o con --manifest solo se comprueban}
        {--manifest= : Manifiesto JSON de las imágenes (fuente de verdad del nombre original, SHA-256, tamaño, MIME y dimensiones)}
        {--verificar-sha : Con --manifest e --imagenes, recalcula el SHA-256 de cada archivo físico}
        {--inventario= : Listado ruta|bytes|sha256 hecho en el servidor, para cruzarlo con la carpeta}
        {--forzar : Cargar aunque ya exista un snapshot con el mismo SHA-256}';

    protected $description = 'Carga el snapshot del sistema viejo en el STAGING local (stg_ev_*). No toca tablas finales ni producción.';

    public function handle(): int
    {
        try {
            GuardiaStaging::exigirDestinoLocal();
            $origenBd = (string) $this->option('origen-bd');
            $dump = (string) $this->option('dump');
            if ($origenBd === '' || $dump === '') {
                $this->error('Indica --dump y --origen-bd.');

                return self::FAILURE;
            }
            GuardiaStaging::exigirOrigenLocal($origenBd);
            if (! Schema::hasTable('stg_ev_snapshots')) {
                $this->error('Faltan las tablas de staging. Instálalas con: php artisan migrate --path=database/staging/ev --force');

                return self::FAILURE;
            }

            $etiqueta = (string) ($this->option('etiqueta') ?: preg_replace('/\.sql(\.gz)?$/i', '', basename($dump)));
            $info = SnapshotInfo::desdeDump($dump, $etiqueta, 'Snapshot privado del sistema viejo (restauración local)');

            $imagenes = null;
            if ($this->option('manifest')) {
                $imagenes = EscanerImagenes::desdeManifiesto(
                    (string) $this->option('manifest'),
                    $this->option('imagenes') ? (string) $this->option('imagenes') : null,
                    (bool) $this->option('verificar-sha'),
                );
                $this->line('Imágenes del manifiesto: '.count($imagenes).($this->option('imagenes') ? ' (archivos físicos comprobados)' : ' (sin comprobar archivos físicos)'));
                if ($this->option('inventario')) {
                    $cruce = EscanerImagenes::compararConInventario($imagenes, (string) $this->option('inventario'));
                    $this->line('Cruce con el inventario del servidor: '.json_encode($cruce));
                    $info = new SnapshotInfo($info->etiqueta, $info->origen, $info->dumpArchivo, $info->dumpSha256, $info->tomadoAt, $info->servidorOrigen, $cruce['sha256_inventario']);
                }
            } elseif ($this->option('imagenes')) {
                $imagenes = (new EscanerImagenes)->escanear((string) $this->option('imagenes'));
                $this->line('Imágenes inventariadas: '.count($imagenes));
                if ($this->option('inventario')) {
                    $cruce = EscanerImagenes::compararConInventario($imagenes, (string) $this->option('inventario'));
                    $this->line('Cruce con el inventario del servidor: '.json_encode($cruce));
                    $info = new SnapshotInfo($info->etiqueta, $info->origen, $info->dumpArchivo, $info->dumpSha256, $info->tomadoAt, $info->servidorOrigen, $cruce['sha256_inventario']);
                }
            }

            config(['database.connections.staging_ev_origen' => [...config('database.connections.'.config('database.default')), 'database' => $origenBd]]);
            DB::purge('staging_ev_origen');

            $resultado = (new ImportadorSnapshot)->cargar($info, new FuenteConexion(DB::connection('staging_ev_origen')), $imagenes, (bool) $this->option('forzar'));
        } catch (QueryException $e) {
            // El mensaje completo de Laravel incluye el SQL con los valores: aquí solo el error del motor (sin datos).
            $this->error('Error de base de datos: '.mb_substr((string) ($e->getPrevious()?->getMessage() ?? 'desconocido'), 0, 300));

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($resultado->yaCargado) {
            $this->info("Ese dump ya estaba cargado (snapshot #{$resultado->snapshotId}). No se hizo ningún cambio.");

            return self::SUCCESS;
        }

        $this->info("Snapshot #{$resultado->snapshotId} cargado.");
        foreach ($resultado->entidades as $entidad => $e) {
            $this->line(sprintf('  %-13s origen %6d | nuevas %6d | iguales %6d | cambiadas %4d | ausentes %4d', $entidad, $e['origen'], $e['nuevas'], $e['iguales'], $e['cambiadas'], $e['ausentes']));
        }
        $this->line('Para ver el reporte: php artisan credential-flow:staging-ev:reporte');

        return self::SUCCESS;
    }
}
