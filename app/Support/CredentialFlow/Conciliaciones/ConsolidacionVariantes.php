<?php

namespace App\Support\CredentialFlow\Conciliaciones;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Conciliacion;
use App\Models\CredentialFlow\ConciliacionEvento;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Legado\CodigoHistorico;
use App\Support\CredentialFlow\Legado\CodigoHistoricoException;
use App\Support\CredentialFlow\Portal\NombreConservador;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Consolidación de variantes del MISMO certificado histórico (Fase 10B-2B-1), en dos modos explícitos y separados:
 *
 *  - `correo`            (DIF_CORREO)  las variantes solo difieren en el correo registrado. El correo no se imprime ni determina identidad.
 *                        Canónico OBJETIVO: la variante con código legado y descarga histórica; si no hay, el menor `old_id` (la misma regla
 *                        que la migración usa para los duplicados idénticos).
 *  - `nombre_cosmetico`  (DIF_NOMBRE)  los nombres difieren pero desaparecen con la normalización CONSERVADORA (sin tildes, mayúsculas ni
 *                        signos; nunca similitud aproximada). El administrador ELIGE explícitamente la variante canónica (define la grafía
 *                        impresa); no se modifica el nombre de ninguna fila.
 *
 * Revalidado BAJO LOCK: caso abierto y grupo sin cambios; mismo par (evento + documento) y ninguna otra diferencia material (nombre exacto o
 * normalizado según el modo, tipo y documento impresos, estado del documento, plantilla y —en el modo nombre— el conjunto de correos); a lo sumo un
 * código efectivo y sin conflicto; ninguna variante revocada ni reemplazada; un PDF congelado solo se admite en la canónica.
 *
 * Resultado: canónica → `ok` y resto → `duplicado_consolidado` (EstadoDerivado: un bloqueo más restrictivo se respeta). NO se modifica ninguna
 * fila histórica, `cf_correos`, descargas, snapshots ni el mapa de origen (se añade UNA fila aditiva `origen_tabla = 'conciliacion'`), no se crea
 * ningún código y no se mueve nada. Reversible con `revertir()` (servicio técnico, sin botón).
 */
final class ConsolidacionVariantes
{
    public const MODO_CORREO = 'correo';

    public const MODO_NOMBRE = 'nombre_cosmetico';

    public const ACCIONES = [self::MODO_CORREO => 'conflicto_correo_consolidado', self::MODO_NOMBRE => 'conflicto_nombre_cosmetico_consolidado'];

    public const RESOLUCIONES = [self::MODO_CORREO => 'correo_consolidado', self::MODO_NOMBRE => 'nombre_cosmetico_consolidado'];

    public const ACCION_REVERTIDA = 'conflicto_consolidacion_revertida';

    public const EXPLICACIONES = [
        self::MODO_CORREO => 'Las variantes corresponden al mismo certificado histórico y difieren únicamente en el correo registrado.',
        self::MODO_NOMBRE => 'Las variantes corresponden al mismo certificado histórico y sus nombres solo difieren en tildes, mayúsculas o signos.',
    ];

