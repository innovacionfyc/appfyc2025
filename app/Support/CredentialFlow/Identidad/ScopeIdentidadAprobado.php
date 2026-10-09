<?php

namespace App\Support\CredentialFlow\Identidad;

use App\Models\CredentialFlow\Conciliacion;
use App\Models\CredentialFlow\DecisionIdentidad;
use App\Support\CredentialFlow\Portal\AccesoPortal;
use App\Support\CredentialFlow\Portal\NombreConservador;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ÚNICO resolver de las decisiones de identidad (Fase 10B-3B-1): documento + correo + decisiones VIGENTES → scope autorizado exacto. OTP, sesión y descarga
 * deben usar esta clase y no reimplementar nada. Es PURO (solo lectura; mismas entradas + mismo estado ⇒ mismo resultado y mismo `scope_hash`).
 *
 * UNA DECISIÓN NO AUTORIZA POR SÍ SOLA: siempre hace falta documento + correo + OTP válido + decisión vigente aplicable. Una decisión nunca REDUCE el acceso
 * que ya concede el gate histórico (los documentos con decisiones son casos bloqueados) y las terminales / personas distintas nunca lo crean.
 *
 * CONCEDER vs RESTRINGIR (10B-3C-0): solo CONCEDEN una `correo_autorizado` aplicable y una `misma_persona` que el Modelo 1 admite para ese correo. Solo
 * RESTRINGEN (bloquean por sí solas): la integridad inválida, una terminal vigente y `personas_distintas` cuando el correo cruza los grupos que separa. Una
 * decisión que NO concede (varios candidatos, correo fuera del conjunto, masivo, riesgo reforzado diferido a 3C...) NO bloquea por sí sola: se anota su motivo y
 * decide el gate histórico, de modo que **una decisión no aplicable nunca reduce un acceso histórico existente**. Si el gate tampoco abre, el resultado es
 * `bloqueado` con ese motivo (para distinguir «hay una decisión pero no basta» de «no hay nada»).
 *
 * PRECEDENCIA (cada paso solo se evalúa si los anteriores no resolvieron):
 *   0. Integridad            cualquier inconsistencia entre decisiones, detalle, grupos reales, correos reales o caso ⇒ CONFLICTO (falla cerrada; jamás fallback).
 *   1. Terminal vigente      requiere_soporte / no_resoluble ⇒ SOPORTE (jamás fallback): un caso en soporte no abre nada.
 *   2. Personas distintas    RESTRINGE: un correo presente en ≥ 2 grupos que la decisión separa no es vía (solo una autorización de correo explícita lo salva).
 *                            No quita el acceso exclusivo legítimo a un grupo (ese correo no cruza los grupos separados).
 *   3. Correo autorizado     correo → grupo G; si G está en una misma_persona vigente, el conjunto. Es explícito y humano: precede a lo histórico.
 *   4. Misma persona acotada Modelo 1: el correo abre el conjunto solo si es su ÚNICO candidato (existe en el conjunto y en ningún grupo fuera de él), el
 *                            conjunto no es masivo (< 100 certificados) y no hay riesgo reforzado. Si no, no concede y se sigue.
 *   5. Gate histórico        `AccesoPortal::alcance` tal cual: sin decisiones, o con decisiones que no conceden ni restringen a este correo.
 * Por qué ese orden: lo que bloquea gana a lo que concede (1, 2), lo explícito gana a lo implícito (3 > 4) y lo implícito gana al gate conservador solo
 * cuando es demostrable (4 > 5).
 *
 * El documento presentado acota todo: se cargan solo las filas de ese documento, así que un correo usado en otro documento no puede traer grupos ajenos.
 * Canonicalización: `AccesoPortal::claveDocumento` y `Correo::normalizar` (vía `AccesoPortal::correoNormalizado`) las aplica quien llama; aquí se recalculan
 * los HMAC de dominio de identidad (`EvidenciaIdentidad`) a partir de ese MISMO valor canónico.
 */
final class ScopeIdentidadAprobado
{
    public function __construct(private readonly AccesoPortal $acceso) {}

