<?php

namespace App\Support\CredentialFlow\Conciliaciones;

use App\Models\CredentialFlow\Conciliacion;
use App\Models\CredentialFlow\ConciliacionEvento;
use App\Support\CredentialFlow\Identidad\EvidenciaIdentidad;
use App\Support\CredentialFlow\Identidad\GruposSinVia;
use App\Support\CredentialFlow\Rehearsal\GuardiaClave;
use Illuminate\Support\Facades\DB;

/**
 * Detector de los GRUPOS SIN VÍA de documentos parcialmente accesibles (Fase 10B-3C-1). Crea, de forma IDEMPOTENTE, un caso administrativo por (documento, causa) en
 * `cf_conciliaciones` (`tipo = identidad_ambigua`, motivo `GRUPO_SIN_VIA_*`); NO crea tablas paralelas y NO concede nada: crear un caso no cambia el gate.
 *
 *  - correo_compartido  → `GRUPO_SIN_VIA_CORREO_COMPARTIDO` (abierto): recuperable con `correo_autorizado` (scope de un grupo).
 *  - evidencia_externa  → `GRUPO_SIN_VIA_EVIDENCIA_EXTERNA` (requiere_soporte): nombre realmente distinto / mismo evento / correo ya autorizado a otro grupo; se gestiona como caso especial (10B-3C-4).
 *  - sin_correo         → `GRUPO_SIN_VIA_SIN_CORREO` (requiere_soporte): no hay correo propio; se gestiona como caso especial (10B-3C-4). Nunca se copia el correo del hermano.
 *  - sin_fila_habilitante NO recibe caso de identidad: depende de resolver su plantilla (flujo 10B-1); solo lo informa el read-model.
 *
 * Clave de idempotencia `identidad_ambigua:documento:<hash>:<sufijo>` (UNIQUE): ejecutar varias veces reutiliza el mismo caso y solo completa relaciones faltantes del
 * pivote (todos los certificados del documento, un rol `grupo_N` por grupo). Un caso existente NUNCA se reabre ni se modifica. Solo escribe en `cf_conciliaciones*`.
 */
final class DetectorGruposSinVia
{
    private const SUFIJO = [GruposSinVia::MOTIVO_COMPARTIDO => 'sv_cc', GruposSinVia::MOTIVO_EVIDENCIA => 'sv_ev', GruposSinVia::MOTIVO_SIN_CORREO => 'sv_sc'];

    private const LOTE_PIVOTE = 500;

    public function __construct(private readonly GruposSinVia $grupos) {}

    /**
     * @return array{por_motivo:array<string,array{detectados:int,nuevos:int,existentes:int,grupos:int,relaciones_agregadas:int}>,sin_caso:array<string,int>}
     */
    public function ejecutar(bool $simular = false): array
    {
        if (! $simular) {
            GuardiaClave::exigirCoincidencia();   // 11A: los HMAC persistidos deben generarse con la MISMA APP_KEY de la corrida
        }
        $resumen = array_fill_keys(array_keys(self::SUFIJO), ['detectados' => 0, 'nuevos' => 0, 'existentes' => 0, 'grupos' => 0, 'relaciones_agregadas' => 0]);
        $sinCaso = ['sin_fila_habilitante' => 0];
        $existentes = DB::table('cf_conciliaciones')->pluck('id', 'clave_idempotencia');

        foreach ($this->grupos->documentosParciales() as $clave => $_) {
            $a = $this->grupos->analizarDocumento($clave);
            if (array_filter($a, fn ($x) => $x['accesible']) === []) {
                continue;
            }
            $porCausa = [];
            foreach ($a as $g => $x) {
                if ($x['causa'] === 'sin_fila_habilitante') {
                    $sinCaso['sin_fila_habilitante']++;
                } elseif ($x['causa'] !== null) {
                    $porCausa[$x['causa']][] = $g;
                }
            }
            foreach ($porCausa as $causa => $gs) {
                $motivo = GruposSinVia::MOTIVO_POR_CAUSA[$causa];
                $hash = EvidenciaIdentidad::hashDocumento($clave);
                $claveCaso = 'identidad_ambigua:documento:'.$hash.':'.self::SUFIJO[$motivo];
                $r = &$resumen[$motivo];
                $r['detectados']++;
                $r['grupos'] += count($gs);
                $existe = $existentes->has($claveCaso);
                if ($simular) {
                    $r[$existe ? 'existentes' : 'nuevos']++;
                    unset($r);

                    continue;
                }
                [$nuevo, $agregadas] = $this->persistir($claveCaso, $hash, $motivo, $a, collect($a)->where('causa', $causa)->pluck('subcausa')->filter()->first());
                $r[$nuevo ? 'nuevos' : 'existentes']++;
                $r['relaciones_agregadas'] += $agregadas;
                unset($r);
            }
        }

        return ['por_motivo' => $resumen, 'sin_caso' => $sinCaso];
    }