    /**
     * Revisa el caso SIN escribir (con `$bloquear`, bajo `lockForUpdate`).
     *
     * @return array{modo:?string,aplicable:bool,nombre_real:bool,bloqueos:list<array{codigo:string,mensaje:string}>,canonico_id:?int,otras_ids:list<int>,codigo:?string,descargas:array<int,int>,filas:Collection<int,object>}
     */
    public function analizar(Conciliacion $caso, bool $bloquear = false, ?int $canonicoElegido = null): array
    {
        $r = ['modo' => null, 'aplicable' => false, 'nombre_real' => false, 'bloqueos' => [], 'canonico_id' => null, 'otras_ids' => [], 'codigo' => null, 'descargas' => [], 'filas' => collect()];
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

        $snap = json_decode((string) $filas->first()->snapshot_legado, true) ?: [];
        $etiquetas = DetectorConciliaciones::lista($snap['duplicado']['etiquetas'] ?? null);
        $modo = match ($etiquetas) {
            ['DIF_CORREO'] => self::MODO_CORREO,
            ['DIF_NOMBRE'] => self::MODO_NOMBRE,
            default => null,
        };
        if ($modo === null) {
            $bloq(ResolucionNoPermitida::NO_ES_DIFERENCIA_DE_CODIGO, 'Este caso no es una diferencia de solo correo ni de solo variación de nombre: requiere revisión humana.');

            return $r;
        }
        $r['modo'] = $modo;

        // Nombre: «realmente distinto» (no cosmético) → nunca se consolida (va a soporte / 10B-3).
        $normalizados = $filas->map(fn ($f) => NombreConservador::normalizar((string) $f->nombre_completo))->unique(null, true);
        if ($modo === self::MODO_NOMBRE && $normalizados->count() > 1) {
            $r['nombre_real'] = true;
            $bloq(ResolucionNoPermitida::NOMBRE_REALMENTE_DISTINTO, 'Los nombres son realmente distintos: no se puede consolidar. Requiere soporte.');

            return $r;
        }
        $r['aplicable'] = true;

        // Mismo par y ninguna otra diferencia material (comparación exacta; el modo nombre admite solo la diferencia de grafía).
        $correos = DB::table('cf_correos')->whereIn('certificado_legado_id', $ids)->where('estado', 'valido')->get(['certificado_legado_id', 'correo_normalizado'])->groupBy('certificado_legado_id')
            ->map(fn ($g) => $g->pluck('correo_normalizado')->sort()->values()->implode('|'));
        $igual = fn (callable $f) => $filas->map($f)->unique(null, true)->count() === 1;
        $estadosDoc = fn ($f) => (json_decode((string) $f->snapshot_legado, true) ?: [])['documento_estado'] ?? 'valido';
        $otras = ! $igual(fn ($f) => $f->evento_id.'|'.$f->documento_clave) || ! $igual(fn ($f) => (string) $f->tipo_documento) || ! $igual(fn ($f) => (string) $f->documento) || ! $igual($estadosDoc) || ! $igual(fn ($f) => (string) $f->plantilla_legado_id)
            || ($modo === self::MODO_CORREO && ! $igual(fn ($f) => (string) $f->nombre_completo))
            || ($modo === self::MODO_NOMBRE && ! $igual(fn ($f) => (string) ($correos[$f->id] ?? '')));
        if ($otras) {
            $bloq(ResolucionNoPermitida::HAY_OTRAS_DIFERENCIAS, 'Además de lo esperado hay otra diferencia entre las variantes: este caso requiere revisión humana.');
        }

        // Código: a lo sumo uno efectivo (legado o de Credential Flow) y sin conflicto.
        $primera = $filas->first();
        try {
            $efectivo = (new CodigoHistorico)->resolver($primera);
        } catch (CodigoHistoricoException) {
            $efectivo = null;
            $bloq(ResolucionNoPermitida::CODIGO_NO_UNICO, 'El par tiene códigos en conflicto: este caso requiere revisión humana.');
        }
        $r['codigo'] = $efectivo['codigo'] ?? null;

        if ($filas->contains(fn ($f) => $f->estado !== CertificadoLegado::ESTADO_VIGENTE || $f->reemplazado_por_emision_id !== null || $f->revocado_at !== null)) {
            $bloq(ResolucionNoPermitida::CERTIFICADOS_CAMBIARON, 'Alguna variante ya fue revocada o reemplazada: no se puede consolidar.');
        }

        // Canónico.
        $r['descargas'] = DB::table('cf_descargas')->whereIn('certificado_legado_id', $ids)->where('origen', 'legado_importado')->groupBy('certificado_legado_id')->selectRaw('certificado_legado_id, COUNT(1) n')->pluck('n', 'certificado_legado_id')->map(fn ($n) => (int) $n)->all();
        $oldId = fn ($f) => (int) ((json_decode((string) $f->snapshot_legado, true) ?: [])['migracion']['old_id'] ?? PHP_INT_MAX);
        $canonico = null;
        if ($modo === self::MODO_CORREO) {
            // Evidencia incompatible: una variante SIN código con descargas históricas (el legado nunca descargó un certificado sin código).
            if ($filas->contains(fn ($f) => ($f->codigo_legado === null || $f->codigo_legado === '') && ($r['descargas'][(int) $f->id] ?? 0) > 0)) {
                $bloq(ResolucionNoPermitida::EVIDENCIA_DE_DESCARGA, 'Una variante sin código tiene descargas históricas: la evidencia es incompatible y requiere revisión humana.');
            }
            $conEvidencia = $filas->filter(fn ($f) => $f->codigo_legado !== null && $f->codigo_legado !== '' && ($r['descargas'][(int) $f->id] ?? 0) > 0);
            $canonico = ($conEvidencia->isNotEmpty() ? $conEvidencia : $filas)->sortBy($oldId)->first();
        } else {
            if ($canonicoElegido !== null) {
                $canonico = $filas->firstWhere('id', $canonicoElegido);
                if ($canonico === null) {
                    $bloq(ResolucionNoPermitida::CANONICO_INVALIDO, 'La variante elegida no pertenece a este caso.');
                }
            }
        }
        $r['canonico_id'] = $canonico === null ? null : (int) $canonico->id;
        $r['otras_ids'] = $canonico === null ? [] : $filas->reject(fn ($f) => (int) $f->id === (int) $canonico->id)->pluck('id')->map(fn ($i) => (int) $i)->values()->all();

        // PDF congelado: solo en la canónica (en otra variante su significado quedaría ambiguo).
        if ($filas->contains(fn ($f) => $f->pdf_archivo !== null && ($canonico === null || (int) $f->id !== (int) $canonico->id))) {
            $bloq(ResolucionNoPermitida::PDF_CONGELADO, ResolucionPlantillas::MSG_PDF_CONGELADO);
        }

        return $r;
    }

