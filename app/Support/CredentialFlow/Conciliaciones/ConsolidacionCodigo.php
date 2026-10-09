<?php

namespace App\Support\CredentialFlow\Conciliaciones;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Conciliacion;
use App\Models\CredentialFlow\ConciliacionEvento;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Legado\CodigoHistorico;
use App\Support\CredentialFlow\Legado\CodigoHistoricoException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Consolidación de los casos «DIF_VERIF» (Fase 10B-2A): variantes del MISMO par (evento + documento) que solo difieren en que una tiene el
 * código histórico y la otra NULL. Con la semántica real del sistema viejo (el código se asignaba en la primera descarga a todas las
 * filas que existían entonces) la fila NULL es un duplicado agregado después: NO es una diferencia de certificado.
 *
 * Regla (revalidada BAJO LOCK antes de resolver; cualquier incumplimiento detiene ese caso y lo deja para revisión humana):
 *  1. caso `conflicto_variantes` abierto y todas las variantes siguen en `pendiente_conciliacion` (el grupo no cambió);
 *  2. patrón DIF_VERIF puro (ClasificadorVariantes): ≥ 2 variantes, un único código distinto y al menos una fila sin código;
 *  3. mismo par lógico y NINGUNA otra diferencia real: nombre, tipo y documento impresos, estado del documento, plantilla y conjunto de
 *     correos válidos son idénticos (comparación exacta, sin similitud aproximada; el correo nunca prueba identidad);
 *  4. el par tiene exactamente UN código legado efectivo y ningún código asignado por Credential Flow;
 *  5. ninguna variante está revocada ni reemplazada; un PDF congelado solo se admite en la variante canónica;
 *  6. CANÓNICO (determinista): la única variante con `codigo_legado` histórico, y debe tener descarga histórica; si otra variante tiene
 *     descargas históricas (imposible en el legado) la evidencia es incompatible y se detiene.
 *
 * Resultado: canónico → `ok`, resto → `duplicado_consolidado` (recalculado con EstadoDerivado: si hubiera otro bloqueo más restrictivo se
 * respeta). NO se copia el código a `codigo_legado`, no se crea ningún código nuevo, no se borra ninguna fila, no se mueven descargas y no
 * se tocan snapshots, correos ni el mapa de migración original: la relación canónico/duplicado se expresa con UNA fila ADITIVA en
 * `cf_migraciones_map` (origen_tabla = 'conciliacion') que ya leen ElegibilidadLegado y el portal, y con los roles del pivote del caso.
 */
final class ConsolidacionCodigo
{
    public const ACCION = 'conflicto_codigo_consolidado';

    public const ACCION_REVERTIDA = 'conflicto_consolidacion_revertida';

    public const RESOLUCION = 'codigo_consolidado';

    public const ORIGEN_MAPA = 'conciliacion';

    public const EXPLICACION = 'Variantes históricas consolidadas: la diferencia correspondía únicamente a la asignación tardía del código de verificación.';

    /**
     * Revisa el caso SIN escribir. Con `$bloquear` toma `lockForUpdate` de las filas (usar dentro de una transacción).
     *
     * @return array{aplicable:bool,bloqueos:list<array{codigo:string,mensaje:string}>,canonico_id:?int,otras_ids:list<int>,codigo:?string,descargas:array<int,int>,filas:Collection<int,object>}
     */
    public function analizar(Conciliacion $caso, bool $bloquear = false): array
    {
        $r = ['aplicable' => false, 'bloqueos' => [], 'canonico_id' => null, 'otras_ids' => [], 'codigo' => null, 'descargas' => [], 'filas' => collect()];
        $bloq = function (string $codigo, string $mensaje) use (&$r): void {
            $r['bloqueos'][] = ['codigo' => $codigo, 'mensaje' => $mensaje];
        };

        if ($caso->tipo !== Conciliacion::TIPO_CONFLICTO_VARIANTES) {
            $bloq(ResolucionNoPermitida::TIPO_NO_ADMITIDO, 'Esta acción no corresponde a este tipo de caso.');

            return $r;
        }

        $ids = DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $caso->id)->orderBy('certificado_legado_id')->pluck('certificado_legado_id')->map(fn ($i) => (int) $i)->all();
        $consulta = DB::table('cf_certificados_legado')->whereIn('id', $ids)->orderBy('id');
        $filas = ($bloquear ? $consulta->lockForUpdate() : $consulta)->get(['id', 'evento_id', 'documento_clave', 'documento', 'tipo_documento', 'nombre_completo', 'codigo_legado', 'estado', 'conciliacion_estado', 'grupo_duplicado',
            'plantilla_legado_id', 'pdf_archivo', 'reemplazado_por_emision_id', 'revocado_at', 'corrida_id', 'snapshot_legado']);
        $r['filas'] = $filas;

