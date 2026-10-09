<?php

namespace App\Support\CredentialFlow\Conciliaciones;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Conciliacion;
use App\Models\CredentialFlow\ConciliacionEvento;
use App\Models\CredentialFlow\Correo;
use App\Support\CredentialFlow\Historico\ConsultaHistorica;
use App\Support\CredentialFlow\Portal\AccesoPortal;
use App\Support\CredentialFlow\Portal\Hmac;
use App\Support\CredentialFlow\Portal\NombreConservador;
use App\Support\CredentialFlow\Rehearsal\GuardiaClave;
use Illuminate\Support\Facades\DB;

/**
 * Detector IDEMPOTENTE de casos de conciliación (Fase 10A). Lee el estado ACTUAL del histórico y crea casos; jamás modifica una tabla
 * histórica (certificados, snapshots, correos, descargas, plantillas, encuestas, estados de conciliación). Escribe SOLO en
 * cf_conciliaciones, cf_conciliaciones_certificados y cf_conciliaciones_eventos.
 *
 * Qué detecta (y qué no):
 *  - conflicto_variantes  un caso por grupo de variantes en `pendiente_conciliacion`.
 *  - revision_documento   un caso por certificado en `revision_documento`.
 *  - identidad_ambigua    un caso por DOCUMENTO con varios grupos de nombre conservador que el portal NO puede autenticar (mismo gate de
 *                         AccesoPortal, solo leído). La similitud difusa no interviene. El documento se guarda como HMAC.
 *  - plantilla_candidata / plantilla_faltante / plantilla_tipo_invalido  un caso por plantilla problemática (o por evento si no tiene
 *                         entrada) que deja certificados en `pendiente_plantilla`.
 *  NO genera casos para certificados `ok`, duplicados idénticos consolidados ni imágenes huérfanas que no afectan a certificados. Los
 *  duplicados idénticos con estado restrictivo (plantilla o documento) se representan por el caso de plantilla/documento.
 *  Las respuestas de encuesta sin vínculo NO generan caso (no tienen certificado al que ligarse): ver docs de la Fase 10A.
 *
 * Idempotencia: la clave es `tipo:referencia_tipo:referencia_clave` (UNIQUE). Si el caso ya existe NO se toca (ni estado ni decisiones); solo
 * se completan relaciones faltantes del pivote. El evento «detectado» se escribe una única vez, en la misma transacción que crea el caso.
 */
final class DetectorConciliaciones
{
    /** Máximo de certificados que se insertan por sentencia en el pivote. */
    private const LOTE_PIVOTE = 500;

    /** @var list<string> claves de los grupos que NO se convierten en caso por diferir solo en código NULL contra valor (10B-1.5) */
    private array $omitidosSoloCodigo = [];

    /**
     * Planifica los casos SIN escribir nada.
     *
     * @return list<array{tipo:string,evento_id:?int,referencia_tipo:string,referencia_clave:string,motivo_origen:?string,clave:string,certificados:list<array{0:int,1:string}>}>
     */
    public function planear(): array
    {
        $this->omitidosSoloCodigo = [];

        return array_merge($this->conflictos(), $this->documentosEnRevision(), $this->identidades(), $this->plantillas());
    }