    public function resolver(string $claveDocumento, string $correoNormalizado): ResultadoScope
    {
        $docHash = EvidenciaIdentidad::hashDocumento($claveDocumento);
        $cH = EvidenciaIdentidad::hashCorreo($correoNormalizado);

        $decisiones = DecisionIdentidad::query()->where('documento_hash', $docHash)->where('estado', DecisionIdentidad::VIGENTE)->orderBy('id')->get();
        // 10B-3C-4: una decisión TERMINAL (soporte / no resoluble) de un caso de GRUPO SIN VÍA solo habla de ESE grupo, que ya no tiene vía: NO restringe al documento ni al
        // grupo hermano que sí entra (portal antes = después). Solo los casos base (el documento entero bloqueado) la tratan como restricción.
        if ($decisiones->contains(fn ($d) => in_array($d->tipo, DecisionIdentidad::TERMINALES, true))) {
            $sinVia = DB::table('cf_conciliaciones')->whereIn('id', $decisiones->pluck('conciliacion_id')->unique())->where('motivo_origen', 'like', 'GRUPO_SIN_VIA_%')->pluck('id')->flip();
            $decisiones = $decisiones->reject(fn ($d) => in_array($d->tipo, DecisionIdentidad::TERMINALES, true) && $sinVia->has($d->conciliacion_id))->values();
        }
        $detalleHuerfano = $this->detalleActivoDeRevocadas($docHash);
        if ($decisiones->isEmpty() && ! $detalleHuerfano) {
            // Sin decisiones aplicables: exactamente el gate histórico actual.
            $al = $this->acceso->alcance($claveDocumento, $correoNormalizado);

            return $al !== null ? ResultadoScope::historico($al['grupo']) : ResultadoScope::sin(ResultadoScope::SIN_VIA, 'sin_decisiones_ni_gate_historico');
        }

        if ($detalleHuerfano) {
            return $this->conflicto('revocada_con_detalle_activo', $decisiones);
        }

        $d = $this->acceso->cargar($claveDocumento);
        $filas = $d['filas'];
        if ($filas->isEmpty()) {
            return ResultadoScope::sin(ResultadoScope::SIN_VIA, 'documento_sin_filas');
        }

        // Estructura histórica del documento: grupo → certificados / correos (HMAC) y correo → grupos.
        $certsPorGrupo = [];
        $correosPorGrupo = [];
        $filasPorGrupo = [];
        $hmacDe = [];
        foreach ($filas as $f) {
            $g = $d['grupos'][$f->id];
            $filasPorGrupo[$g][] = $f;
            $certsPorGrupo[$g] = ($certsPorGrupo[$g] ?? 0) + 1;
            foreach (array_keys($d['correos'][$f->id] ?? []) as $c) {
                $h = $hmacDe[$c] ??= EvidenciaIdentidad::hashCorreo($c);
                $correosPorGrupo[$g][$h] = true;
            }
        }
        $correosPorGrupo = array_map(fn ($c) => array_keys($c), $correosPorGrupo);
        foreach (array_keys($certsPorGrupo) as $g) {
            $correosPorGrupo[$g] ??= [];
        }

        $hijos = $this->hijos($decisiones);
        // Segundas aprobaciones (doble control, 10B-3C-3): una consulta para todas las decisiones del documento.
        $aprobaciones = DB::table('cf_decisiones_identidad_aprobaciones')->whereIn('decision_id', $decisiones->pluck('id'))->get()->keyBy('decision_id');
        $motivo = $this->integridad($decisiones, $hijos, $certsPorGrupo, $correosPorGrupo, $docHash, $aprobaciones);
        if ($motivo !== null) {
            return $this->conflicto($motivo, $decisiones);
        }

        // 1. Terminales.
        if ($decisiones->contains(fn ($x) => in_array($x->tipo, DecisionIdentidad::TERMINALES, true))) {
            return ResultadoScope::sin(ResultadoScope::SOPORTE, 'decision_terminal_vigente');
        }

        $gruposDeC = array_keys(array_filter($correosPorGrupo, fn ($cs) => in_array($cH, $cs, true)));
        sort($gruposDeC);
        if ($gruposDeC === []) {
            return ResultadoScope::sin(ResultadoScope::SIN_VIA, 'correo_ausente_del_documento');
        }

        $mismas = $decisiones->where('tipo', DecisionIdentidad::MISMA_PERSONA);
        $setDe = fn ($dec) => $hijos['grupos'][$dec->id];
        $autorizado = $decisiones->first(fn ($x) => $x->tipo === DecisionIdentidad::CORREO_AUTORIZADO && ($hijos['correos'][$x->id]->correo_hmac ?? null) === $cH);

        // 2. Personas distintas (solo restringe).
        $restringido = $decisiones->where('tipo', DecisionIdentidad::PERSONAS_DISTINTAS)->contains(fn ($p) => count(array_intersect($setDe($p), $gruposDeC)) >= 2);

        // Motivo de la PRIMERA decisión que apuntaba a este correo pero NO pudo conceder (inaplicable, diferida a 3C, masiva sin confirmar...). Una decisión que no
        // concede NO bloquea por sí sola: solo informa el motivo si, además, el gate histórico tampoco abre.
        $inaplicable = null;

        // 3. Correo autorizado explícito.
        if ($autorizado !== null) {
            $g = (string) $hijos['correos'][$autorizado->id]->grupo_hash;
            $ap = $aprobaciones[$autorizado->id] ?? null;
            $misma = $mismas->first(fn ($m) => in_array($g, $setDe($m), true));
            $scope = $misma !== null ? $setDe($misma) : [$g];
            $ids = array_filter([(int) $autorizado->id, $misma?->id]);
            $total = (int) array_sum(array_map(fn ($x) => $certsPorGrupo[$x], $scope));
            if ($ap !== null || ModeloAcotado::masivo((int) ($certsPorGrupo[$g] ?? 0))) {
                // AUTORIZACIÓN MASIVA (10B-3C-3): el grupo por sí solo es masivo. El scope es SIEMPRE su grupo (jamás se expande por una `misma_persona`) y solo es
                // aplicable con doble control + evidencia externa + confirmación masiva + el interruptor masivo. Si no lo es, NO bloquea: cae al gate histórico.
                $r = $this->masivoAutorizado($docHash, $cH, $autorizado, $ap, $g, $filasPorGrupo, count($gruposDeC));
                if ($r->esAprobado()) {
                    return $r;
                }
                $inaplicable = (string) $r->motivoInterno;
            } elseif ($this->riesgoReforzado($filasPorGrupo, $scope)) {
                $inaplicable = 'riesgo_reforzado_pendiente_3c';
            } elseif (ModeloAcotado::masivo($total) && ! ($autorizado->confirmo_alcance_masivo && ($misma === null || $misma->confirmo_alcance_masivo))) {
                $inaplicable = 'scope_masivo_sin_confirmacion';
            } elseif (count($gruposDeC) >= 2 && ! $autorizado->confirmacion_reforzada) {
                $inaplicable = 'correo_compartido_sin_confirmacion_reforzada';
            } else {
                $r = $this->aprobado($docHash, $cH, $scope, $ids, $filasPorGrupo);
                if ($r->esAprobado()) {
                    return $r;
                }
                $inaplicable = (string) $r->motivoInterno;
            }
        }

        // 2 (restricción explícita). Personas distintas separa los grupos en los que este correo aparece y NO hay autorización de correo que lo salve: el
        // gate histórico ya sería ambiguo (un correo en ≥ 2 grupos nunca abre), así que bloquear aquí no quita ningún acceso histórico.
        if ($autorizado === null && $restringido) {
            return ResultadoScope::sin(ResultadoScope::BLOQUEADO, 'personas_distintas_y_correo_compartido');
        }

        // 4. Misma persona acotada. Si concede, scope aprobado. Si NO concede (correo fuera del conjunto, varios candidatos, masivo, riesgo reforzado...) NO bloquea:
        // solo anota el motivo y se sigue con el siguiente conjunto y, al final, con el gate histórico.
        if ($autorizado === null || $inaplicable !== null) {
            foreach ($mismas as $m) {
                $scope = $setDe($m);
                if (array_intersect($gruposDeC, $scope) === []) {
                    continue;
                }
                if ($this->riesgoReforzado($filasPorGrupo, $scope)) {
                    $inaplicable ??= 'riesgo_reforzado_pendiente_3c';

                    continue;
                }
                $total = (int) array_sum(array_map(fn ($x) => $certsPorGrupo[$x], $scope));
                $c = ModeloAcotado::concede($correosPorGrupo, $scope, $cH, $total);
                if ($c['concede']) {
                    $r = $this->aprobado($docHash, $cH, $scope, [(int) $m->id], $filasPorGrupo);
                    if ($r->esAprobado()) {
                        return $r;
                    }
                    $inaplicable ??= (string) $r->motivoInterno;

                    continue;
                }
                $inaplicable ??= (string) $c['motivo'];
            }
        }

        // 5. Gate histórico: sin ninguna decisión que CONCEDA ni RESTRINJA a este correo, manda exactamente lo de siempre. Una decisión no aplicable nunca reduce
        // un acceso histórico existente.
        $al = $this->acceso->alcance($claveDocumento, $correoNormalizado);
        if ($al !== null) {
            return ResultadoScope::historico($al['grupo']);
        }
        if ($inaplicable !== null) {
            return ResultadoScope::sin(ResultadoScope::BLOQUEADO, $inaplicable);
        }

        return count($gruposDeC) >= 2 ? ResultadoScope::sin(ResultadoScope::AMBIGUO, 'correo_en_varios_grupos_sin_decision_aplicable') : ResultadoScope::sin(ResultadoScope::SIN_VIA, 'sin_decision_aplicable');
    }