    /** @return array{caso_id:int,modo:string,canonico_id:int,consolidadas:int,estados:array<int,string>} */
    public function consolidar(int $casoId, int $actorId, string $motivo, string $modo, ?int $canonicoElegido = null): array
    {
        $motivo = $this->motivoValido($motivo);

        return DB::transaction(function () use ($casoId, $actorId, $motivo, $modo, $canonicoElegido) {
            $caso = Conciliacion::query()->whereKey($casoId)->lockForUpdate()->firstOrFail();
            if ($caso->tipo !== Conciliacion::TIPO_CONFLICTO_VARIANTES) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::TIPO_NO_ADMITIDO, 'Esta acción no corresponde a este tipo de caso.');
            }
            if ($caso->estado !== Conciliacion::ABIERTO) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::CASO_YA_RESUELTO, 'Este caso ya fue resuelto.');
            }
            $a = $this->analizar($caso, true, $canonicoElegido);
            if ($a['modo'] !== $modo && $a['bloqueos'] === []) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::TIPO_NO_ADMITIDO, 'Esta acción no corresponde a este caso.');
            }
            if ($a['bloqueos'] !== []) {
                throw new ResolucionNoPermitida($a['bloqueos'][0]['codigo'], $a['bloqueos'][0]['mensaje']);
            }
            if ($modo === self::MODO_NOMBRE && $canonicoElegido === null) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::CANONICO_INVALIDO, 'Elige cuál variante queda como canónica.');
            }

            /** @var Collection<int,object> $filas */
            $filas = $a['filas'];
            $canonico = $filas->firstWhere('id', $a['canonico_id']);
            $corrida = $canonico->corrida_id ?? throw new ResolucionNoPermitida(ResolucionNoPermitida::CERTIFICADOS_CAMBIARON, 'El certificado canónico no tiene una corrida de migración asociada.');
            $oldCanonico = (json_decode((string) $canonico->snapshot_legado, true) ?: [])['migracion']['old_id'] ?? null;

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
            $mapaId = DB::table('cf_migraciones_map')->insertGetId([
                'corrida_id' => $corrida, 'origen_tabla' => ConsolidacionCodigo::ORIGEN_MAPA, 'origen_id' => (string) $caso->id, 'destino_tabla' => 'cf_certificados_legado', 'destino_id' => $canonico->id,
                'relacion' => 'canonico', 'detalle' => json_encode(['grupo' => $canonico->grupo_duplicado, 'consolidadas' => $a['otras_ids'], 'regla' => self::RESOLUCIONES[$modo]]), 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
            $roles = DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $caso->id)->pluck('rol', 'certificado_legado_id')->all();
            DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $caso->id)->where('certificado_legado_id', $canonico->id)->update(['rol' => 'canonico', 'updated_at' => $ahora]);
            DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $caso->id)->whereIn('certificado_legado_id', $a['otras_ids'])->update(['rol' => 'consolidada', 'updated_at' => $ahora]);

            $caso->update(['estado' => Conciliacion::RESUELTO, 'resolucion' => self::RESOLUCIONES[$modo], 'resuelto_por' => $actorId, 'resuelto_at' => $ahora]);
            ConciliacionEvento::create(['conciliacion_id' => $caso->id, 'accion' => self::ACCIONES[$modo], 'estado_anterior' => Conciliacion::ABIERTO, 'estado_nuevo' => Conciliacion::RESUELTO, 'motivo' => $motivo, 'actor_id' => $actorId, 'evidencia' => [
                'regla' => self::EXPLICACIONES[$modo], 'canonico_id' => (int) $canonico->id, 'canonico_elegido_por_admin' => $modo === self::MODO_NOMBRE, 'consolidadas_ids' => $a['otras_ids'], 'codigo_existente' => $a['codigo'] !== null,
                'descargas_historicas' => $a['descargas'], 'estados_anteriores' => $anteriores, 'estados_nuevos' => $nuevos, 'roles_anteriores' => $roles, 'mapa_id' => $mapaId,
            ]]);
            $this->movimiento($actorId, self::ACCIONES[$modo], $caso->id, count($filas));

            return ['caso_id' => (int) $caso->id, 'modo' => $modo, 'canonico_id' => (int) $canonico->id, 'consolidadas' => count($a['otras_ids']), 'estados' => $nuevos];
        });
    }

    /**
     * Deshace la consolidación (servicio técnico, sin botón): solo si es el ÚLTIMO evento del caso, sin PDF congelado, sin descarga nueva de
     * Credential Flow, sin revocación ni reemplazo y con los estados tal como los dejó la consolidación.
     *
     * @return array{caso_id:int,restauradas:int}
     */
    public function revertir(int $casoId, int $actorId, string $motivo): array
    {
        $motivo = $this->motivoValido($motivo);

        return DB::transaction(function () use ($casoId, $actorId, $motivo) {
            $caso = Conciliacion::query()->whereKey($casoId)->lockForUpdate()->firstOrFail();
            $ultimo = DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso->id)->orderByDesc('id')->first();
            if ($caso->estado !== Conciliacion::RESUELTO || $ultimo === null || ! in_array($ultimo->accion, self::ACCIONES, true)) {
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
            DB::table('cf_migraciones_map')->where('id', (int) ($evi['mapa_id'] ?? 0))->where('origen_tabla', ConsolidacionCodigo::ORIGEN_MAPA)->where('origen_id', (string) $caso->id)->delete();
            foreach (($evi['roles_anteriores'] ?? []) as $id => $rol) {
                DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $caso->id)->where('certificado_legado_id', (int) $id)->update(['rol' => $rol, 'updated_at' => $ahora]);
            }
            $caso->update(['estado' => Conciliacion::ABIERTO, 'resolucion' => null, 'resuelto_por' => null, 'resuelto_at' => null]);
            ConciliacionEvento::create(['conciliacion_id' => $caso->id, 'accion' => self::ACCION_REVERTIDA, 'estado_anterior' => Conciliacion::RESUELTO, 'estado_nuevo' => Conciliacion::ABIERTO, 'motivo' => $motivo, 'actor_id' => $actorId,
                'evidencia' => ['revierte' => $ultimo->accion, 'canonico_id' => $evi['canonico_id'] ?? null, 'restauradas' => count($ids)]]);
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
