<?php

namespace App\Support\CredentialFlow\Identidad;

use App\Models\CredentialFlow\Conciliacion;
use App\Models\CredentialFlow\DecisionIdentidad;
use Illuminate\Support\Facades\DB;

/**
 * Revalidación RÁPIDA del scope aprobado de una sesión (Fase 10B-3B-2): UNA consulta por PK sobre las decisiones que la sustentan (más su caso). No reconstruye el
 * caso ni carga certificados, y solo se ejecuta si la sesión usa decisiones.
 *
 * Qué garantiza (y por qué basta):
 *  - cada decisión EXISTE y sigue `vigente` (la revocación cierra la sesión en la siguiente petición);
 *  - conserva su `vigente_clave` (SHA-256 del contenido canónico: caso + tipo + grupos/correo) ⇒ es la MISMA decisión con el mismo contenido: revocar la 1 y
 *    crear la 2 con el mismo grupo NO sirve, los ids y las claves forman parte del contrato;
 *  - pertenece al documento de la sesión y su caso es de identidad, de ese documento y está en un estado compatible (abierto, soporte o resuelto por
 *    `identidad_aplicada`; nunca descartado ni resuelto por otra vía);
 *  - es una decisión que concede (misma persona / correo autorizado), no una terminal ni «personas distintas».
 *  - (10B-3C-3) si alguna decisión lleva una SEGUNDA APROBACIÓN (autorización masiva), esa aprobación sigue `aprobada` (aprobador presente y distinto del solicitante), el
 *    conjunto de aprobaciones es EXACTAMENTE el de la sesión y el interruptor masivo sigue encendido: apagarlo, o revocar la aprobación, mata la sesión masiva.
 * Como las decisiones son inmutables mientras están vigentes y 3A impide crear decisiones contradictorias con las vigentes, mientras estas propiedades se
 * mantengan el scope calculado al iniciar la sesión sigue siendo el que produciría el resolver. Cualquier otra cosa ⇒ la sesión se invalida ENTERA.
 */
final class RevalidacionScope
{
    /**
     * Ids de las segundas aprobaciones de estas decisiones (ordenados), o null si alguna no es válida o el interruptor masivo está apagado. `[]` = ninguna decisión
     * es una autorización masiva. Una consulta por PK de decisión.
     *
     * @param  list<int>  $ids
     * @return list<int>|null
     */
    public static function aprobaciones(string $claveDocumento, array $ids): ?array
    {
        $filas = self::cargar($claveDocumento, $ids);

        return $filas === null ? null : self::aprobacionesDe($filas);
    }

    /**
     * @param  array<int,object>  $filas  decisiones ya cargadas (con el LEFT JOIN de su segunda aprobación)
     * @return list<int>|null
     */
    private static function aprobacionesDe(array $filas): ?array
    {
        $lista = [];
        foreach ($filas as $f) {
            if ($f->ap_id === null) {
                continue;
            }
            if (! IdentidadFlags::masaHabilitada() || $f->ap_estado !== 'aprobada' || $f->ap_aprobada_por === null || $f->ap_aprobada_at === null || (int) $f->ap_aprobada_por === (int) $f->ap_solicitada_por) {
                return null;
            }
            $lista[] = (int) $f->ap_id;
        }
        sort($lista);

        return $lista;
    }

    /** @param list<int> $ids  @return list<string>|null las `vigente_clave` en el orden de `$ids`, o null si alguna decisión no es válida */
    public static function claves(string $claveDocumento, array $ids): ?array
    {
        $filas = self::cargar($claveDocumento, $ids);

        return $filas === null ? null : array_map(fn ($id) => (string) $filas[$id]->vigente_clave, $ids);
    }

    /**
     * @param  list<int>  $ids
     * @param  list<string>  $clavesEsperadas  en el mismo orden que `$ids`
     * @param  list<int>  $aprobacionesEsperadas  segundas aprobaciones de la sesión (vacío = no es una sesión masiva)
     */
    public static function vigente(string $claveDocumento, array $ids, array $clavesEsperadas, array $aprobacionesEsperadas = []): bool
    {
        if ($ids === [] || count($ids) !== count($clavesEsperadas)) {
            return false;
        }
        sort($aprobacionesEsperadas);
        $filas = self::cargar($claveDocumento, $ids);
        if ($filas === null || self::aprobacionesDe($filas) !== array_values($aprobacionesEsperadas)) {
            return false;
        }
        foreach ($ids as $i => $id) {
            if (! hash_equals((string) $clavesEsperadas[$i], (string) $filas[$id]->vigente_clave)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<int> $ids  @return array<int,object>|null */
    private static function cargar(string $claveDocumento, array $ids): ?array
    {
        if ($ids === [] || count($ids) !== count(array_unique($ids))) {
            return null;
        }
        $docHash = EvidenciaIdentidad::hashDocumento($claveDocumento);
        // UNA sola consulta: la decisión, su caso y (LEFT JOIN, única por decisión) su segunda aprobación si es una autorización masiva (10B-3C-3).
        $filas = DB::table('cf_decisiones_identidad as d')->join('cf_conciliaciones as c', 'c.id', '=', 'd.conciliacion_id')
            ->leftJoin('cf_decisiones_identidad_aprobaciones as a', 'a.decision_id', '=', 'd.id')->whereIn('d.id', $ids)
            ->get(['d.id', 'd.estado', 'd.tipo', 'd.vigente_clave', 'd.documento_hash', 'c.tipo as caso_tipo', 'c.estado as caso_estado', 'c.resolucion as caso_resolucion', 'c.referencia_clave as caso_documento',
                'a.id as ap_id', 'a.estado as ap_estado', 'a.solicitada_por as ap_solicitada_por', 'a.aprobada_por as ap_aprobada_por', 'a.aprobada_at as ap_aprobada_at'])
            ->keyBy('id');
        if ($filas->count() !== count($ids)) {
            return null;
        }
        foreach ($ids as $id) {
            $f = $filas[$id] ?? null;
            $casoOk = $f !== null && $f->caso_tipo === Conciliacion::TIPO_IDENTIDAD_AMBIGUA && $f->caso_documento === $docHash
                && (in_array($f->caso_estado, [Conciliacion::ABIERTO, Conciliacion::REQUIERE_SOPORTE], true) || ($f->caso_estado === Conciliacion::RESUELTO && $f->caso_resolucion === 'identidad_aplicada'));
            if (! $casoOk || $f->estado !== DecisionIdentidad::VIGENTE || $f->vigente_clave === null || $f->documento_hash !== $docHash
                || ! in_array($f->tipo, [DecisionIdentidad::MISMA_PERSONA, DecisionIdentidad::CORREO_AUTORIZADO], true)) {
                return null;
            }
        }

        return $filas->all();
    }
}