    /**
     * Unión de grupos con RIESGO REFORZADO: dos grupos del scope comparten evento (p. ej. DIF_NOMBRE) o sus nombres son realmente distintos. Aunque exista una
     * decisión (que 3A solo registra con evidencia externa declarada), 10B-3B no la aplica: se deja a 10B-3C, que puede además corregir el certificado.
     * La clase de nombres es DESCRIPTIVA y aquí solo restringe (jamás concede por parecido).
     *
     * @param  array<string,list<object>>  $filasPorGrupo
     * @param  list<string>  $scope
     */
    private function riesgoReforzado(array $filasPorGrupo, array $scope): bool
    {
        if (count($scope) < 2) {
            return false;
        }
        $eventos = [];
        $nombres = [];
        foreach ($scope as $g) {
            $eventos[$g] = collect($filasPorGrupo[$g] ?? [])->pluck('evento_id')->unique()->all();
            $nombres[$g] = NombreConservador::normalizar((string) ($filasPorGrupo[$g][0]->nombre_completo ?? ''));
        }
        for ($i = 0; $i < count($scope); $i++) {
            for ($j = $i + 1; $j < count($scope); $j++) {
                if (array_intersect($eventos[$scope[$i]], $eventos[$scope[$j]]) !== [] || EvidenciaIdentidad::clase($nombres[$scope[$i]], $nombres[$scope[$j]]) === 'distinto') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $scope
     * @param  list<int>  $ids
     * @param  array<string,list<object>>  $filasPorGrupo
     */
    private function aprobado(string $docHash, string $cH, array $scope, array $ids, array $filasPorGrupo): ResultadoScope
    {
        $filasScope = collect($scope)->flatMap(fn ($g) => $filasPorGrupo[$g] ?? []);
        if (! $filasScope->contains(fn ($f) => AccesoPortal::habilitaAcceso($f))) {
            return ResultadoScope::sin(ResultadoScope::SIN_VIA, 'scope_sin_fila_habilitante');
        }

        // Un scope masivo SOLO existe por la vía de la autorización masiva (`masivoAutorizado`): aquí (misma persona + correo, o grupos pequeños sumados) no se concede.
        if (ModeloAcotado::masivo((int) $filasScope->count())) {
            return ResultadoScope::sin(ResultadoScope::SIN_VIA, 'scope_masivo_sin_doble_control');
        }

        return ResultadoScope::aprobado($docHash, $cH, $scope, array_values($ids), false);
    }

    /**
     * Autorización masiva de UN grupo (>= 100 certificados), con doble control (10B-3C-3). Aplicable solo si TODO se cumple: decisión vigente (ya filtrada), segunda
     * aprobación `aprobada` de un actor distinto (la integridad ya exigió que fuera distinto), evidencia externa declarada y con huella, confirmación masiva,
     * confirmación reforzada si el correo está en varios grupos, el interruptor masivo (con los otros dos) y una fila habilitante. El scope es SOLO ese grupo.
     *
     * @param  array<string,list<object>>  $filasPorGrupo
     */
    private function masivoAutorizado(string $docHash, string $cH, object $decision, ?object $ap, string $g, array $filasPorGrupo, int $gruposDelCorreo): ResultadoScope
    {
        if ($ap === null) {
            return ResultadoScope::sin(ResultadoScope::SIN_VIA, 'scope_masivo_sin_doble_control');
        }
        if ($ap->estado !== 'aprobada') {
            return ResultadoScope::sin(ResultadoScope::SIN_VIA, $ap->estado === 'pendiente' ? 'pendiente_segunda_aprobacion' : 'aprobacion_revocada');
        }
        if (! $decision->confirmo_alcance_masivo || ! $decision->declaro_evidencia_externa || $decision->evidencia_sha256 === null) {
            return ResultadoScope::sin(ResultadoScope::SIN_VIA, 'masivo_sin_evidencia_o_confirmacion');
        }
        if ($gruposDelCorreo >= 2 && ! $decision->confirmacion_reforzada) {
            return ResultadoScope::sin(ResultadoScope::SIN_VIA, 'correo_compartido_sin_confirmacion_reforzada');
        }
        if (! IdentidadFlags::masaHabilitada()) {
            return ResultadoScope::sin(ResultadoScope::SIN_VIA, 'mass_scope_apagado');
        }
        $filasScope = collect($filasPorGrupo[$g] ?? []);
        if (! $filasScope->contains(fn ($f) => AccesoPortal::habilitaAcceso($f))) {
            return ResultadoScope::sin(ResultadoScope::SIN_VIA, 'scope_sin_fila_habilitante');
        }

        return ResultadoScope::aprobado($docHash, $cH, [$g], [(int) $decision->id], true, [(int) $ap->id]);
    }

    /** Detalle todavía activo de decisiones REVOCADAS: corrupción manual o de BD ⇒ falla cerrada. */
    private function detalleActivoDeRevocadas(string $docHash): bool
    {
        return DB::table('cf_decisiones_identidad_grupos as g')->join('cf_decisiones_identidad as d', 'd.id', '=', 'g.decision_id')
            ->where('g.documento_hash', $docHash)->whereNotNull('g.vigente_tipo')->where('d.estado', '!=', DecisionIdentidad::VIGENTE)->exists()
            || DB::table('cf_decisiones_identidad_correos as c')->join('cf_decisiones_identidad as d', 'd.id', '=', 'c.decision_id')
                ->where('c.documento_hash', $docHash)->whereNotNull('c.vigente')->where('d.estado', '!=', DecisionIdentidad::VIGENTE)->exists();
    }

    /** @return array{grupos:array<int,list<string>>,filasGrupos:array<int,list<object>>,correos:array<int,object>,correosFilas:array<int,list<object>>} */
    private function hijos(Collection $decisiones): array
    {
        $ids = $decisiones->pluck('id');
        $gr = DB::table('cf_decisiones_identidad_grupos')->whereIn('decision_id', $ids)->orderBy('id')->get()->groupBy('decision_id');
        $co = DB::table('cf_decisiones_identidad_correos')->whereIn('decision_id', $ids)->orderBy('id')->get()->groupBy('decision_id');
        $grupos = [];
        foreach ($gr as $id => $rows) {
            $l = $rows->pluck('grupo_hash')->map(fn ($x) => (string) $x)->unique()->all();
            sort($l);
            $grupos[$id] = $l;
        }
        foreach ($decisiones as $d) {
            $grupos[$d->id] ??= [];
        }

        return ['grupos' => $grupos, 'filasGrupos' => $gr->map->all()->all(), 'correos' => $co->map->first()->all(), 'correosFilas' => $co->map->all()->all()];
    }

    /**
     * @param  array<string,int>  $certsPorGrupo
     * @param  array<string,list<string>>  $correosPorGrupo
     */
    private function integridad(Collection $decisiones, array $hijos, array $certsPorGrupo, array $correosPorGrupo, string $docHash, Collection $aprobaciones): ?string
    {
        // Una segunda aprobación solo cuelga de un `correo_autorizado`; aprobada ⇒ aprobador presente y distinto del solicitante; pendiente ⇒ sin aprobador. Todo lo
        // demás es corrupción: falla cerrada.
        foreach ($aprobaciones as $ap) {
            $tipo = $decisiones->firstWhere('id', $ap->decision_id)?->tipo;
            if ($tipo !== DecisionIdentidad::CORREO_AUTORIZADO || $ap->documento_hash !== $docHash
                || ($ap->estado === 'pendiente' && $ap->aprobada_por !== null)
                || ($ap->estado === 'aprobada' && ($ap->aprobada_por === null || $ap->aprobada_at === null || (int) $ap->aprobada_por === (int) $ap->solicitada_por))
                || ! in_array($ap->estado, ['pendiente', 'aprobada', 'revocada'], true)) {
                return 'aprobacion_inconsistente';
            }
        }
        $terminales = 0;
        $sustantivas = 0;
        $pertenencia = [DecisionIdentidad::MISMA_PERSONA => [], DecisionIdentidad::PERSONAS_DISTINTAS => []];
        $correosVistos = [];
        foreach ($decisiones as $d) {
            if (! in_array($d->tipo, DecisionIdentidad::TIPOS, true)) {
                return 'tipo_invalido';
            }
            if ($d->vigente_clave === null || $d->revocada_at !== null || $d->revocada_por !== null) {
                return 'vigente_con_datos_de_revocacion';
            }
            $gruposFilas = $hijos['filasGrupos'][$d->id] ?? [];
            $correosFilas = $hijos['correosFilas'][$d->id] ?? [];
            if (in_array($d->tipo, DecisionIdentidad::TERMINALES, true)) {
                $terminales++;
                if ($gruposFilas !== [] || $correosFilas !== [] || (int) $d->vigente_caso_clave !== (int) $d->conciliacion_id) {
                    return 'terminal_inconsistente';
                }

                continue;
            }
            $sustantivas++;
            if ($d->tipo === DecisionIdentidad::CORREO_AUTORIZADO) {
                $c = $correosFilas[0] ?? null;
                if ($gruposFilas !== [] || count($correosFilas) !== 1 || (int) $c->vigente !== 1 || $c->documento_hash !== $docHash) {
                    return 'correo_autorizado_inconsistente';
                }
                if (! isset($certsPorGrupo[$c->grupo_hash])) {
                    return 'grupo_inexistente';
                }
                if (! in_array($c->correo_hmac, $correosPorGrupo[$c->grupo_hash], true)) {
                    return 'correo_ausente_del_grupo';
                }
                if (isset($correosVistos[$c->correo_hmac])) {
                    return 'correo_autorizado_a_dos_grupos';
                }
                $correosVistos[$c->correo_hmac] = true;

                continue;
            }
            // misma_persona / personas_distintas
            if ($correosFilas !== [] || count($gruposFilas) < 2) {
                return 'grupos_insuficientes_o_con_correo';
            }
            foreach ($gruposFilas as $g) {
                if ($g->vigente_tipo !== $d->tipo || $g->documento_hash !== $docHash) {
                    return 'detalle_de_grupo_inconsistente';
                }
                if (! isset($certsPorGrupo[$g->grupo_hash])) {
                    return 'grupo_inexistente';
                }
                if (isset($pertenencia[$d->tipo][$g->grupo_hash])) {
                    return 'grupo_en_dos_alcances';
                }
                $pertenencia[$d->tipo][$g->grupo_hash] = (int) $d->id;
            }
        }
        if ($terminales > 1 || ($terminales > 0 && $sustantivas > 0)) {
            return 'terminal_con_otras_decisiones';
        }
        foreach ($decisiones->where('tipo', DecisionIdentidad::MISMA_PERSONA) as $m) {
            foreach ($decisiones->where('tipo', DecisionIdentidad::PERSONAS_DISTINTAS) as $p) {
                if (count(array_intersect($hijos['grupos'][$m->id], $hijos['grupos'][$p->id])) >= 2) {
                    return 'misma_persona_contradice_personas_distintas';
                }
            }
        }

        // El caso de cada decisión debe existir, ser de identidad, ser de ESTE documento y no estar descartado ni resuelto por otra vía.
        $casos = Conciliacion::query()->whereIn('id', $decisiones->pluck('conciliacion_id')->unique())->get()->keyBy('id');
        foreach ($decisiones as $d) {
            $caso = $casos[$d->conciliacion_id] ?? null;
            if ($caso === null || $caso->tipo !== Conciliacion::TIPO_IDENTIDAD_AMBIGUA || $caso->referencia_clave !== $docHash) {
                return 'caso_inconsistente';
            }
            if (! in_array($caso->estado, [Conciliacion::ABIERTO, Conciliacion::REQUIERE_SOPORTE], true) && ! ($caso->estado === Conciliacion::RESUELTO && $caso->resolucion === 'identidad_aplicada')) {
                return 'caso_en_estado_incompatible';
            }
        }

        return null;
    }

    private function conflicto(string $motivo, Collection $decisiones): ResultadoScope
    {
        // Solo ids y un código técnico: nunca documentos, correos ni nombres.
        Log::warning('Credential Flow: decisiones de identidad inconsistentes; se niega el acceso.', ['motivo' => $motivo, 'decisiones' => $decisiones->pluck('id')->all()]);

        return ResultadoScope::sin(ResultadoScope::CONFLICTO, $motivo);
    }
}
