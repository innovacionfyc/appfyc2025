<?php

namespace App\Support\CredentialFlow\Legado;

use App\Models\CredentialFlow\CertificadoLegado;
use Illuminate\Support\Facades\DB;

/**
 * Política de elegibilidad y de canónicos del histórico. Funciones sobre la base de datos, sin efectos.
 *
 * PDF solo en el CANÓNICO (política A): un grupo de duplicados idénticos tiene UN certificado canónico y N filas
 * `duplicado_consolidado` que resuelven a él. Así no se guardan dos PDFs iguales y la verificación es una sola.
 *
 * Elegible para generar/servir un PDF: vigente, conciliación `ok`, plantilla utilizable, documento válido, datos presentes y sin
 * reemplazo por una emisión moderna. Nunca para `pendiente_plantilla`, `pendiente_conciliacion` ni `revision_documento`.
 * La AUSENCIA de código no bloquea (ver CodigoHistorico): se asigna de forma perezosa al generar.
 */
final class ElegibilidadLegado
{
    /** Motivos por los que NO se genera ni se ofrece un PDF. */
    public const REVOCADO = 'REVOCADO';

    public const REEMPLAZADO = 'REEMPLAZADO';

    public const REVISION_DOCUMENTO = 'REVISION_DOCUMENTO';

    public const PENDIENTE_CONCILIACION = 'PENDIENTE_CONCILIACION';

    public const PENDIENTE_PLANTILLA = 'PENDIENTE_PLANTILLA';

    public const SIN_CANONICO = 'SIN_CANONICO';

    public const DATOS_FALTANTES = 'DATOS_FALTANTES';

    public const NO_CONCILIADO = 'NO_CONCILIADO';

    /** El certificado sobre el que se actúa: él mismo o, si es un duplicado idéntico consolidado, su canónico (null si no se puede resolver). */
    public static function canonico(CertificadoLegado $c): ?CertificadoLegado
    {
        if ($c->conciliacion_estado !== CertificadoLegado::CONCILIACION_DUPLICADO_CONSOLIDADO) {
            return $c;
        }
        if ($c->grupo_duplicado === null) {
            return null;
        }
        $id = DB::table('cf_certificados_legado as k')
            ->join('cf_migraciones_map as m', fn ($j) => $j->on('m.destino_id', '=', 'k.id')->where('m.destino_tabla', 'cf_certificados_legado')->where('m.relacion', 'canonico'))
            ->where('k.grupo_duplicado', $c->grupo_duplicado)->orderBy('k.id')->value('k.id');

        return $id === null ? null : CertificadoLegado::find($id);
    }

    /** Motivo por el que NO es elegible (código), o null si lo es. Se evalúa sobre el certificado canónico. */
    public static function motivo(CertificadoLegado $c): ?string
    {
        $snap = $c->snapshot_legado ?? [];

        return match (true) {
            $c->estado === CertificadoLegado::ESTADO_REVOCADO => self::REVOCADO,
            $c->estado === CertificadoLegado::ESTADO_REEMPLAZADO || $c->reemplazado_por_emision_id !== null => self::REEMPLAZADO,
            $c->conciliacion_estado === CertificadoLegado::CONCILIACION_REVISION_DOCUMENTO => self::REVISION_DOCUMENTO,
            $c->conciliacion_estado === CertificadoLegado::CONCILIACION_PENDIENTE => self::PENDIENTE_CONCILIACION,
            $c->conciliacion_estado === CertificadoLegado::CONCILIACION_PENDIENTE_PLANTILLA || $c->plantilla_legado_id === null => self::PENDIENTE_PLANTILLA,
            $c->conciliacion_estado !== CertificadoLegado::CONCILIACION_OK => self::NO_CONCILIADO,
            ($snap['documento_estado'] ?? 'valido') !== 'valido' => self::REVISION_DOCUMENTO,
            // Fase 10B-1.5: un `codigo_legado` NULL NO es un defecto. En el sistema viejo el código se asignaba en la primera descarga; aquí lo
            // asigna CodigoHistorico al generar el PDF (nunca durante una consulta de elegibilidad).
            trim((string) $c->nombre_completo) === '' || trim((string) $c->documento) === '' => self::DATOS_FALTANTES,
            default => null,
        };
    }
}