    /**
     * UN documento (10B-3C-4): crea, de forma idempotente, los casos de los grupos que quedan SIN vía tras aplicar una decisión (p. ej. la fila suelta del documento masivo
     * cuando su correo ya se autorizó al grupo grande). Se llama al cerrar un caso de identidad, para que el residual no quede escondido por el caso principal resuelto.
     * Ignora la exclusión de «documentos con caso base» de `ejecutar()`: aquí el caso base es justamente el que acaba de cerrarse.
     *
     * @return array{nuevos:int,existentes:int}
     */
    public function ejecutarDocumento(string $clave): array
    {
        $a = $this->grupos->analizarDocumento($clave, true);
        $r = ['nuevos' => 0, 'existentes' => 0];
        if (array_filter($a, fn ($x) => $x['accesible']) === []) {
            return $r;
        }
        $porCausa = [];
        foreach ($a as $g => $x) {
            if ($x['causa'] !== null && $x['causa'] !== 'sin_fila_habilitante') {
                $porCausa[$x['causa']][] = $g;
            }
        }
        $hash = EvidenciaIdentidad::hashDocumento($clave);
        foreach (array_keys($porCausa) as $causa) {
            $motivo = GruposSinVia::MOTIVO_POR_CAUSA[$causa];
            [$nuevo] = $this->persistir('identidad_ambigua:documento:'.$hash.':'.self::SUFIJO[$motivo], $hash, $motivo, $a, collect($a)->where('causa', $causa)->pluck('subcausa')->filter()->first());
            $r[$nuevo ? 'nuevos' : 'existentes']++;
        }

        return $r;
    }

    /**
     * @param  array<string,array{ids:list<int>}>  $analisis
     * @return array{0:bool,1:int} [¿se creó?, relaciones agregadas]
     */
    private function persistir(string $claveCaso, string $hash, string $motivo, array $analisis, ?string $subcausa = null): array
    {
        return DB::transaction(function () use ($claveCaso, $hash, $motivo, $analisis, $subcausa) {
            $ahora = now();
            $estado = $motivo === GruposSinVia::MOTIVO_COMPARTIDO ? Conciliacion::ABIERTO : Conciliacion::REQUIERE_SOPORTE;
            $id = DB::table('cf_conciliaciones')->where('clave_idempotencia', $claveCaso)->value('id');
            $nuevo = false;
            if ($id === null) {
                // insertOrIgnore: si otro proceso lo creó entre la lectura y aquí, no falla ni duplica.
                $nuevo = DB::table('cf_conciliaciones')->insertOrIgnore([
                    'tipo' => Conciliacion::TIPO_IDENTIDAD_AMBIGUA, 'estado' => $estado, 'evento_id' => null, 'referencia_tipo' => 'documento', 'referencia_clave' => $hash,
                    'motivo_origen' => $motivo, 'clave_idempotencia' => $claveCaso, 'created_at' => $ahora, 'updated_at' => $ahora,
                ]) === 1;
                $id = DB::table('cf_conciliaciones')->where('clave_idempotencia', $claveCaso)->value('id');
                if ($nuevo) {
                    DB::table('cf_conciliaciones_eventos')->insert([
                        'conciliacion_id' => $id, 'accion' => ConciliacionEvento::DETECTADO, 'estado_anterior' => null, 'estado_nuevo' => $estado, 'motivo' => null,
                        'evidencia' => json_encode(['grupos' => count($analisis), 'motivo_origen' => $motivo, 'subcausa' => $subcausa]), 'actor_id' => null, 'created_at' => $ahora,
                    ]);
                }
            }
            $tiene = DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $id)->pluck('certificado_legado_id')->flip();
            $filas = [];
            $n = 0;
            foreach ($analisis as $x) {
                $n++;
                foreach ($x['ids'] as $cert) {
                    if (! $tiene->has($cert)) {
                        $filas[] = ['conciliacion_id' => $id, 'certificado_legado_id' => $cert, 'rol' => 'grupo_'.$n, 'created_at' => $ahora, 'updated_at' => $ahora];
                    }
                }
            }
            $agregadas = 0;
            foreach (array_chunk($filas, self::LOTE_PIVOTE) as $lote) {
                $agregadas += DB::table('cf_conciliaciones_certificados')->insertOrIgnore($lote);
            }

            return [$nuevo, $agregadas];
        });
    }
}