        if ($filas->count() < 2 || $filas->contains(fn ($f) => $f->conciliacion_estado !== CertificadoLegado::CONCILIACION_PENDIENTE)
            || ($filas->first()->grupo_duplicado !== null && DB::table('cf_certificados_legado')->where('grupo_duplicado', $filas->first()->grupo_duplicado)->count() !== $filas->count())) {
            $bloq(ResolucionNoPermitida::CERTIFICADOS_CAMBIARON, 'Los certificados de este caso cambiaron desde que se detectó. Actualiza la pantalla y revisa el caso.');

            return $r;
        }

        // 2. Patrón DIF_VERIF puro.
        $snap = json_decode((string) $filas->first()->snapshot_legado, true) ?: [];
        if (! ClasificadorVariantes::soloDifiereEnCodigoNulo($filas, $snap)) {
            $bloq(ResolucionNoPermitida::NO_ES_DIFERENCIA_DE_CODIGO, 'Este caso no es una diferencia de solo código: requiere revisión humana.');

            return $r;
        }
        $r['aplicable'] = true;

        // 3. Mismo par y ninguna otra diferencia real (exacto).
        $correos = DB::table('cf_correos')->whereIn('certificado_legado_id', $ids)->where('estado', 'valido')->get(['certificado_legado_id', 'correo_normalizado'])->groupBy('certificado_legado_id')
            ->map(fn ($g) => $g->pluck('correo_normalizado')->sort()->values()->implode('|'));
        $igual = fn (callable $f) => $filas->map($f)->unique(null, true)->count() === 1;
        $estadosDoc = fn ($f) => (json_decode((string) $f->snapshot_legado, true) ?: [])['documento_estado'] ?? 'valido';
        if (! $igual(fn ($f) => $f->evento_id.'|'.$f->documento_clave) || ! $igual(fn ($f) => (string) $f->nombre_completo) || ! $igual(fn ($f) => (string) $f->tipo_documento) || ! $igual(fn ($f) => (string) $f->documento)
            || ! $igual($estadosDoc) || ! $igual(fn ($f) => (string) $f->plantilla_legado_id) || ! $igual(fn ($f) => (string) ($correos[$f->id] ?? ''))) {
            $bloq(ResolucionNoPermitida::HAY_OTRAS_DIFERENCIAS, 'Además del código hay otra diferencia entre las variantes: este caso requiere revisión humana.');
        }

        // 4. Exactamente un código legado efectivo y ninguno asignado por Credential Flow.
        $primera = $filas->first();
        try {
            $efectivo = (new CodigoHistorico)->resolver($primera);
        } catch (CodigoHistoricoException) {
            $efectivo = null;
        }
        $registrado = DB::table('cf_codigos_historicos')->where('par_hash', CodigoHistorico::parHash((int) $primera->evento_id, (string) $primera->documento_clave))->exists();
        if ($efectivo === null || $efectivo['origen'] !== CodigoHistorico::ORIGEN_LEGADO || $registrado) {
            $bloq(ResolucionNoPermitida::CODIGO_NO_UNICO, 'El par no tiene un único código legado efectivo: este caso requiere revisión humana.');
        }
        $r['codigo'] = $efectivo['codigo'] ?? null;

