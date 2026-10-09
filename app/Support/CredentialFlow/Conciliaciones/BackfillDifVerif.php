<?php

namespace App\Support\CredentialFlow\Conciliaciones;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Conciliacion;
use App\Support\CredentialFlow\Rehearsal\GuardiaClave;
use Illuminate\Support\Facades\DB;

/**
 * BACKFILL explícito de los casos DIF_VERIF (Fase 11A). El detector general NO crea estos casos desde 10B-1.5 (codigo_legado NULL significa «nunca descargado», no «código
 * inválido»), pero 10B-2A (`ConsolidacionCodigo`) necesita su población histórica: este comando la reconstruye por REGLAS, sin ids fijos, sin tocar el detector general y sin
 * mezclar la semántica de códigos.
 *
 * Criterio exacto (el mismo predicado que ya aplica el detector para OMITIRlos, `ClasificadorVariantes::soloDifiereEnCodigoNulo`):
 *  - certificados en `pendiente_conciliacion` con `grupo_duplicado` (mismo certificado lógico: nunca un certificado suelto);
 *  - TODAS las variantes del grupo (aunque alguna tuviera otro estado) y ≥ 2;
 *  - el snapshot del grupo marca como única diferencia `DIF_VERIF` (comparación estricta del migrador: nombre, documento, tipo, correo y plantilla iguales);
 *  - exactamente UN código legado distinto, presente en al menos una variante y ausente (NULL/vacío) en al menos otra.
 * El caso creado es idéntico en forma al que creaba el detector antiguo (`conflicto_variantes`, referencia `grupo_duplicado`, motivo `DIF_VERIF`, rol `variante`, clave
 * `conflicto_variantes:grupo_duplicado:{grupo}`), por eso es idempotente y compatible con el detector actual (que lo ve como «existente»). El evento «detectado» lleva
 * `origen = backfill_dif_verif_11a`, lo que permite revertirlo de forma selectiva. Sin PII (solo ids y conteos). Solo escribe en `cf_conciliaciones*`.
 */
final class BackfillDifVerif
{
    public const ORIGEN = 'backfill_dif_verif_11a';

    public function __construct(private readonly DetectorConciliaciones $detector) {}

    /**
     * Planifica sin escribir. @return list<array<string,mixed>>
     */
    public function planear(): array
    {
        $filas = DB::table('cf_certificados_legado')->where('conciliacion_estado', CertificadoLegado::CONCILIACION_PENDIENTE)->whereNotNull('grupo_duplicado')->orderBy('id')->get(['id', 'grupo_duplicado', 'snapshot_legado']);
        $grupos = $filas->pluck('grupo_duplicado')->unique()->values()->all();
        if ($grupos === []) {
            return [];
        }
        $miembros = DB::table('cf_certificados_legado')->whereIn('grupo_duplicado', $grupos)->orderBy('id')->get(['id', 'evento_id', 'grupo_duplicado', 'codigo_legado'])->groupBy('grupo_duplicado');
        $casos = [];
        foreach ($filas->groupBy('grupo_duplicado') as $grupo => $pendientes) {
            $todos = $miembros[$grupo] ?? collect();
            $snap = json_decode((string) $pendientes->first()->snapshot_legado, true) ?: [];
            if (! ClasificadorVariantes::soloDifiereEnCodigoNulo($todos, $snap)) {
                continue;
            }
            $casos[] = $this->detector->casoPlan(
                Conciliacion::TIPO_CONFLICTO_VARIANTES, 'grupo_duplicado', (string) $grupo, 'DIF_VERIF', $this->detector->eventoUnicoDe($todos->pluck('evento_id')),
                $todos->map(fn ($t) => [(int) $t->id, 'variante'])->all(),
            );
        }

        return $casos;
    }

    /**
     * @return array{detectados:int,nuevos:int,existentes:int,certificados:int,relaciones_agregadas:int,con_descarga_en_la_canonica:int,simulado:bool}
     */
    public function ejecutar(bool $simular = false): array
    {
        GuardiaClave::exigirCoincidencia();
        $plan = $this->planear();
        $existentes = DB::table('cf_conciliaciones')->pluck('id', 'clave_idempotencia');
        $r = ['detectados' => count($plan), 'nuevos' => 0, 'existentes' => 0, 'certificados' => 0, 'relaciones_agregadas' => 0, 'con_descarga_en_la_canonica' => 0, 'simulado' => $simular];
        foreach ($plan as $c) {
            $r['certificados'] += count($c['certificados']);
            $ids = collect($c['certificados'])->pluck(0)->all();
            if (DB::table('cf_descargas')->whereIn('certificado_legado_id', DB::table('cf_certificados_legado')->whereIn('id', $ids)->whereNotNull('codigo_legado')->select('id'))->exists()) {
                $r['con_descarga_en_la_canonica']++;
            }
            if ($simular) {
                $r[$existentes->has($c['clave']) ? 'existentes' : 'nuevos']++;

                continue;
            }
            [$nuevo, $agregadas] = $this->detector->persistir($c, ['origen' => self::ORIGEN]);
            $r[$nuevo ? 'nuevos' : 'existentes']++;
            $r['relaciones_agregadas'] += $agregadas;
        }

        return $r;
    }

    /**
     * Revierte SOLO los casos creados por este backfill que siguen intactos: abiertos, con el único evento «detectado» (origen del backfill) y sin decisiones.
     *
     * @return array{revertidos:int,conservados:int}
     */
    public function revertir(): array
    {
        $r = ['revertidos' => 0, 'conservados' => 0];
        $ids = DB::table('cf_conciliaciones_eventos')->where('accion', 'detectado')->where('evidencia->origen', self::ORIGEN)->pluck('conciliacion_id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$r) {
                $caso = DB::table('cf_conciliaciones')->where('id', $id)->lockForUpdate()->first();
                $intacto = $caso !== null && $caso->estado === Conciliacion::ABIERTO && DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $id)->count() === 1;
                if (! $intacto) {
                    $r['conservados']++;

                    return;
                }
                DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $id)->delete();
                DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $id)->delete();
                DB::table('cf_conciliaciones')->where('id', $id)->delete();
                $r['revertidos']++;
            });
        }

        return $r;
    }
}
