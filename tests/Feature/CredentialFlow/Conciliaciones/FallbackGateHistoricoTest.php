<?php

namespace Tests\Feature\CredentialFlow\Conciliaciones;

use App\Models\CredentialFlow\Conciliacion;
use App\Support\CredentialFlow\Identidad\DecisionesIdentidad;
use App\Support\CredentialFlow\Identidad\EvidenciaIdentidad;
use App\Support\CredentialFlow\Identidad\ResultadoScope;
use App\Support\CredentialFlow\Identidad\ScopeIdentidadAprobado;
use App\Support\CredentialFlow\Portal\AccesoPortal;
use App\Support\CredentialFlow\Portal\NombreConservador;
use App\Support\CredentialFlow\Portal\ServicioOtp;
use Illuminate\Support\Facades\DB;

/**
 * Fase 10B-3C-0: NO REGRESIÓN DEL GATE HISTÓRICO. Una decisión que no CONCEDE acceso a un correo (varios candidatos, correo fuera del conjunto, riesgo diferido a
 * 3C...) y que tampoco lo RESTRINGE explícitamente no puede reducir el acceso histórico que ya tiene: decide el gate. El patrón real (30 documentos): un grupo
 * hermano S entra con su correo exclusivo `c`, y comparte otro correo `x` con un grupo G que hoy no tiene vía.
 */
class FallbackGateHistoricoTest extends ConciliacionesTestCase
{
    private const MOTIVO = 'Decisión administrativa de prueba.';

