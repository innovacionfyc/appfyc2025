<?php

namespace App\Support\CredentialFlow\Conciliaciones;

use App\Models\CredentialFlow\CertificadoLegado;

/**
 * Estado de conciliación de un certificado histórico, RECALCULADO con la misma prioridad con la que lo asignó la migración:
 * revisión del documento > variantes conflictivas > sin plantilla usable > duplicado idéntico no canónico > ok.
 * Se deriva del snapshot (que conserva todos los motivos), así que al quitar el bloqueo de plantilla se respeta cualquier otro
 * bloqueo más restrictivo y los duplicados idénticos recuperan su relación canónico/duplicado sin tocar sus grupos.
 */
final class EstadoDerivado
{
    /** @param array<string,mixed> $snapshot snapshot_legado ya decodificado */
    public static function para(array $snapshot, bool $plantillaUsable): string
    {
        $dup = $snapshot['duplicado'] ?? null;
        $oldId = $snapshot['migracion']['old_id'] ?? null;
        $conflictivo = is_array($dup) && ($dup['clasificacion'] ?? null) === 'conflictivo';
        $identicoNoCanonico = is_array($dup) && ($dup['clasificacion'] ?? null) === 'identico' && (int) $oldId !== (int) ($dup['canonico_old_id'] ?? 0);

        return match (true) {
            ($snapshot['documento_estado'] ?? 'valido') !== 'valido' => CertificadoLegado::CONCILIACION_REVISION_DOCUMENTO,
            $conflictivo => CertificadoLegado::CONCILIACION_PENDIENTE,
            ! $plantillaUsable => CertificadoLegado::CONCILIACION_PENDIENTE_PLANTILLA,
            $identicoNoCanonico => CertificadoLegado::CONCILIACION_DUPLICADO_CONSOLIDADO,
            default => CertificadoLegado::CONCILIACION_OK,
        };
    }
}
