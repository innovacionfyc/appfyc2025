<?php

namespace App\Support\CredentialFlow\Migracion;

use App\Models\CredentialFlow\MigracionCorrida;
use App\Models\Movimiento;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ROLLBACK TÉCNICO de una corrida de ENCUESTAS HISTÓRICAS (Fase 8). Herramienta de operación (solo el comando
 * credential-flow:encuestas:rollback-corrida): sin ruta ni pantalla. Borra SOLO lo que lleva el corrida_id de esa corrida y nunca toca
 * el staging, los certificados ni los eventos.
 *
 * Solo se permite si la corrida está COMPLETADA y NADA ha pasado después: ninguna corrida posterior de encuestas, ninguna respuesta
 * nueva sobre sus versiones, ninguna edición de la estructura ni de las respuestas históricas. Las respuestas futuras y las encuestas o
 * versiones nuevas creadas con el motor (sin corrida) SE CONSERVAN siempre: si existen respuestas nuevas sobre una versión histórica, se
 * NIEGA sin tocar nada (no se puede dejar huérfana una respuesta nueva).
 *
 * Orden (UNA transacción): detalle → respuestas → opciones → preguntas → versiones → encuestas sin versiones restantes → mapas → corrida.
 */
final class RollbackEncuestas
{
    /** @return array<string,mixed> conteos borrados (sin datos personales) */
    public function revertir(int $corridaId): array
    {
        GuardiaMigracion::exigirDestinoSeguro();

        return DB::transaction(function () use ($corridaId) {
            $corrida = MigracionCorrida::lockForUpdate()->find($corridaId) ?? throw new RollbackNoPermitido(RollbackNoPermitido::NO_EXISTE, "No existe la corrida #{$corridaId}.");
            if ($corrida->tipo !== MigracionCorrida::TIPO_ENCUESTAS) {
                throw new RollbackNoPermitido(RollbackNoPermitido::NO_EXISTE, "La corrida #{$corridaId} no es una corrida de encuestas.");
            }
            if ($corrida->estado !== MigracionCorrida::ESTADO_COMPLETADA) {
                throw new RollbackNoPermitido(RollbackNoPermitido::NO_COMPLETADA, "La corrida #{$corridaId} está «{$corrida->estado}»: solo se revierte una corrida completada.");
            }
            $this->exigirSinActividadPosterior($corrida);

            $mia = fn (string $tabla): Builder => DB::table($tabla)->where('corrida_id', $corridaId);
            $versiones = fn () => $mia('cf_encuestas_versiones')->select('id');
            $preguntas = fn () => DB::table('cf_encuestas_preguntas')->whereIn('version_id', $versiones())->select('id');
            $borrado = [];

            $borrado['detalles'] = DB::table('cf_encuestas_respuestas_detalle')->whereIn('respuesta_id', $mia('cf_encuestas_respuestas')->select('id'))->delete();
            $borrado['respuestas'] = $mia('cf_encuestas_respuestas')->delete();
            $borrado['opciones'] = DB::table('cf_encuestas_opciones')->whereIn('pregunta_id', $preguntas())->delete();
            $borrado['preguntas'] = DB::table('cf_encuestas_preguntas')->whereIn('version_id', $versiones())->delete();
            $borrado['versiones'] = $mia('cf_encuestas_versiones')->delete();
            // La encuesta se conserva si alguien le añadió versiones nuevas (sin corrida).
            $borrado['encuestas'] = $mia('cf_encuestas')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('cf_encuestas_versiones')->whereColumn('cf_encuestas_versiones.encuesta_id', 'cf_encuestas.id'))->delete();
            $conservadas = $mia('cf_encuestas')->count();
            $borrado['mapas'] = DB::table('cf_migraciones_map')->where('corrida_id', $corridaId)->delete();

            $t = $corrida->totales ?? [];
            $esperado = [
                'respuestas' => $t['respuestas'] ?? null, 'detalles' => $t['detalles'] ?? null, 'versiones' => isset($t['versiones']) ? count($t['versiones']) : null,
                'preguntas' => isset($t['versiones']) ? array_sum(array_column($t['versiones'], 'preguntas')) : null,
                'opciones' => isset($t['versiones']) ? array_sum(array_column($t['versiones'], 'opciones')) : null,
            ];
            foreach ($esperado as $clave => $n) {
                if ($n !== null && (int) $n !== $borrado[$clave]) {
                    throw new RollbackNoPermitido(RollbackNoPermitido::CAMBIO_CONCURRENTE, "Se esperaba borrar {$n} de «{$clave}» y se borraron {$borrado[$clave]}. Se revierte el rollback.");
                }
            }

            $resultado = $borrado + ['encuestas_conservadas' => $conservadas];
            $t['rollback'] = $resultado;
            MigracionCorrida::whereKey($corridaId)->update(['estado' => MigracionCorrida::ESTADO_REVERTIDA, 'rollback_at' => now(), 'totales' => json_encode($t, JSON_UNESCAPED_UNICODE)]);
            Movimiento::registrar('rollback', 'credential-flow', 'Rollback técnico de una corrida de encuestas históricas', ['corrida_id' => $corridaId] + $resultado);

            return $resultado;
        });
    }

    /** @throws RollbackNoPermitido */
    private function exigirSinActividadPosterior(MigracionCorrida $c): void
    {
        $id = $c->id;
        $no = fn (string $codigo, string $mensaje) => throw new RollbackNoPermitido($codigo, $mensaje);

        if (MigracionCorrida::where('tipo', $c->tipo)->where('id', '>', $id)->where('estado', MigracionCorrida::ESTADO_COMPLETADA)->exists()) {
            $no(RollbackNoPermitido::CORRIDA_POSTERIOR, 'Hay una corrida de encuestas completada posterior: se revierte primero esa.');
        }
        $versiones = DB::table('cf_encuestas_versiones')->where('corrida_id', $id)->select('id');

        if (DB::table('cf_encuestas_respuestas')->whereIn('version_id', $versiones)->where(fn ($q) => $q->whereNull('corrida_id')->orWhere('corrida_id', '!=', $id))->exists()) {
            $no(RollbackNoPermitido::RESPUESTAS_POSTERIORES, 'Hay respuestas nuevas sobre las versiones históricas: es actividad posterior; no se revierte.');
        }
        if (DB::table('cf_encuestas_respuestas')->where('corrida_id', $id)->where(fn ($q) => $q->whereColumn('updated_at', '!=', 'created_at')->orWhereNotNull('emision_id'))->exists()) {
            $no(RollbackNoPermitido::RESPUESTAS_MODIFICADAS, 'Hay respuestas históricas modificadas después de la migración.');
        }
        $t = $c->totales ?? [];
        $preguntasEsperadas = isset($t['versiones']) ? array_sum(array_column($t['versiones'], 'preguntas')) : null;
        if (DB::table('cf_encuestas_versiones')->where('corrida_id', $id)->where(fn ($q) => $q->whereNotNull('update_by')->orWhereColumn('updated_at', '!=', 'created_at'))->exists()
            || DB::table('cf_encuestas_preguntas')->whereIn('version_id', $versiones)->whereColumn('updated_at', '!=', 'created_at')->exists()
            || DB::table('cf_encuestas_opciones')->whereIn('pregunta_id', DB::table('cf_encuestas_preguntas')->whereIn('version_id', $versiones)->select('id'))->whereColumn('updated_at', '!=', 'created_at')->exists()
            || ($preguntasEsperadas !== null && DB::table('cf_encuestas_preguntas')->whereIn('version_id', $versiones)->count() !== (int) $preguntasEsperadas)) {
            $no(RollbackNoPermitido::ESTRUCTURA_MODIFICADA, 'La estructura de las versiones históricas se modificó después de la migración.');
        }
    }
}