    private const EVID = 'Confirmado por el organizador del evento mediante correo institucional.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->detectar();
        config(['credential_flow.identidad.decisiones_enabled' => true]);
    }

    private function resolver(): ScopeIdentidadAprobado
    {
        return app(ScopeIdentidadAprobado::class);
    }

    private function svc(): DecisionesIdentidad
    {
        return app(DecisionesIdentidad::class);
    }

    private function actorId(): int
    {
        return $this->admin()->id;
    }

    /**
     * @param  array<string,array{correos:list<string>,n?:int,evento?:int}>  $spec
     * @return array{clave:string,caso:Conciliacion,g:array<string,string>}
     */
    private function doc(string $sufijo, array $spec): array
    {
        $clave = '97'.str_pad($sufijo, 5, '0', STR_PAD_LEFT);
        $molde = (array) DB::table('cf_certificados_legado')->where('conciliacion_estado', 'ok')->orderBy('id')->first();
        unset($molde['id']);
        $caso = Conciliacion::create(['tipo' => Conciliacion::TIPO_IDENTIDAD_AMBIGUA, 'estado' => 'abierto', 'referencia_tipo' => 'documento', 'referencia_clave' => EvidenciaIdentidad::hashDocumento($clave),
            'motivo_origen' => 'CORREO_CRUZA_GRUPOS', 'clave_idempotencia' => 'identidad_ambigua:documento:fb'.$sufijo]);
        $eventos = DB::table('cf_eventos')->orderBy('id')->pluck('id')->all();
        $g = [];
        $i = 0;
        foreach ($spec as $nombre => $s) {
            $i++;
            $g[$nombre] = NombreConservador::grupoId($nombre);
            for ($k = 0; $k < ($s['n'] ?? 1); $k++) {
                $id = DB::table('cf_certificados_legado')->insertGetId(['documento' => $clave, 'documento_clave' => $clave, 'nombre_completo' => $nombre, 'codigo_legado' => null, 'grupo_duplicado' => null, 'estado' => 'vigente',
                    'conciliacion_estado' => 'ok', 'reemplazado_por_emision_id' => null, 'pdf_archivo' => null, 'pdf_hash' => null, 'evento_id' => $s['evento'] ?? $eventos[($i - 1) % count($eventos)]] + $molde);
                foreach ($s['correos'] as $n => $correo) {
                    DB::table('cf_correos')->insert(['certificado_legado_id' => $id, 'correo' => $correo, 'correo_normalizado' => $correo, 'estado' => 'valido', 'orden' => $n + 1, 'es_principal' => $n === 0 ? 1 : 0, 'origen' => 'legado', 'created_at' => now(), 'updated_at' => now()]);
                }
                DB::table('cf_conciliaciones_certificados')->insert(['conciliacion_id' => $caso->id, 'certificado_legado_id' => $id, 'rol' => 'grupo_'.$i, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        return ['clave' => $clave, 'caso' => $caso, 'g' => $g];
    }

    /** El patrón real: S entra con `c` (exclusivo); G solo tiene el correo `x` que comparte con S. */
    private function par(string $sufijo, array $extra = []): array
    {
        return $this->doc($sufijo, ['LAURA RIOS S' => ['correos' => ['c'.$sufijo.'@x.test', 'x'.$sufijo.'@x.test']] + $extra, 'LAURA RIOS G' => ['correos' => ['x'.$sufijo.'@x.test']]]);
    }

    private function misma(array $d, array $nombres, array $extra = []): int
    {
        return $this->svc()->crear($d['caso']->id, $this->actorId(), 'misma_persona', self::MOTIVO, ['grupos' => array_map(fn ($n) => $d['g'][$n], $nombres), 'evidencia' => self::EVID, 'confirmo' => true, 'evidencia_externa' => true] + $extra)['decision_id'];
    }

    private function autorizar(array $d, string $nombre, string $correo): int
    {
        return $this->svc()->crear($d['caso']->id, $this->actorId(), 'correo_autorizado', self::MOTIVO, ['grupo' => $d['g'][$nombre], 'correo_hmac' => EvidenciaIdentidad::hashCorreo($correo), 'evidencia' => self::EVID, 'reforzada' => true])['decision_id'];
    }

    private function distintas(array $d, array $nombres): int
    {
        return $this->svc()->crear($d['caso']->id, $this->actorId(), 'personas_distintas', self::MOTIVO, ['grupos' => array_map(fn ($n) => $d['g'][$n], $nombres), 'confirmo' => true])['decision_id'];
    }

    private function res(array $d, string $correo): ResultadoScope
    {
        return $this->resolver()->resolver($d['clave'], $correo);
    }

    /** Lo que concede hoy el gate histórico a ese correo (la referencia contra la que no puede haber regresión). */
    private function gate(array $d, string $correo): ?array
    {
        return (new AccesoPortal)->alcance($d['clave'], $correo);
    }

    // ── A. misma_persona NO aplicable + correo histórico exclusivo ───────────

    public function test_una_misma_persona_que_no_concede_no_quita_el_acceso_historico_del_correo_exclusivo(): void
    {
        $d = $this->par('1');
        $antes = $this->res($d, 'c1@x.test');
        $this->assertSame([ResultadoScope::HISTORICO_NORMAL, $this->g('LAURA RIOS S')], [$antes->tipo, $antes->grupoHistorico], 'hoy c entra al grupo S por el gate');

        $this->misma($d, ['LAURA RIOS S', 'LAURA RIOS G']);   // dos candidatos (c y x): el Modelo 1 NO concede

        $despues = $this->res($d, 'c1@x.test');
        $this->assertSame([ResultadoScope::HISTORICO_NORMAL, $this->g('LAURA RIOS S'), null], [$despues->tipo, $despues->grupoHistorico, $despues->scopeHash], 'antes = después: acceso histórico, sin scope aprobado artificial');
        $this->assertSame($this->gate($d, 'c1@x.test'), ['grupo' => $despues->grupoHistorico]);
        // El correo compartido sigue sin vía (el gate ya era ambiguo) y se informa el motivo de la decisión que no basta.
        $x = $this->res($d, 'x1@x.test');
        $this->assertSame([ResultadoScope::BLOQUEADO, 'varios_candidatos_requieren_correo_autorizado'], [$x->tipo, $x->motivoInterno]);
        $this->assertNull($this->gate($d, 'x1@x.test'));
    }

    public function test_un_correo_que_escapa_del_conjunto_tampoco_pierde_su_acceso_historico(): void
    {
        $d = $this->doc('2', ['ROSA DIAZ A' => ['correos' => ['ca2@x.test', 'x2@x.test']], 'ROSA DIAZ B' => ['correos' => ['cb2@x.test', 'x2@x.test']], 'ROSA DIAZ C' => ['correos' => ['x2@x.test']]]);
        $this->misma($d, ['ROSA DIAZ A', 'ROSA DIAZ B']);   // x también está en C: escapa
        $r2 = $this->res($d, 'ca2@x.test');
        $this->assertSame(ResultadoScope::HISTORICO_NORMAL, $r2->tipo, $r2->tipo.' '.$r2->motivoInterno);
        $this->assertSame([ResultadoScope::BLOQUEADO, 'correo_fuera_del_scope'], [$this->res($d, 'x2@x.test')->tipo, $this->res($d, 'x2@x.test')->motivoInterno]);
    }

    // ── B. misma_persona aplicable ───────────────────────────────────────────

    public function test_una_misma_persona_que_si_concede_usa_el_scope_aprobado_sin_fallback(): void
    {
        $d = $this->doc('3', ['ELSA MORA A' => ['correos' => ['x3@x.test']], 'ELSA MORA B' => ['correos' => ['x3@x.test']]]);
        $dec = $this->misma($d, ['ELSA MORA A', 'ELSA MORA B']);
        $r = $this->res($d, 'x3@x.test');
        $this->assertSame([ResultadoScope::APROBADO, [$dec]], [$r->tipo, $r->decisionIds]);
        $this->assertNull($this->gate($d, 'x3@x.test'), 'sin la decisión no había vía: el acceso es nuevo y aprobado');
    }

    // ── C / D. personas_distintas ────────────────────────────────────────────

    public function test_personas_distintas_no_quita_el_acceso_exclusivo_a_cada_grupo(): void
    {
        $d = $this->doc('4', ['PEDRO LEON A' => ['correos' => ['a4@x.test']], 'PEDRO LEON B' => ['correos' => ['b4@x.test']]]);
        $this->distintas($d, ['PEDRO LEON A', 'PEDRO LEON B']);
        $a = $this->res($d, 'a4@x.test');
        $b = $this->res($d, 'b4@x.test');
        $this->assertSame([ResultadoScope::HISTORICO_NORMAL, $this->g('PEDRO LEON A')], [$a->tipo, $a->grupoHistorico], 'impide abrir B o A+B con este correo, pero no quita A');
        $this->assertSame([ResultadoScope::HISTORICO_NORMAL, $this->g('PEDRO LEON B')], [$b->tipo, $b->grupoHistorico]);
    }

    public function test_personas_distintas_con_correo_compartido_sigue_bloqueado_y_el_exclusivo_sigue_entrando(): void
    {
        $d = $this->par('5');
        $this->distintas($d, ['LAURA RIOS S', 'LAURA RIOS G']);
        $x = $this->res($d, 'x5@x.test');
        $this->assertSame([ResultadoScope::BLOQUEADO, 'personas_distintas_y_correo_compartido'], [$x->tipo, $x->motivoInterno], 'sin fallback: el gate ya era ambiguo');
        $this->assertSame(ResultadoScope::HISTORICO_NORMAL, $this->res($d, 'c5@x.test')->tipo);
    }

    // ── E. terminales ────────────────────────────────────────────────────────

    public function test_las_terminales_siguen_bloqueando_aunque_el_gate_historico_abriria(): void
    {
        $d = $this->par('6');
        $this->assertSame(ResultadoScope::HISTORICO_NORMAL, $this->res($d, 'c6@x.test')->tipo);
        foreach (['requiere_soporte', 'no_resoluble'] as $tipo) {
            $id = $this->svc()->crear($d['caso']->id, $this->actorId(), $tipo, self::MOTIVO)['decision_id'];
            $this->assertSame([ResultadoScope::SOPORTE, 'decision_terminal_vigente'], [$this->res($d, 'c6@x.test')->tipo, $this->res($d, 'c6@x.test')->motivoInterno], $tipo.': restricción explícita, sin fallback');
            $this->svc()->revocar($id, $this->actorId(), 'Se retira la terminal por la prueba.');
        }
        $this->assertSame(ResultadoScope::HISTORICO_NORMAL, $this->res($d, 'c6@x.test')->tipo, 'revocada la terminal, vuelve el gate');
    }

    // ── F. corrupción ────────────────────────────────────────────────────────

    public function test_la_corrupcion_sigue_fallando_cerrada_sin_fallback_historico(): void
    {
        $d = $this->par('7');
        $dec = $this->misma($d, ['LAURA RIOS S', 'LAURA RIOS G']);
        $this->assertSame(ResultadoScope::HISTORICO_NORMAL, $this->res($d, 'c7@x.test')->tipo);

        DB::table('cf_decisiones_identidad_grupos')->where('decision_id', $dec)->limit(1)->update(['grupo_hash' => str_repeat('f', 64)]);

        $r = $this->res($d, 'c7@x.test');
        $this->assertSame([ResultadoScope::CONFLICTO, 'grupo_inexistente'], [$r->tipo, $r->motivoInterno], 'el correo con acceso histórico tampoco entra si las decisiones están corruptas');
        $this->assertNotNull($this->gate($d, 'c7@x.test'), 'el gate abriría, pero la integridad (0) precede');
    }

    // ── G. riesgo diferido a 3C ──────────────────────────────────────────────

    public function test_el_riesgo_reforzado_no_se_aplica_pero_tampoco_quita_el_acceso_historico(): void
    {
        $d = $this->par('8');
        DB::table('cf_certificados_legado')->where('documento_clave', $d['clave'])->update(['evento_id' => 1]);   // mismo evento: DIF_NOMBRE
        $this->misma($d, ['LAURA RIOS S', 'LAURA RIOS G']);

        $this->assertSame(ResultadoScope::HISTORICO_NORMAL, $this->res($d, 'c8@x.test')->tipo, 'la barrera 3C no se salta (no hay scope aprobado) y lo histórico se conserva');
        $x = $this->res($d, 'x8@x.test');
        $this->assertSame([ResultadoScope::BLOQUEADO, 'riesgo_reforzado_pendiente_3c'], [$x->tipo, $x->motivoInterno], 'el correo sin vía histórica sigue bloqueado por la barrera 3C');
        $this->assertFalse($x->habilita());
    }

    public function test_nombres_realmente_distintos_siguen_bloqueados_para_el_correo_sin_vía(): void
    {
        $d = $this->doc('9', ['ALFREDO MONTOYA' => ['correos' => ['m9@x.test', 'x9@x.test']], 'ZENAIDA QUINTERO' => ['correos' => ['x9@x.test']]]);
        $this->misma($d, ['ALFREDO MONTOYA', 'ZENAIDA QUINTERO']);
        $this->assertSame(ResultadoScope::HISTORICO_NORMAL, $this->res($d, 'm9@x.test')->tipo);
        $this->assertSame('riesgo_reforzado_pendiente_3c', $this->res($d, 'x9@x.test')->motivoInterno);
    }

    // ── H. correo_autorizado ─────────────────────────────────────────────────

    public function test_un_correo_autorizado_usa_su_scope_y_el_exclusivo_del_hermano_no_se_pierde(): void
    {
        $d = $this->par('10');
        $aut = $this->autorizar($d, 'LAURA RIOS G', 'x10@x.test');

        $x = $this->res($d, 'x10@x.test');
        $this->assertSame([ResultadoScope::APROBADO, [$this->g('LAURA RIOS G')], [$aut]], [$x->tipo, $x->grupos, $x->decisionIds], 'la autorización explícita no cae al histórico: abre solo G');
        $c = $this->res($d, 'c10@x.test');
        $this->assertSame([ResultadoScope::HISTORICO_NORMAL, $this->g('LAURA RIOS S')], [$c->tipo, $c->grupoHistorico], 'S sigue entrando por su correo');

        // Autorización + misma persona no aplicable para c: c tampoco pierde su acceso.
        $this->misma($d, ['LAURA RIOS S', 'LAURA RIOS G']);
        $x = $this->res($d, 'x10@x.test');
        $this->assertSame([ResultadoScope::APROBADO, 2], [$x->tipo, count($x->grupos)], 'con la unión compatible, la autorización abre el conjunto');
        $this->assertSame(ResultadoScope::HISTORICO_NORMAL, $this->res($d, 'c10@x.test')->tipo);
    }

    public function test_una_autorizacion_con_riesgo_diferido_no_quita_lo_historico_del_hermano(): void
    {
        $d = $this->par('11');
        DB::table('cf_certificados_legado')->where('documento_clave', $d['clave'])->update(['evento_id' => 1]);
        $this->autorizar($d, 'LAURA RIOS G', 'x11@x.test');
        $this->misma($d, ['LAURA RIOS S', 'LAURA RIOS G']);   // la unión tiene riesgo reforzado: se difiere

        $x = $this->res($d, 'x11@x.test');
        $this->assertSame([ResultadoScope::BLOQUEADO, 'riesgo_reforzado_pendiente_3c'], [$x->tipo, $x->motivoInterno]);
        $this->assertSame(ResultadoScope::HISTORICO_NORMAL, $this->res($d, 'c11@x.test')->tipo);
    }

    // ── Cierre del caso y consumidores ───────────────────────────────────────

    public function test_una_decision_que_no_produce_scope_aprobado_nunca_marca_identidad_aplicada(): void
    {
        config(['credential_flow.identidad.multi_scope_enabled' => true]);
        $d = $this->par('12');
        $this->misma($d, ['LAURA RIOS S', 'LAURA RIOS G']);

        $this->assertSame(['abierto', null], [$d['caso']->fresh()->estado, $d['caso']->fresh()->resolucion]);
        $this->assertSame(0, DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $d['caso']->id)->where('accion', 'identidad_acceso_aplicado')->count());
    }

    public function test_el_fallback_no_crea_eventos_ni_escribe_nada(): void
    {
        $d = $this->par('13');
        $this->misma($d, ['LAURA RIOS S', 'LAURA RIOS G']);
        $antes = md5(json_encode([DB::table('cf_conciliaciones_eventos')->get(), DB::table('cf_decisiones_identidad')->get(), DB::table('movimientos')->get(), DB::table('cf_accesos_otp')->get()]));

        for ($i = 0; $i < 3; $i++) {
            $this->res($d, 'c13@x.test');
            $this->res($d, 'x13@x.test');
        }

        $this->assertSame($antes, md5(json_encode([DB::table('cf_conciliaciones_eventos')->get(), DB::table('cf_decisiones_identidad')->get(), DB::table('movimientos')->get(), DB::table('cf_accesos_otp')->get()])));
    }

    public function test_con_el_interruptor_apagado_el_comportamiento_es_el_historico(): void
    {
        $d = $this->par('14');
        $this->misma($d, ['LAURA RIOS S', 'LAURA RIOS G']);
        config(['credential_flow.identidad.decisiones_enabled' => false]);
        $tablas = [];
        DB::listen(function ($q) use (&$tablas) {
            if (str_contains($q->sql, 'cf_decisiones_identidad')) {
                $tablas[] = $q->sql;
            }
        });

        app(ServicioOtp::class)->solicitar($d['clave'], 'c14@x.test', '10.0.0.1', 'ua');

        $this->assertSame([], $tablas, 'apagado: ni se consultan las decisiones');
        $this->assertSame(1, DB::table('cf_accesos_otp')->count(), 'el correo con acceso histórico recibe su OTP de siempre');
        $this->assertNull(DB::table('cf_accesos_otp')->value('scope_hash'));
    }

    public function test_el_resolver_sigue_siendo_una_consulta_acotada_con_una_decision_no_aplicable(): void
    {
        $d = $this->par('15');
        $this->misma($d, ['LAURA RIOS S', 'LAURA RIOS G']);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->res($d, 'c15@x.test');
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(18, $n, 'sin N+1: decisiones + detalle + documento + gate histórico');
    }

    private function g(string $nombre): string
    {
        return NombreConservador::grupoId($nombre);
    }
}
