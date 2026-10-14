<?php

namespace App\Support\CredentialFlow\Migracion;

use App\Models\CredentialFlow\MigracionCorrida;
use App\Models\Movimiento;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ROLLBACK TÉCNICO de una corrida DELTA (Fase 11F-B). Herramienta de operación (solo los comandos `…:delta-rollback`): sin ruta ni
 * pantalla. Borra SOLO lo que lleva el corrida_id de ESA corrida delta y jamás toca las corridas base (#1/#2) ni el staging.
 *
 * Se niega, sin tocar nada, si la corrida no está completada o si hay actividad posterior sobre lo que creó (otro delta posterior,
 * encuestas delta o base ligadas a sus certificados/eventos, casos de conciliación, códigos asignados, PDF congelado, certificados,
 * correos, descargas o eventos modificados o con dependientes de otro origen). Las encuestas delta se revierten ANTES que las de
 * certificados; los casos, antes de ambas.
 */
final class RollbackDelta
{
    public function __construct(private readonly ?string $confirmoProduccion = null, private readonly string $comando = '') {}

    /** @return array<string,int> conteos borrados (sin datos personales) */
    public function revertirLegado(int $corridaId): array
    {
        GuardiaMigracion::exigirDestinoSeguro(null, $this->confirmoProduccion, $this->comando);

        return DB::transaction(function () use ($corridaId) {
            $corrida = $this->corrida($corridaId, MigracionCorrida::TIPO_LEGADO_DELTA);
            $this->exigirSinActividadPosteriorLegado($corrida);

            $mia = fn (string $tabla): Builder => DB::table($tabla)->where('corrida_id', $corridaId);
            $borrado = [];
            // Primero, los códigos que el delta puso en certificados BASE (vuelven exactamente a NULL); después, lo que el delta creó.
            $borrado['codigos'] = $this->revertirCodigos($corridaId);
            $borrado['descargas'] = $mia('cf_descargas')->delete();
            $borrado['correos'] = $mia('cf_correos')->delete();
            $borrado['certificados'] = $mia('cf_certificados_legado')->delete();

            // Los eventos apuntan a sus plantillas (RESTRICT): se desenlazan antes de borrar las plantillas del delta.
            $mia('cf_eventos')->update(['plantilla_legado_id' => null]);
            $borrado['plantillas'] = $mia('cf_plantillas_legado')->delete();
            // Solo los contenidos de ESTE delta que ya nadie referencia; un contenido reutilizado por SHA es de la base y no se toca.
            $borrado['contenidos'] = $mia('cf_plantillas_legado_contenidos')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('cf_plantillas_legado')->whereColumn('cf_plantillas_legado.contenido_id', 'cf_plantillas_legado_contenidos.id'))
                ->delete();
            $conservados = $mia('cf_plantillas_legado_contenidos')->count();
            $borrado['eventos'] = $mia('cf_eventos')->delete();
            $borrado['mapas'] = DB::table('cf_migraciones_map')->where('corrida_id', $corridaId)->delete();

            $t = $corrida->totales ?? [];
            $esperado = [
                'descargas' => $t['descargas']['migradas'] ?? null, 'correos' => $t['correos']['total'] ?? null,
                'certificados' => $t['certificados'] ?? null, 'eventos' => $t['eventos'] ?? null,
                'codigos' => $t['codigos_actualizados']['certificados'] ?? null,
            ];
            foreach ($esperado as $clave => $n) {
                if ($n !== null && (int) $n !== $borrado[$clave]) {
                    throw new RollbackNoPermitido(RollbackNoPermitido::CAMBIO_CONCURRENTE, "Se esperaba borrar {$n} de «{$clave}» y se borraron {$borrado[$clave]}. Se revierte el rollback.");
                }
            }

            return $this->cerrar($corrida, $borrado + ['contenidos_conservados' => $conservados], 'Rollback técnico de un delta de certificados históricos');
        });
    }

    /**
     * Revierte, UNA A UNA y acotado por id + valor + updated_at, los `codigo_legado` que ESTE delta actualizó en certificados base.
     * Se niega (sin tocar nada: la transacción lo deshace) si después de la corrida el certificado o su código cambió, hay PDF,
     * visibilidad, emisión o código moderno, o sus casos de conciliación ya no son exactamente los del momento de la actualización.
     */
    private function revertirCodigos(int $corridaId): int
    {
        $mapas = DB::table('cf_migraciones_map')->where('corrida_id', $corridaId)->where('origen_tabla', MigradorDelta::MAPA_CODIGO)
            ->where('destino_tabla', 'cf_certificados_legado')->orderBy('id')->get();
        $no = fn (string $codigo, string $mensaje) => throw new RollbackNoPermitido($codigo, $mensaje);
        $n = 0;

        foreach ($mapas as $m) {
            $d = json_decode((string) $m->detalle, true) ?: [];
            $cert = DB::table('cf_certificados_legado')->where('id', $m->destino_id)->first();
            if ($cert === null || (string) $cert->codigo_legado !== (string) ($d['codigo_nuevo'] ?? '') || (string) $cert->updated_at !== (string) ($d['updated_at_nuevo'] ?? '')) {
                $no(RollbackNoPermitido::CODIGO_ACTUALIZADO_MODIFICADO, 'Un código legado actualizado por este delta fue modificado después (o el certificado cambió): no se revierte.');
            }
            if ($cert->pdf_archivo !== null || $cert->pdf_hash !== null || $cert->materializado_at !== null) {
                $no(RollbackNoPermitido::PDF_CONGELADO, 'Un certificado con código actualizado por este delta tiene PDF congelado: no se revierte.');
            }
            if ((bool) $cert->visible_portal || $cert->estado !== 'vigente' || $cert->revocado_at !== null || $cert->reemplazado_por_emision_id !== null
                || (int) $cert->intentos_generacion > 0 || $cert->update_by !== null
                || DB::table('cf_descargas')->where('certificado_legado_id', $cert->id)->whereNotNull('emision_id')->exists()) {
                $no(RollbackNoPermitido::CERTIFICADOS_MODIFICADOS, 'Un certificado con código actualizado por este delta fue modificado, emitido o publicado: no se revierte.');
            }
            if (Schema::hasTable('cf_codigos_historicos') && DB::table('cf_codigos_historicos')->where('certificado_canonico_id', $cert->id)->exists()) {
                $no(RollbackNoPermitido::CODIGOS_ASIGNADOS, 'Hay un código moderno asignado sobre un certificado con código actualizado por este delta: no se revierte.');
            }
            $casos = DB::table('cf_conciliaciones_certificados as cc')->join('cf_conciliaciones as c', 'c.id', '=', 'cc.conciliacion_id')
                ->where('cc.certificado_legado_id', $cert->id)->orderBy('c.id')->get(['c.id', 'c.estado', 'c.updated_at'])
                ->map(fn ($c) => ['id' => (int) $c->id, 'estado' => $c->estado, 'updated_at' => (string) $c->updated_at])->all();
            if ($casos !== ($d['casos_vinculados'] ?? [])) {
                $no(RollbackNoPermitido::CASOS_POSTERIORES, 'Los casos de conciliación de un certificado con código actualizado por este delta cambiaron: se revierten primero esos casos.');
            }

            $filas = DB::table('cf_certificados_legado')->where('id', $cert->id)->where('codigo_legado', $d['codigo_nuevo'])->where('updated_at', $d['updated_at_nuevo'])
                ->update(['codigo_legado' => null, 'updated_at' => $d['updated_at_anterior']]);
            if ($filas !== 1) {
                $no(RollbackNoPermitido::CAMBIO_CONCURRENTE, 'El código legado de un certificado cambió durante el rollback. Se revierte el rollback.');
            }
            $n++;
        }

        return $n;
    }

    /** @return array<string,int> */
    public function revertirEncuestas(int $corridaId): array
    {
        GuardiaMigracion::exigirDestinoSeguro(null, $this->confirmoProduccion, $this->comando);

        return DB::transaction(function () use ($corridaId) {
            $corrida = $this->corrida($corridaId, MigracionCorrida::TIPO_ENCUESTAS_DELTA);
            if (MigracionCorrida::where('tipo', $corrida->tipo)->where('id', '>', $corridaId)->where('estado', MigracionCorrida::ESTADO_COMPLETADA)->exists()) {
                throw new RollbackNoPermitido(RollbackNoPermitido::CORRIDA_POSTERIOR, 'Hay un delta de encuestas completado posterior: se revierte primero ese.');
            }
            $mias = DB::table('cf_encuestas_respuestas')->where('corrida_id', $corridaId);
            if ((clone $mias)->where(fn ($q) => $q->whereColumn('updated_at', '!=', 'created_at')->orWhereNotNull('emision_id'))->exists()) {
                throw new RollbackNoPermitido(RollbackNoPermitido::RESPUESTAS_MODIFICADAS, 'Hay respuestas del delta modificadas después de la migración.');
            }

            $borrado = [];
            $borrado['detalles'] = DB::table('cf_encuestas_respuestas_detalle')->whereIn('respuesta_id', (clone $mias)->select('id'))->delete();
            $borrado['respuestas'] = (clone $mias)->delete();
            $borrado['mapas'] = DB::table('cf_migraciones_map')->where('corrida_id', $corridaId)->delete();

            $t = $corrida->totales ?? [];
            foreach (['respuestas' => $t['respuestas'] ?? null, 'detalles' => $t['detalles'] ?? null] as $clave => $n) {
                if ($n !== null && (int) $n !== $borrado[$clave]) {
                    throw new RollbackNoPermitido(RollbackNoPermitido::CAMBIO_CONCURRENTE, "Se esperaba borrar {$n} de «{$clave}» y se borraron {$borrado[$clave]}. Se revierte el rollback.");
                }
            }

            return $this->cerrar($corrida, $borrado, 'Rollback técnico de un delta de encuestas históricas');
        });
    }

    private function corrida(int $id, string $tipo): MigracionCorrida
    {
        $corrida = MigracionCorrida::lockForUpdate()->find($id) ?? throw new RollbackNoPermitido(RollbackNoPermitido::NO_EXISTE, "No existe la corrida #{$id}.");
        if ($corrida->tipo !== $tipo) {
            throw new RollbackNoPermitido(RollbackNoPermitido::TIPO_NO_ES_DELTA, "La corrida #{$id} no es una corrida «{$tipo}»: las corridas base no se revierten con esta herramienta.");
        }
        if ($corrida->estado !== MigracionCorrida::ESTADO_COMPLETADA) {
            throw new RollbackNoPermitido(RollbackNoPermitido::NO_COMPLETADA, "La corrida #{$id} está «{$corrida->estado}»: solo se revierte una corrida completada.");
        }

        return $corrida;
    }

    /** @param array<string,int> $resultado */
    private function cerrar(MigracionCorrida $corrida, array $resultado, string $descripcion): array
    {
        $t = $corrida->totales ?? [];
        $t['rollback'] = $resultado;
        MigracionCorrida::whereKey($corrida->id)->update(['estado' => MigracionCorrida::ESTADO_REVERTIDA, 'rollback_at' => now(), 'totales' => json_encode($t, JSON_UNESCAPED_UNICODE)]);
        Movimiento::registrar('rollback', 'credential-flow', $descripcion, ['corrida_id' => $corrida->id] + $resultado);

        return $resultado;
    }

    /** @throws RollbackNoPermitido */
    private function exigirSinActividadPosteriorLegado(MigracionCorrida $c): void
    {
        $id = $c->id;
        $no = fn (string $codigo, string $mensaje) => throw new RollbackNoPermitido($codigo, $mensaje);

        if (MigracionCorrida::where('tipo', $c->tipo)->where('id', '>', $id)->where('estado', MigracionCorrida::ESTADO_COMPLETADA)->exists()) {
            $no(RollbackNoPermitido::CORRIDA_POSTERIOR, 'Hay un delta de certificados completado posterior: se revierte primero ese.');
        }
        $certs = fn () => DB::table('cf_certificados_legado')->where('corrida_id', $id);
        $eventos = fn () => DB::table('cf_eventos')->where('corrida_id', $id);

        // Encuestas (base o delta) ligadas a certificados o eventos del delta: se revierten primero.
        if (Schema::hasTable('cf_encuestas_respuestas')
            && (DB::table('cf_encuestas_respuestas')->whereIn('certificado_legado_id', $certs()->select('id'))->exists()
                || DB::table('cf_encuestas_respuestas')->whereIn('evento_id', $eventos()->select('id'))->exists())) {
            $no(RollbackNoPermitido::ENCUESTAS_VINCULADAS, 'Hay respuestas de encuestas ligadas a certificados o eventos de este delta: se revierte primero el delta de encuestas.');
        }
        // Casos de conciliación sobre certificados o eventos del delta (el rollback de casos es aparte y todo-o-nada).
        if (Schema::hasTable('cf_conciliaciones_certificados')
            && (DB::table('cf_conciliaciones_certificados')->whereIn('certificado_legado_id', $certs()->select('id'))->exists()
                || DB::table('cf_conciliaciones')->whereIn('evento_id', $eventos()->select('id'))->exists())) {
            $no(RollbackNoPermitido::CASOS_POSTERIORES, 'Hay casos de conciliación sobre certificados o eventos de este delta: se revierten primero esos casos.');
        }
        // Casos que referencian por CLAVE (sin FK) una plantilla, un evento, un certificado o un grupo de este delta: quedarían colgando.
        if (Schema::hasTable('cf_conciliaciones')) {
            $referencias = [
                'plantilla_legado' => DB::table('cf_plantillas_legado')->where('corrida_id', $id)->pluck('id'),
                'evento' => $eventos()->pluck('id'),
                'certificado' => $certs()->pluck('id'),
                'grupo_duplicado' => $certs()->whereNotNull('grupo_duplicado')->distinct()->pluck('grupo_duplicado'),
            ];
            foreach ($referencias as $tipo => $claves) {
                foreach (array_chunk($claves->map(fn ($c) => (string) $c)->all(), 500) as $trozo) {
                    if (DB::table('cf_conciliaciones')->where('referencia_tipo', $tipo)->whereIn('referencia_clave', $trozo)->exists()) {
                        $no(RollbackNoPermitido::CASOS_POSTERIORES, "Hay casos de conciliación que referencian ({$tipo}) lo creado por este delta: se revierten primero esos casos.");
                    }
                }
            }
        }
        if (Schema::hasTable('cf_codigos_historicos')
            && DB::table('cf_codigos_historicos')->where(fn ($q) => $q->whereIn('certificado_canonico_id', $certs()->select('id'))->orWhereIn('evento_id', $eventos()->select('id')))->exists()) {
            $no(RollbackNoPermitido::CODIGOS_ASIGNADOS, 'Hay códigos históricos asignados sobre certificados de este delta: son permanentes; no se revierte.');
        }
        if ($certs()->where(fn ($q) => $q->whereNotNull('pdf_archivo')->orWhereNotNull('pdf_hash')->orWhereNotNull('materializado_at'))->exists()) {
            $no(RollbackNoPermitido::PDF_CONGELADO, 'Hay certificados del delta con PDF congelado: no se revierte.');
        }
        if ($certs()->where(fn ($q) => $q->where('visible_portal', true)->orWhere('estado', '!=', 'vigente')->orWhere('intentos_generacion', '>', 0)
            ->orWhereNotNull('revocado_at')->orWhereNotNull('reemplazado_por_emision_id')->orWhereNotNull('update_by')->orWhereColumn('updated_at', '!=', 'created_at'))->exists()) {
            $no(RollbackNoPermitido::CERTIFICADOS_MODIFICADOS, 'Hay certificados del delta modificados después de la migración.');
        }

        $descargasAjenas = DB::table('cf_descargas')->whereIn('certificado_legado_id', $certs()->select('id'))->where(fn ($q) => $q->whereNull('corrida_id')->orWhere('corrida_id', '!=', $id));
        $descargasPropias = DB::table('cf_descargas')->where('corrida_id', $id);
        // Las descargas del delta SOBRE certificados base son de este delta y se borran; sobre certificados del delta solo caben las propias.
        if ($descargasAjenas->exists() || (clone $descargasPropias)->where(fn ($q) => $q->whereNotNull('participante_id')->orWhereNotNull('emision_id')->orWhereColumn('updated_at', '!=', 'created_at'))->exists()) {
            $no(RollbackNoPermitido::DESCARGAS_NUEVAS, 'Hay descargas nuevas (o modificadas) sobre estos certificados: es actividad posterior.');
        }
        $correosAjenos = DB::table('cf_correos')->whereIn('certificado_legado_id', $certs()->select('id'))->where(fn ($q) => $q->whereNull('corrida_id')->orWhere('corrida_id', '!=', $id));
        $correosPropios = DB::table('cf_correos')->where('corrida_id', $id);
        if ($correosAjenos->exists() || (clone $correosPropios)->where(fn ($q) => $q->whereNotNull('update_by')->orWhere('es_principal', true)->orWhereColumn('updated_at', '!=', 'created_at'))->exists()) {
            $no(RollbackNoPermitido::CORREOS_NUEVOS, 'Hay correos nuevos o modificados sobre estos certificados: es actividad posterior.');
        }
        if (DB::table('cf_lotes')->whereIn('evento_id', $eventos()->select('id'))->exists()
            || DB::table('cf_certificados_legado')->whereIn('evento_id', $eventos()->select('id'))->where(fn ($q) => $q->whereNull('corrida_id')->orWhere('corrida_id', '!=', $id))->exists()
            || $eventos()->where(fn ($q) => $q->whereNotNull('deleted_at')->orWhereNotNull('update_by')->orWhereColumn('updated_at', '!=', 'created_at'))->exists()) {
            $no(RollbackNoPermitido::EVENTOS_EN_USO, 'Hay bases, certificados de otro origen o ediciones posteriores sobre los eventos del delta.');
        }
        $plantillas = DB::table('cf_plantillas_legado')->where('corrida_id', $id)->select('id');
        if (DB::table('cf_certificados_legado')->whereIn('plantilla_legado_id', $plantillas)->where(fn ($q) => $q->whereNull('corrida_id')->orWhere('corrida_id', '!=', $id))->exists()
            || DB::table('cf_eventos')->whereIn('plantilla_legado_id', $plantillas)->where(fn ($q) => $q->whereNull('corrida_id')->orWhere('corrida_id', '!=', $id))->exists()
            || DB::table('cf_plantillas_legado')->where('corrida_id', $id)->where(fn ($q) => $q->whereNotNull('update_by')->orWhereColumn('updated_at', '!=', 'created_at'))->exists()) {
            $no(RollbackNoPermitido::PLANTILLAS_COMPARTIDAS, 'Hay certificados o eventos de otro origen (o ediciones) que usan las plantillas del delta.');
        }
    }
}