        // 5. Revocado / reemplazado.
        if ($filas->contains(fn ($f) => $f->estado !== CertificadoLegado::ESTADO_VIGENTE || $f->reemplazado_por_emision_id !== null || $f->revocado_at !== null)) {
            $bloq(ResolucionNoPermitida::CERTIFICADOS_CAMBIARON, 'Alguna variante ya fue revocada o reemplazada: no se puede consolidar.');
        }

        // 6. Canónico determinista + evidencia de descarga histórica.
        $canonico = $filas->first(fn ($f) => $f->codigo_legado !== null && $f->codigo_legado !== '');
        $r['canonico_id'] = $canonico === null ? null : (int) $canonico->id;
        $r['otras_ids'] = $filas->reject(fn ($f) => $canonico !== null && (int) $f->id === (int) $canonico->id)->pluck('id')->map(fn ($i) => (int) $i)->values()->all();
        $r['descargas'] = DB::table('cf_descargas')->whereIn('certificado_legado_id', $ids)->where('origen', 'legado_importado')->groupBy('certificado_legado_id')->selectRaw('certificado_legado_id, COUNT(1) n')->pluck('n', 'certificado_legado_id')->map(fn ($n) => (int) $n)->all();
        if ($canonico === null || ($r['descargas'][(int) $canonico->id] ?? 0) < 1 || collect($r['otras_ids'])->contains(fn ($id) => ($r['descargas'][$id] ?? 0) > 0)) {
            $bloq(ResolucionNoPermitida::EVIDENCIA_DE_DESCARGA, 'La evidencia de descarga histórica no confirma cuál es la variante canónica: este caso requiere revisión humana.');
        }

        // PDF congelado: solo se admite en la canónica (su significado no cambia); en otra variante quedaría ambiguo.
        if ($filas->contains(fn ($f) => $f->pdf_archivo !== null && ($canonico === null || (int) $f->id !== (int) $canonico->id))) {
            $bloq(ResolucionNoPermitida::PDF_CONGELADO, ResolucionPlantillas::MSG_PDF_CONGELADO);
        }

