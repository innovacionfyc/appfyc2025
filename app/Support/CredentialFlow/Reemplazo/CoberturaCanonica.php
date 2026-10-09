<?php

namespace App\Support\CredentialFlow\Reemplazo;

use App\Models\CredentialFlow\Conciliacion;
use App\Models\CredentialFlow\ConciliacionEvento;
use Illuminate\Support\Facades\DB;

/**
 * Cierra, de forma auditable, el caso de una VARIANTE de un certificado lógico cuando la raíz ya fue reemplazada (Fase 10B-2B-2C.1).
 *
 * Un caso secundario se cierra como `cubierto_por_reemplazo_canonico` SOLO tras REVALIDAR equivalencia estricta con el caso raíz resuelto:
 * mismo grupo, mismo evento, mismo documento (exacto y clave), mismo nombre exacto, misma plantilla histórica, mismo tipo de documento, misma categoría
 * de problema documental, mismo estado documental y mismos motivos del snapshot, certificado todavía vigente y sin enlace propio. Si cualquier cosa
 * difiere, el caso NO se cierra y queda abierto para revisión humana (sin ninguna corrección «heredada»).
 * La variante NO recibe `reemplazado_por_emision_id` (el UNIQUE y la política «solo la raíz lleva el enlace» se conservan): resuelve por la raíz.
 *
 * Es idempotente. Se invoca dentro de la transacción del reemplazo (con `skipLocked`: nunca espera un caso que otro proceso retiene, para no
 * crear un interbloqueo con el orden caso → evento) y, como barrido, cuando una variante recibe el rechazo «ya reemplazado lógicamente».
 */
final class CoberturaCanonica
{
    public const RESOLUCION = 'cubierto_por_reemplazo_canonico';

    public const ACCION = 'reemplazo_cubierto_por_canonico';

    /** @return list<int> ids de los casos secundarios cerrados en esta llamada */
    public function cubrir(int $raizCertId, ?int $actorId = null, bool $omitirBloqueados = true): array
    {
        return DB::transaction(function () use ($raizCertId, $actorId, $omitirBloqueados) {
            $l = CertificadoLogico::de($raizCertId);
            if (count($l['miembros']) < 2) {
                return [];
            }
            $raiz = DB::table('cf_certificados_legado')->where('id', $l['raiz'])->first();
            if ($raiz === null || $raiz->reemplazado_por_emision_id === null) {
                return [];
            }
            $casoRaiz = Conciliacion::query()->where('estado', Conciliacion::RESUELTO)->where('resolucion', ReemplazoHistorico::RESOLUCION)
                ->whereIn('id', DB::table('cf_conciliaciones_certificados')->where('certificado_legado_id', $raiz->id)->select('conciliacion_id'))->orderBy('id')->first();
            if ($casoRaiz === null) {
                return [];
            }

            $actor = $actorId ?? ($casoRaiz->resuelto_por === null ? null : (int) $casoRaiz->resuelto_por);
            $variantes = array_values(array_diff($l['miembros'], [$l['raiz']]));
            $candidatos = DB::table('cf_conciliaciones_certificados')->whereIn('certificado_legado_id', $variantes)->orderBy('conciliacion_id')->pluck('conciliacion_id')->unique()->map(fn ($i) => (int) $i);
            $cerrados = [];
            foreach ($candidatos as $id) {
                // `for update skip locked` (MySQL 8 / MariaDB 10.6+): un caso retenido por otra transacción se omite en lugar de esperarlo.
                $caso = Conciliacion::query()->whereKey($id)->lock($omitirBloqueados ? 'for update skip locked' : true)->first();
                if ($caso === null || $caso->estado !== Conciliacion::ABIERTO || ! $this->equivalente($caso, $casoRaiz, $raiz, $l)) {
                    continue;
                }
                $ahora = now();
                $caso->update(['estado' => Conciliacion::RESUELTO, 'resolucion' => self::RESOLUCION, 'resuelto_por' => $actor, 'resuelto_at' => $ahora]);
                ConciliacionEvento::create([
                    'conciliacion_id' => $caso->id, 'accion' => self::ACCION, 'estado_anterior' => Conciliacion::ABIERTO, 'estado_nuevo' => Conciliacion::RESUELTO,
                    'motivo' => 'El certificado lógico ya fue reemplazado desde su certificado principal.', 'actor_id' => $actor,
                    'evidencia' => [
                        'caso_raiz_id' => (int) $casoRaiz->id, 'certificado_raiz_id' => (int) $raiz->id, 'emision_id' => (int) $raiz->reemplazado_por_emision_id,
                        'certificados_cubiertos' => DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $caso->id)->orderBy('certificado_legado_id')->pluck('certificado_legado_id')->map(fn ($i) => (int) $i)->all(),
                        'razon' => 'equivalencia_estricta_con_el_caso_raiz', 'categoria' => (string) $caso->motivo_origen,
                    ],
                ]);
                $cerrados[] = (int) $caso->id;
            }

            return $cerrados;
        });
    }

    /**
     * Equivalencia estricta del caso secundario con el caso raíz resuelto. Cualquier diferencia → false (no se cierra).
     *
     * @param  array{grupo:?string,miembros:list<int>,raiz:int}  $l
     */
    private function equivalente(Conciliacion $caso, Conciliacion $casoRaiz, object $raiz, array $l): bool
    {
        if ($caso->tipo !== Conciliacion::TIPO_REVISION_DOCUMENTO || (string) $caso->motivo_origen !== (string) $casoRaiz->motivo_origen) {
            return false;
        }
        $ids = DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $caso->id)->pluck('certificado_legado_id')->map(fn ($i) => (int) $i)->all();
        // Todo certificado del caso debe ser una variante del MISMO grupo (ninguno ajeno, ninguno de la raíz).
        if ($ids === [] || array_diff($ids, array_diff($l['miembros'], [$l['raiz']])) !== []) {
            return false;
        }
        $base = $this->firma($raiz);
        foreach (DB::table('cf_certificados_legado')->whereIn('id', $ids)->get() as $c) {
            if ($c->estado !== 'vigente' || $c->revocado_at !== null || $c->reemplazado_por_emision_id !== null || $this->firma($c) !== $base) {
                return false;
            }
        }

        return true;
    }

    /** Todo lo que debe coincidir exactamente entre la raíz y la variante. */
    private function firma(object $c): string
    {
        $s = is_string($c->snapshot_legado) ? (json_decode($c->snapshot_legado, true) ?: []) : (array) $c->snapshot_legado;
        $motivos = array_map('strval', (array) ($s['motivos'] ?? []));
        sort($motivos);

        return hash('sha256', json_encode([
            $c->grupo_duplicado, (int) $c->evento_id, (string) $c->documento, (string) $c->documento_clave, (string) $c->nombre_completo,
            $c->plantilla_legado_id === null ? null : (int) $c->plantilla_legado_id, (string) $c->tipo_documento,
            (string) ($s['documento_estado'] ?? ''), $motivos,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