    /**
     * @return array{por_tipo:array<string,array{detectados:int,nuevos:int,existentes:int,relaciones_agregadas:int}>,total:array{detectados:int,nuevos:int,existentes:int,relaciones_agregadas:int},omitidos:array{solo_codigo_nulo_vs_valor:int,con_caso_existente:int}}
     */
    public function ejecutar(bool $simular = false): array
    {
        if (! $simular) {
            GuardiaClave::exigirCoincidencia();   // 11A: los HMAC persistidos deben generarse con la MISMA APP_KEY de la corrida
        }
        $plan = $this->planear();
        $resumen = array_fill_keys(Conciliacion::TIPOS, ['detectados' => 0, 'nuevos' => 0, 'existentes' => 0, 'relaciones_agregadas' => 0]);
        $existentes = DB::table('cf_conciliaciones')->pluck('id', 'clave_idempotencia');

        foreach ($plan as $c) {
            $r = &$resumen[$c['tipo']];
            $r['detectados']++;
            if ($simular) {
                $existe = $existentes->has($c['clave']);
                $r[$existe ? 'existentes' : 'nuevos']++;
                if (! $existe) {
                    $r['relaciones_agregadas'] += count($c['certificados']);
                }
                unset($r);

                continue;
            }
            [$nuevo, $agregadas] = $this->persistir($c);
            $r[$nuevo ? 'nuevos' : 'existentes']++;
            $r['relaciones_agregadas'] += $agregadas;
            unset($r);
        }

        $total = ['detectados' => 0, 'nuevos' => 0, 'existentes' => 0, 'relaciones_agregadas' => 0];
        foreach ($resumen as $r) {
            foreach ($total as $k => $_) {
                $total[$k] += $r[$k];
            }
        }

        // Grupos que dejan de ser conflicto conceptual (solo difieren en código NULL contra valor): no generan caso nuevo. Los casos que ya
        // existían NO se tocan ni se borran (los resolverá 10B-2 de forma controlada).
        $omitidos = ['solo_codigo_nulo_vs_valor' => count($this->omitidosSoloCodigo), 'con_caso_existente' => count(array_filter($this->omitidosSoloCodigo, fn ($k) => $existentes->has($k)))];

        return ['por_tipo' => $resumen, 'total' => $total, 'omitidos' => $omitidos];
    }

