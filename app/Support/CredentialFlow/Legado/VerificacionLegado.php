<?php

namespace App\Support\CredentialFlow\Legado;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Support\CredentialFlow\Reemplazo\CertificadoLogico;
use App\Support\CredentialFlow\Reemplazo\EmisionVigente;
use Illuminate\Support\Facades\DB;

/**
 * Resuelve un código LEGADO (numérico corto) contra cf_certificados_legado. Separado a propósito de la verificación moderna
 * (cf_emisiones): un código legado nunca se busca como moderno ni al revés.
 *
 * `codigo_legado` NO es único: un mismo código aparece en varias filas de un mismo duplicado. Se agrupa por significado histórico
 * (evento + documento): un solo par → una única verificación; pares distintos → anomalía (COLISION) que no revela nada.
 * Los duplicados idénticos se comportan como un único certificado: manda el canónico. Búsqueda exacta, nunca parcial.
 */
final class VerificacionLegado
{
    /** Formato legado observado: 4 o 5 dígitos (5237–11215 en el snapshot). */
    public const FORMATO = '/^\d{4,5}$/';

    public static function esFormatoLegado(string $codigo): bool
    {
        return preg_match(self::FORMATO, $codigo) === 1;
    }

    public function resolver(string $codigo): ResultadoVerificacionLegado
    {
        if (! self::esFormatoLegado($codigo)) {
            return new ResultadoVerificacionLegado(ResultadoVerificacionLegado::NO_ENCONTRADO);
        }

        $filas = DB::table('cf_certificados_legado as c')->join('cf_eventos as e', 'e.id', '=', 'c.evento_id')->where('c.codigo_legado', $codigo)
            ->get(['c.id', 'c.evento_id', 'c.documento_clave', 'c.estado', 'c.conciliacion_estado', 'c.reemplazado_por_emision_id', 'e.nombre as evento', 'e.anio']);
        // Códigos asignados por Credential Flow (Fase 10B-1.5): se resuelven al par de su certificado canónico. Mismo comportamiento y
        // misma respuesta: nada revela si el código es histórico o asignado.
        $asignado = DB::table('cf_codigos_historicos')->where('codigo', $codigo)->first(['certificado_canonico_id']);
        if ($asignado !== null) {
            $canon = DB::table('cf_certificados_legado')->where('id', $asignado->certificado_canonico_id)->first(['evento_id', 'documento_clave']);
            $delPar = $canon === null ? collect() : DB::table('cf_certificados_legado as c')->join('cf_eventos as e', 'e.id', '=', 'c.evento_id')->where('c.evento_id', $canon->evento_id)->where('c.documento_clave', $canon->documento_clave)
                ->get(['c.id', 'c.evento_id', 'c.documento_clave', 'c.estado', 'c.conciliacion_estado', 'c.reemplazado_por_emision_id', 'e.nombre as evento', 'e.anio']);
            $filas = $filas->concat($delPar)->unique('id')->values();
        }
        if ($filas->isEmpty()) {
            return new ResultadoVerificacionLegado(ResultadoVerificacionLegado::NO_ENCONTRADO);
        }

        // Un mismo código en pares (evento, documento) distintos es una anomalía: no se revela nada.
        $pares = $filas->map(fn ($f) => $f->evento_id."\0".$f->documento_clave)->unique();
        if ($pares->count() > 1) {
            return new ResultadoVerificacionLegado(ResultadoVerificacionLegado::COLISION, pares: $pares->count());
        }

        // Duplicados idénticos → un único certificado: manda el canónico.
        $vigentes = $filas->reject(fn ($f) => $f->conciliacion_estado === CertificadoLegado::CONCILIACION_DUPLICADO_CONSOLIDADO)->values();
        if ($vigentes->isEmpty()) {
            $canonico = ElegibilidadLegado::canonico(CertificadoLegado::findOrFail($filas->first()->id));
            if ($canonico === null) {
                return new ResultadoVerificacionLegado(ResultadoVerificacionLegado::COLISION, pares: 0);
            }
            $vigentes = DB::table('cf_certificados_legado as c')->join('cf_eventos as e', 'e.id', '=', 'c.evento_id')->where('c.id', $canonico->id)
                ->get(['c.id', 'c.evento_id', 'c.documento_clave', 'c.estado', 'c.conciliacion_estado', 'c.reemplazado_por_emision_id', 'e.nombre as evento', 'e.anio']);
        }
        $f = $vigentes->first();
        $evento = (string) $f->evento;
        $anio = $f->anio === null ? null : (int) $f->anio;

        if ($vigentes->contains(fn ($x) => $x->estado === CertificadoLegado::ESTADO_REVOCADO)) {
            return new ResultadoVerificacionLegado(ResultadoVerificacionLegado::REVOCADO, $evento, $anio);
        }
        // Una variante de un certificado lógico (grupo de duplicados) resuelve por la fila del grupo que lleva el reemplazo (10B-2B-2C.1).
        $reemplazo = $vigentes->first(fn ($x) => $x->estado === CertificadoLegado::ESTADO_REEMPLAZADO || $x->reemplazado_por_emision_id !== null)
            ?? $vigentes->map(fn ($x) => CertificadoLogico::reemplazada((int) $x->id))->filter()->first();
        if ($reemplazo) {
            // El código histórico conduce a la emisión VIGENTE actual de la cadena (histórico → A → B → C llega a C, no se queda en A). Si ya no hay
            // vigente (revocada), a la última emisión, que mostrará su revocación. Una anomalía (ciclo, cadena corrupta, emisión faltante, histórico
            // «reemplazado» sin emisión) no se detalla: el registro «está en revisión».
            $cadena = $reemplazo->reemplazado_por_emision_id === null ? null : EmisionVigente::desdeEmision((int) $reemplazo->reemplazado_por_emision_id);
            if ($cadena === null || $cadena['estado'] === EmisionVigente::ESTADO_ANOMALIA) {
                return new ResultadoVerificacionLegado(ResultadoVerificacionLegado::EN_REVISION);
            }

            return new ResultadoVerificacionLegado(ResultadoVerificacionLegado::REEMPLAZADO, $evento, $anio, ($cadena['vigente'] ?? $cadena['ultima'])->codigo);
        }
        // Registro histórico en revisión (documento, conciliación o plantilla): se confirma que existe, sin decir por qué.
        if ($vigentes->contains(fn ($x) => $x->conciliacion_estado !== CertificadoLegado::CONCILIACION_OK)) {
            return new ResultadoVerificacionLegado(ResultadoVerificacionLegado::EN_REVISION);
        }

        return new ResultadoVerificacionLegado(ResultadoVerificacionLegado::VALIDO, $evento, $anio);
    }
}