        return $r;
    }

    /** @return array{caso_id:int,canonico_id:int,consolidadas:int,codigo:string,estados:array<int,string>} */
    public function consolidar(int $casoId, int $actorId, string $motivo): array
    {
        $motivo = $this->motivoValido($motivo);

        return DB::transaction(function () use ($casoId, $actorId, $motivo) {
            $caso = Conciliacion::query()->whereKey($casoId)->lockForUpdate()->firstOrFail();
            if ($caso->tipo !== Conciliacion::TIPO_CONFLICTO_VARIANTES) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::TIPO_NO_ADMITIDO, 'Esta acción no corresponde a este tipo de caso.');
            }
            if ($caso->estado !== Conciliacion::ABIERTO) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::CASO_YA_RESUELTO, 'Este caso ya fue resuelto.');
            }

            $a = $this->analizar($caso, true);
            if ($a['bloqueos'] !== []) {
                throw new ResolucionNoPermitida($a['bloqueos'][0]['codigo'], $a['bloqueos'][0]['mensaje']);
            }
            /** @var Collection<int,object> $filas */
            $filas = $a['filas'];
            $canonico = $filas->firstWhere('id', $a['canonico_id']);
            $corrida = $canonico->corrida_id ?? throw new ResolucionNoPermitida(ResolucionNoPermitida::CERTIFICADOS_CAMBIARON, 'El certificado canónico no tiene una corrida de migración asociada.');
            $oldCanonico = (json_decode((string) $canonico->snapshot_legado, true) ?: [])['migracion']['old_id'] ?? null;

            // Estado derivado con la prioridad de la migración, tratando al grupo como duplicado idéntico con ese canónico (en memoria: el
            // snapshot de la base NO se toca). Un bloqueo más restrictivo (documento, plantilla) se respeta.
            $anteriores = [];
            $nuevos = [];
            foreach ($filas as $f) {
                $s = json_decode((string) $f->snapshot_legado, true) ?: [];
                $s['duplicado'] = array_merge($s['duplicado'] ?? [], ['clasificacion' => 'identico', 'canonico_old_id' => $oldCanonico]);
                $anteriores[(int) $f->id] = $f->conciliacion_estado;
                $nuevos[(int) $f->id] = EstadoDerivado::para($s, $f->plantilla_legado_id !== null);
            }

            $ahora = now();
            foreach ($nuevos as $id => $estado) {
                DB::table('cf_certificados_legado')->where('id', $id)->update(['conciliacion_estado' => $estado, 'update_by' => $actorId, 'updated_at' => $ahora]);
            }

            // Relación canónico/duplicado: UNA fila aditiva (no se reescribe el mapa de origen, que conserva «variante_conflictiva»).
            $mapaId = DB::table('cf_migraciones_map')->insertGetId([
                'corrida_id' => $corrida, 'origen_tabla' => self::ORIGEN_MAPA, 'origen_id' => (string) $caso->id, 'destino_tabla' => 'cf_certificados_legado', 'destino_id' => $canonico->id,
                'relacion' => 'canonico', 'detalle' => json_encode(['grupo' => $canonico->grupo_duplicado, 'consolidadas' => $a['otras_ids'], 'regla' => self::RESOLUCION]), 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);

            $roles = DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $caso->id)->pluck('rol', 'certificado_legado_id')->all();
            DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $caso->id)->where('certificado_legado_id', $canonico->id)->update(['rol' => 'canonico', 'updated_at' => $ahora]);
            DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $caso->id)->whereIn('certificado_legado_id', $a['otras_ids'])->update(['rol' => 'consolidada', 'updated_at' => $ahora]);

            $caso->update(['estado' => Conciliacion::RESUELTO, 'resolucion' => self::RESOLUCION, 'resuelto_por' => $actorId, 'resuelto_at' => $ahora]);
            ConciliacionEvento::create(['conciliacion_id' => $caso->id, 'accion' => self::ACCION, 'estado_anterior' => Conciliacion::ABIERTO, 'estado_nuevo' => Conciliacion::RESUELTO, 'motivo' => $motivo, 'actor_id' => $actorId, 'evidencia' => [
                'regla' => self::EXPLICACION, 'codigo' => $a['codigo'], 'canonico_id' => (int) $canonico->id, 'consolidadas_ids' => $a['otras_ids'], 'descargas_historicas' => $a['descargas'],
                'estados_anteriores' => $anteriores, 'estados_nuevos' => $nuevos, 'roles_anteriores' => $roles, 'mapa_id' => $mapaId,
            ]]);
            $this->movimiento($actorId, self::ACCION, $caso->id, count($filas));

            return ['caso_id' => (int) $caso->id, 'canonico_id' => (int) $canonico->id, 'consolidadas' => count($a['otras_ids']), 'codigo' => (string) $a['codigo'], 'estados' => $nuevos];
        });
    }

    /**
     * Deshace la consolidación (servicio técnico, sin botón): solo mientras ningún PDF se haya congelado, no haya una descarga nueva de
     * Credential Flow, ninguna variante esté reemplazada o revocada y no haya decisiones posteriores sobre el caso. `codigo_legado`,
     * descargas, correos y snapshots no se tocan.
     *
     * @return array{caso_id:int,restauradas:int}
     */
    public function revertir(int $casoId, int $actorId, string $motivo): array
    {
        $motivo = $this->motivoValido($motivo);

        return DB::transaction(function () use ($casoId, $actorId, $motivo) {
            $caso = Conciliacion::query()->whereKey($casoId)->lockForUpdate()->firstOrFail();
            $ultimo = DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso->id)->orderByDesc('id')->first();
            if ($caso->estado !== Conciliacion::RESUELTO || $ultimo === null || $ultimo->accion !== self::ACCION) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::NO_REVERSIBLE, 'Este caso no tiene una consolidación que se pueda deshacer (o ya hay decisiones posteriores).');
            }
            $evi = json_decode((string) $ultimo->evidencia, true) ?: [];
            $anteriores = array_map('strval', $evi['estados_anteriores'] ?? []);
            $nuevos = array_map('strval', $evi['estados_nuevos'] ?? []);
            $ids = array_map('intval', array_keys($anteriores));

            $filas = DB::table('cf_certificados_legado')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id', 'estado', 'conciliacion_estado', 'pdf_archivo', 'reemplazado_por_emision_id', 'revocado_at']);
            if ($filas->contains(fn ($f) => $f->pdf_archivo !== null)) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::PDF_CONGELADO, ResolucionPlantillas::MSG_PDF_CONGELADO);
            }
            if ($filas->count() !== count($ids) || $filas->contains(fn ($f) => $f->estado !== CertificadoLegado::ESTADO_VIGENTE || $f->reemplazado_por_emision_id !== null || $f->revocado_at !== null || $f->conciliacion_estado !== ($nuevos[$f->id] ?? null))) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::NO_REVERSIBLE, 'Los certificados cambiaron después de la consolidación: no se puede deshacer automáticamente.');
            }
            if (DB::table('cf_descargas')->whereIn('certificado_legado_id', $ids)->where('origen', 'credential_flow')->exists()) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::NO_REVERSIBLE, 'Ya hay descargas nuevas de Credential Flow que dependen de esta consolidación: no se puede deshacer.');
            }

            $ahora = now();
            foreach ($anteriores as $id => $estado) {
                DB::table('cf_certificados_legado')->where('id', (int) $id)->update(['conciliacion_estado' => $estado, 'update_by' => $actorId, 'updated_at' => $ahora]);
            }
            DB::table('cf_migraciones_map')->where('id', (int) ($evi['mapa_id'] ?? 0))->where('origen_tabla', self::ORIGEN_MAPA)->where('origen_id', (string) $caso->id)->delete();
            foreach (($evi['roles_anteriores'] ?? []) as $id => $rol) {
                DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $caso->id)->where('certificado_legado_id', (int) $id)->update(['rol' => $rol, 'updated_at' => $ahora]);
            }
            $caso->update(['estado' => Conciliacion::ABIERTO, 'resolucion' => null, 'resuelto_por' => null, 'resuelto_at' => null]);
            ConciliacionEvento::create(['conciliacion_id' => $caso->id, 'accion' => self::ACCION_REVERTIDA, 'estado_anterior' => Conciliacion::RESUELTO, 'estado_nuevo' => Conciliacion::ABIERTO, 'motivo' => $motivo, 'actor_id' => $actorId,
                'evidencia' => ['revierte' => self::ACCION, 'canonico_id' => $evi['canonico_id'] ?? null, 'restauradas' => count($ids)]]);
            $this->movimiento($actorId, self::ACCION_REVERTIDA, $caso->id, count($ids));

            return ['caso_id' => (int) $caso->id, 'restauradas' => count($ids)];
        });
    }

    private function motivoValido(string $motivo): string
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < ResolucionPlantillas::MOTIVO_MIN || mb_strlen($motivo) > ResolucionPlantillas::MOTIVO_MAX) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::TIPO_NO_ADMITIDO, 'El motivo debe tener entre '.ResolucionPlantillas::MOTIVO_MIN.' y '.ResolucionPlantillas::MOTIVO_MAX.' caracteres.');
        }

        return $motivo;
    }

    private function movimiento(int $actorId, string $accion, int $casoId, int $certificados): void
    {
        // Sin IP ni agente de usuario: solo ids y conteos, nada personal.
        Movimiento::create(['user_id' => $actorId, 'tipo' => 'conciliacion', 'modulo' => 'credential_flow', 'descripcion' => "Conciliación #{$casoId}: {$accion} ({$certificados} certificados)", 'metadata' => ['conciliacion_id' => $casoId, 'accion' => $accion, 'certificados' => $certificados]]);
    }
}