    /**
     * Persiste UN caso planificado (idempotente por su clave). Público para que el backfill de DIF_VERIF (11A) reutilice exactamente la misma forma de caso/evento/pivote;
     * `$evidenciaExtra` se añade a la evidencia del evento «detectado» (solo ids/conteos).
     *
     * @param  array<string,mixed>  $c
     * @param  array<string,mixed>  $evidenciaExtra
     * @return array{0:bool,1:int} [¿se creó el caso?, relaciones agregadas al pivote]
     */
    public function persistir(array $c, array $evidenciaExtra = []): array
    {
        return DB::transaction(function () use ($c, $evidenciaExtra) {
            $ahora = now();
            $id = DB::table('cf_conciliaciones')->where('clave_idempotencia', $c['clave'])->value('id');
            $nuevo = false;
            if ($id === null) {
                // insertOrIgnore: si otro proceso lo creó entre la lectura y aquí, no falla ni duplica.
                $creado = DB::table('cf_conciliaciones')->insertOrIgnore([
                    'tipo' => $c['tipo'], 'estado' => Conciliacion::ABIERTO, 'evento_id' => $c['evento_id'], 'referencia_tipo' => $c['referencia_tipo'],
                    'referencia_clave' => $c['referencia_clave'], 'motivo_origen' => $c['motivo_origen'], 'clave_idempotencia' => $c['clave'],
                    'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
                $id = DB::table('cf_conciliaciones')->where('clave_idempotencia', $c['clave'])->value('id');
                $nuevo = $creado === 1;
                if ($nuevo) {
                    DB::table('cf_conciliaciones_eventos')->insert([
                        'conciliacion_id' => $id, 'accion' => ConciliacionEvento::DETECTADO, 'estado_anterior' => null, 'estado_nuevo' => Conciliacion::ABIERTO,
                        'motivo' => null, 'evidencia' => json_encode(['certificados' => count($c['certificados']), 'motivo_origen' => $c['motivo_origen']] + $evidenciaExtra), 'actor_id' => null, 'created_at' => $ahora,
                    ]);
                }
            }

            // Relaciones: solo las que faltan (idempotente; el UNIQUE lo garantiza aunque dos procesos coincidan).
            $tiene = DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $id)->pluck('certificado_legado_id')->flip();
            $filas = [];
            foreach ($c['certificados'] as [$cert, $rol]) {
                if (! $tiene->has($cert)) {
                    $filas[] = ['conciliacion_id' => $id, 'certificado_legado_id' => $cert, 'rol' => $rol, 'created_at' => $ahora, 'updated_at' => $ahora];
                }
            }
            $agregadas = 0;
            foreach (array_chunk($filas, self::LOTE_PIVOTE) as $lote) {
                $agregadas += DB::table('cf_conciliaciones_certificados')->insertOrIgnore($lote);
            }

            return [$nuevo, $agregadas];
        });
    }

    // ── A. Conflictos (variantes de un grupo duplicado con diferencias) ──────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function conflictos(): array
    {
        $filas = DB::table('cf_certificados_legado')->where('conciliacion_estado', CertificadoLegado::CONCILIACION_PENDIENTE)->orderBy('id')->get(['id', 'evento_id', 'grupo_duplicado', 'snapshot_legado']);
        $miembros = $filas->whereNotNull('grupo_duplicado')->pluck('grupo_duplicado')->unique()->values()->all();
        // Todas las variantes del grupo (aunque alguna tuviera otro estado), en un solo viaje.
        $delGrupo = $miembros === [] ? collect() : DB::table('cf_certificados_legado')->whereIn('grupo_duplicado', $miembros)->orderBy('id')->get(['id', 'evento_id', 'grupo_duplicado', 'codigo_legado'])->groupBy('grupo_duplicado');

        $casos = [];
        foreach ($filas->groupBy(fn ($f) => $f->grupo_duplicado ?? 'cert:'.$f->id) as $clave => $grupo) {
            $primero = $grupo->first();
            $suelto = $primero->grupo_duplicado === null;
            $todos = $suelto ? $grupo : ($delGrupo[$primero->grupo_duplicado] ?? $grupo);
            $snap = json_decode((string) $primero->snapshot_legado, true) ?: [];
            // 10B-1.5: variantes que solo difieren en código NULL contra valor del MISMO par no son un conflicto de contenido.
            if (! $suelto && ClasificadorVariantes::soloDifiereEnCodigoNulo($todos, $snap)) {
                $this->omitidosSoloCodigo[] = Conciliacion::TIPO_CONFLICTO_VARIANTES.':grupo_duplicado:'.$clave;

                continue;
            }
            $motivo = self::lista($snap['duplicado']['etiquetas'] ?? null);
            $casos[] = $this->caso(
                Conciliacion::TIPO_CONFLICTO_VARIANTES, $suelto ? 'certificado' : 'grupo_duplicado', $suelto ? (string) $primero->id : (string) $clave,
                $motivo === [] ? null : implode(',', $motivo), $this->eventoUnico($todos->pluck('evento_id')),
                $todos->map(fn ($t) => [(int) $t->id, 'variante'])->all(),
            );
        }

        return $casos;
    }

    // ── B. Documento en revisión ─────────────────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function documentosEnRevision(): array
    {
        $casos = [];
        foreach (DB::table('cf_certificados_legado')->where('conciliacion_estado', CertificadoLegado::CONCILIACION_REVISION_DOCUMENTO)->orderBy('id')->get(['id', 'evento_id', 'snapshot_legado']) as $f) {
            $snap = json_decode((string) $f->snapshot_legado, true) ?: [];
            $motivo = collect(self::lista($snap['motivos'] ?? null))->first(fn ($m) => str_starts_with((string) $m, 'DOC_'));
            $casos[] = $this->caso(Conciliacion::TIPO_REVISION_DOCUMENTO, 'certificado', (string) $f->id, $motivo ?? 'DOC_OTRO', (int) $f->evento_id, [[(int) $f->id, 'afectado']]);
        }

        return $casos;
    }

    // ── C. Identidad ambigua (documento con varios grupos de nombre que el portal no puede autenticar) ───

    /** @return list<array<string,mixed>> */
    private function identidades(): array
    {
        // documento_clave → grupo de nombre conservador → ids. Solo se guardan ids (el nombre se descarta en cuanto se agrupa).
        $porDoc = [];
        DB::table('cf_certificados_legado')->select('id', 'documento_clave', 'nombre_completo')->orderBy('id')->chunkById(5000, function ($filas) use (&$porDoc) {
            foreach ($filas as $f) {
                $porDoc[$f->documento_clave][NombreConservador::grupoId((string) $f->nombre_completo)][] = (int) $f->id;
            }
        });

        $acceso = new AccesoPortal;
        $casos = [];
        foreach ($porDoc as $doc => $grupos) {
            if (count($grupos) < 2) {
                continue;
            }
            $ids = array_merge(...array_values($grupos));
            $motivo = $this->motivoBloqueo((string) $doc, $ids, $acceso);
            if ($motivo === null) {
                continue; // al menos un correo autentica con alcance claro: el portal ya funciona para este documento.
            }
            $filas = DB::table('cf_certificados_legado')->whereIn('id', $ids)->get(['id', 'evento_id'])->keyBy('id');
            $certificados = [];
            $n = 0;
            foreach ($grupos as $idsGrupo) {
                $n++;
                foreach ($idsGrupo as $id) {
                    $certificados[] = [$id, 'grupo_'.$n];
                }
            }
            $casos[] = $this->caso(Conciliacion::TIPO_IDENTIDAD_AMBIGUA, 'documento', Hmac::de('conciliacion_documento', (string) $doc), $motivo, $this->eventoUnico($filas->pluck('evento_id')), $certificados);
        }

        return $casos;
    }

    /** null si el documento SÍ puede autenticar; si no, el motivo técnico del bloqueo. Mismo gate que el portal (solo lectura). */
    private function motivoBloqueo(string $doc, array $ids, AccesoPortal $acceso): ?string
    {
        if (AccesoPortal::claveDocumento($doc) === null) {
            return 'DOCUMENTO_INVALIDO';
        }
        $correos = DB::table('cf_correos')->whereIn('certificado_legado_id', $ids)->where('estado', Correo::ESTADO_VALIDO)->get(['certificado_legado_id', 'correo_normalizado']);
        if ($correos->isEmpty()) {
            return 'SIN_CORREOS_VALIDOS';
        }
        foreach ($correos->pluck('correo_normalizado')->unique() as $correo) {
            if ($acceso->alcance($doc, $correo) !== null) {
                return null;
            }
        }
        $grupoDe = DB::table('cf_certificados_legado')->whereIn('id', $ids)->get(['id', 'nombre_completo'])->mapWithKeys(fn ($f) => [$f->id => NombreConservador::grupoId((string) $f->nombre_completo)]);
        $cruza = $correos->groupBy('correo_normalizado')->contains(fn ($g) => $g->map(fn ($x) => $grupoDe[$x->certificado_legado_id])->unique()->count() > 1);

        return $cruza ? 'CORREO_CRUZA_GRUPOS' : 'SIN_ALCANCE_HABILITANTE';
    }

    // ── D. Plantillas pendientes ─────────────────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function plantillas(): array
    {
        $pendientes = DB::table('cf_certificados_legado')->where('conciliacion_estado', CertificadoLegado::CONCILIACION_PENDIENTE_PLANTILLA)->orderBy('id')->get(['id', 'evento_id'])->groupBy('evento_id');
        if ($pendientes->isEmpty()) {
            return [];
        }
        $eventos = DB::table('cf_eventos')->whereIn('id', $pendientes->keys())->get(['id', 'plantilla_legado_id'])->keyBy('id');
        $entradas = DB::table('cf_plantillas_legado')->whereIn('id', $eventos->pluck('plantilla_legado_id')->filter()->unique())->get(['id', 'estado', 'motivo_no_renderizable', 'notas'])->keyBy('id');

        // Se agrupa por entrada de plantilla problemática (varios eventos pueden compartirla) o, sin entrada, por evento.
        $grupos = [];
        foreach ($pendientes as $eventoId => $certs) {
            $e = $entradas[$eventos[$eventoId]->plantilla_legado_id ?? 0] ?? null;
            $porEntrada = $e !== null && in_array($e->estado, ['faltante', 'extension_invalida'], true);
            $clave = $porEntrada ? 'plantilla_legado:'.$e->id : 'evento:'.$eventoId;
            $grupos[$clave]['entrada'] = $porEntrada ? $e : null;
            $grupos[$clave]['eventos'][] = (int) $eventoId;
            $grupos[$clave]['certs'] = array_merge($grupos[$clave]['certs'] ?? [], $certs->pluck('id')->map(fn ($i) => (int) $i)->all());
        }

        $casos = [];
        foreach ($grupos as $clave => $g) {
            [$refTipo, $refClave] = explode(':', $clave, 2);
            $e = $g['entrada'];
            [$tipo, $motivo] = match (true) {
                $e !== null && $e->estado === 'extension_invalida' => [Conciliacion::TIPO_PLANTILLA_TIPO_INVALIDO, $e->motivo_no_renderizable ?? 'EXTENSION_INVALIDA'],
                $e !== null && ConsultaHistorica::evidenciaDe($e->notas) !== null => [Conciliacion::TIPO_PLANTILLA_CANDIDATA, 'ARCHIVO_FALTANTE_CON_CANDIDATA'],
                $e !== null => [Conciliacion::TIPO_PLANTILLA_FALTANTE, 'ARCHIVO_FALTANTE'],
                default => [Conciliacion::TIPO_PLANTILLA_FALTANTE, 'EVENTO_SIN_PLANTILLA_UTILIZABLE'],
            };
            $casos[] = $this->caso($tipo, $refTipo, $refClave, $motivo, $this->eventoUnico(collect($g['eventos'])), array_map(fn ($id) => [$id, 'afectado'], $g['certs']));
        }

        return $casos;
    }

    // ── Utilidades ───────────────────────────────────────────────────────────────────────────────────────

    /** @param list<array{0:int,1:string}> $certificados */
    private function caso(string $tipo, string $refTipo, string $refClave, ?string $motivo, ?int $eventoId, array $certificados): array
    {
        return [
            'tipo' => $tipo, 'evento_id' => $eventoId, 'referencia_tipo' => $refTipo, 'referencia_clave' => $refClave, 'motivo_origen' => $motivo,
            'clave' => $tipo.':'.$refTipo.':'.$refClave, 'certificados' => $certificados,
        ];
    }

    /** El evento si todos los certificados son del mismo; si no, null. */
    public function eventoUnicoDe($ids): ?int
    {
        return $this->eventoUnico($ids);
    }

    /** Forma de un caso planificado (la misma que usa el detector). @return array<string,mixed> */
    public function casoPlan(string $tipo, string $refTipo, string $refClave, ?string $motivo, ?int $eventoId, array $certificados): array
    {
        return $this->caso($tipo, $refTipo, $refClave, $motivo, $eventoId, $certificados);
    }

    /** El evento si todos los certificados son del mismo; si no, null. */
    private function eventoUnico($ids): ?int
    {
        $u = collect($ids)->filter()->unique();

        return $u->count() === 1 ? (int) $u->first() : null;
    }

    /** Etiquetas del migrador: pueden venir como arreglo o como texto separado por comas. @return list<string> */
    public static function lista(mixed $v): array
    {
        $a = is_array($v) ? $v : (is_string($v) ? explode(',', $v) : []);

        return array_values(array_filter(array_map(fn ($x) => trim((string) $x), $a), fn ($x) => $x !== ''));
    }
}
