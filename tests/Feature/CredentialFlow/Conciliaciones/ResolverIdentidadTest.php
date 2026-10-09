<?php

namespace Tests\Feature\CredentialFlow\Conciliaciones;

use App\Mail\CodigoAccesoMail;
use App\Models\CredentialFlow\Conciliacion;
use App\Models\CredentialFlow\DecisionIdentidad;
use App\Support\CredentialFlow\Conciliaciones\ResolucionNoPermitida as No;
use App\Support\CredentialFlow\Identidad\AutorizacionMasiva;
use App\Support\CredentialFlow\Identidad\DecisionesIdentidad;
use App\Support\CredentialFlow\Identidad\EvidenciaIdentidad;
use App\Support\CredentialFlow\Identidad\IdentidadFlags;
use App\Support\CredentialFlow\Identidad\ModeloAcotado;
use App\Support\CredentialFlow\Identidad\ResultadoScope;
use App\Support\CredentialFlow\Identidad\ScopeIdentidadAprobado;
use App\Support\CredentialFlow\Portal\AccesoPortal;
use App\Support\CredentialFlow\Portal\NombreConservador;
use App\Support\CredentialFlow\Portal\ServicioOtp;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Fase 10B-3B-1: resolver de identidad + OTP con scope congelado, con el interruptor APAGADO por defecto. Ninguna prueba abre una sesión multi-grupo: no existe.
 * Los documentos se arman con nombres/correos sintéticos; el caso de identidad se crea como lo haría el detector.
 */
class ResolverIdentidadTest extends ConciliacionesTestCase
{
    private const MOTIVO = 'Decisión administrativa de prueba.';

    private const EVID = 'Confirmado por el organizador del evento mediante correo institucional.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->detectar();
        Mail::fake();
    }

    private function encender(bool $v = true): void
    {
        config(['credential_flow.identidad.decisiones_enabled' => $v]);
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
     * Documento sintético con un certificado (o `n`) por grupo de nombre y sus correos. @param array<string,array{correos:list<string>,n?:int}> $spec
     *
     * @return array{clave:string,caso:Conciliacion,g:array<string,string>}
     */
    private function doc(string $sufijo, array $spec): array
    {
        $clave = '98'.str_pad($sufijo, 5, '0', STR_PAD_LEFT);
        $molde = (array) DB::table('cf_certificados_legado')->where('conciliacion_estado', 'ok')->orderBy('id')->first();
        unset($molde['id']);
        $ahora = now();
        $caso = Conciliacion::create(['tipo' => Conciliacion::TIPO_IDENTIDAD_AMBIGUA, 'estado' => 'abierto', 'referencia_tipo' => 'documento', 'referencia_clave' => EvidenciaIdentidad::hashDocumento($clave),
            'motivo_origen' => 'CORREO_CRUZA_GRUPOS', 'clave_idempotencia' => 'identidad_ambigua:documento:'.$sufijo]);
        $g = [];
        $i = 0;
        $eventos = DB::table('cf_eventos')->orderBy('id')->pluck('id')->all();
        foreach ($spec as $nombre => $s) {
            $i++;
            $g[$nombre] = NombreConservador::grupoId($nombre);
            for ($k = 0; $k < ($s['n'] ?? 1); $k++) {
                $id = DB::table('cf_certificados_legado')->insertGetId(['documento' => $clave, 'documento_clave' => $clave, 'nombre_completo' => $nombre, 'codigo_legado' => null, 'grupo_duplicado' => null,
                    'estado' => 'vigente', 'conciliacion_estado' => 'ok', 'reemplazado_por_emision_id' => null, 'pdf_archivo' => null, 'pdf_hash' => null, 'evento_id' => $eventos[($i - 1) % count($eventos)]] + $molde);
                foreach ($s['correos'] as $n => $correo) {
                    DB::table('cf_correos')->insert(['certificado_legado_id' => $id, 'correo' => $correo, 'correo_normalizado' => $correo, 'estado' => 'valido', 'orden' => $n + 1, 'es_principal' => $n === 0 ? 1 : 0, 'origen' => 'legado', 'created_at' => $ahora, 'updated_at' => $ahora]);
                }
                DB::table('cf_conciliaciones_certificados')->insert(['conciliacion_id' => $caso->id, 'certificado_legado_id' => $id, 'rol' => 'grupo_'.$i, 'created_at' => $ahora, 'updated_at' => $ahora]);
            }
        }

        return ['clave' => $clave, 'caso' => $caso, 'g' => $g];
    }

    private function misma(array $d, array $nombres, array $extra = []): array
    {
        return $this->svc()->crear($d['caso']->id, $this->actorId(), DecisionIdentidad::MISMA_PERSONA, self::MOTIVO,
            ['grupos' => array_map(fn ($n) => $d['g'][$n], $nombres), 'evidencia' => self::EVID, 'confirmo' => true, 'evidencia_externa' => true] + $extra);
    }

    private function autorizar(array $d, string $nombre, string $correo, array $extra = []): array
    {
        return $this->svc()->crear($d['caso']->id, $this->actorId(), DecisionIdentidad::CORREO_AUTORIZADO, self::MOTIVO,
            ['grupo' => $d['g'][$nombre], 'correo_hmac' => EvidenciaIdentidad::hashCorreo($correo), 'evidencia' => self::EVID, 'reforzada' => true] + $extra);
    }

    private function distintas(array $d, array $nombres): array
    {
        return $this->svc()->crear($d['caso']->id, $this->actorId(), DecisionIdentidad::PERSONAS_DISTINTAS, self::MOTIVO, ['grupos' => array_map(fn ($n) => $d['g'][$n], $nombres), 'confirmo' => true]);
    }

    private function res(array $d, string $correo): ResultadoScope
    {
        return $this->resolver()->resolver($d['clave'], $correo);
    }

    /** @return list<string> */
    private function grupos(array $d, array $nombres): array
    {
        $h = array_map(fn ($n) => $d['g'][$n], $nombres);
        sort($h);

        return $h;
    }

    // ── Interruptores ────────────────────────────────────────────────────────

    public function test_los_dos_interruptores_estan_apagados_por_defecto_y_la_sesion_multigrupo_tiene_doble_guardia(): void
    {
        $this->assertFalse(config('credential_flow.identidad.decisiones_enabled'));
        $this->assertFalse(config('credential_flow.identidad.multi_scope_enabled'));
        $this->assertFalse(IdentidadFlags::decisionesHabilitadas());
        config(['credential_flow.identidad.multi_scope_enabled' => true]);
        $this->assertFalse(IdentidadFlags::multiScopeHabilitado(), 'el interruptor de sesión SOLO no tiene efecto útil: exige también las decisiones');
        config(['credential_flow.identidad.decisiones_enabled' => true]);
        $this->assertTrue(IdentidadFlags::multiScopeHabilitado());
    }

    // ── Sin decisiones: delega exactamente en el gate histórico ──────────────

    public function test_sin_decisiones_el_resolver_es_identico_al_gate_historico_en_todos_los_documentos(): void
    {
        $this->encender();
        $acceso = new AccesoPortal;
        $docs = DB::table('cf_certificados_legado')->distinct()->pluck('documento_clave');
        $correos = DB::table('cf_correos')->where('estado', 'valido')->whereNotNull('certificado_legado_id')->pluck('correo_normalizado')->unique();
        $n = 0;
        foreach ($docs as $doc) {
            foreach ($correos as $c) {
                $al = $acceso->alcance((string) $doc, $c);
                $r = $this->resolver()->resolver((string) $doc, $c);
                $this->assertSame($al !== null, $r->habilita());
                if ($al !== null) {
                    $this->assertSame([ResultadoScope::HISTORICO_NORMAL, $al['grupo']], [$r->tipo, $r->grupoHistorico]);
                } else {
                    $this->assertSame(ResultadoScope::SIN_VIA, $r->tipo);
                }
                $n++;
            }
        }
        $this->assertGreaterThan(50, $n);
    }

    public function test_el_caso_de_identidad_del_fixture_sigue_sin_acceso_con_el_motor_encendido_y_sin_decisiones(): void
    {
        $this->encender();
        $caso = Conciliacion::where('tipo', 'identidad_ambigua')->firstOrFail();
        $this->assertNull((new AccesoPortal)->alcance('2000002', 'hugo@example.test'));
        $this->assertFalse($this->resolver()->resolver('2000002', 'hugo@example.test')->habilita());
        $this->post(route('portal.solicitar'), ['documento' => '2000002', 'correo' => 'hugo@example.test']);
        $this->assertTrue(Mail::sent(CodigoAccesoMail::class)->isEmpty());
        $this->assertSame('abierto', $caso->fresh()->estado);
    }

    // ── misma_persona (Modelo 1 acotado) ─────────────────────────────────────

    public function test_misma_persona_con_un_unico_correo_candidato_concede_el_scope_en_orden_canonico(): void
    {
        $d = $this->doc('1', ['ANA UNO' => ['correos' => ['ana@x.test']], 'ANA DOS' => ['correos' => ['ana@x.test']]]);
        $this->assertFalse($this->res($d, 'ana@x.test')->habilita(), 'sin decisión no hay vía');

        $dec = $this->misma($d, ['ANA DOS', 'ANA UNO']);
        $r = $this->res($d, 'ana@x.test');

        $this->assertSame(ResultadoScope::APROBADO, $r->tipo);
        $this->assertSame($this->grupos($d, ['ANA UNO', 'ANA DOS']), $r->grupos);
        $this->assertSame([$dec['decision_id']], $r->decisionIds);
        $this->assertNotNull($r->scopeHash);
        $this->assertSame($r->scopeHash, $this->res($d, 'ana@x.test')->scopeHash, 'resolver puro: mismo estado, mismo hash');
        // Orden canónico: [A,B] y [B,A] dan el mismo hash.
        $this->assertSame(
            ResultadoScope::hash('d', 'c', ['b', 'a'], [2, 1]),
            ResultadoScope::hash('d', 'c', ['a', 'b'], [1, 2])
        );
        $this->assertNotSame(ResultadoScope::hash('d', 'c', ['a', 'b'], [1]), ResultadoScope::hash('d', 'c2', ['a', 'b'], [1]));
        $this->assertSame(128 / 2, strlen($r->scopeHash));
        // Otro correo (no histórico) no entra.
        $this->assertFalse($this->res($d, 'otro@x.test')->habilita());
    }

    public function test_un_correo_que_escapa_del_scope_no_lo_abre_la_misma_persona_sola(): void
    {
        $d = $this->doc('2', ['MARIA GOMEZ A' => ['correos' => ['x@x.test']], 'MARIA GOMEZ B' => ['correos' => ['x@x.test']], 'MARIA GOMEZ C' => ['correos' => ['x@x.test']]]);
        $this->misma($d, ['MARIA GOMEZ A', 'MARIA GOMEZ B']);

        $r = $this->res($d, 'x@x.test');
        $this->assertSame([ResultadoScope::BLOQUEADO, 'correo_fuera_del_scope'], [$r->tipo, $r->motivoInterno]);

        // Con la autorización explícita del correo para A, abre A+B (la composición) y NUNCA C.
        $a = $this->autorizar($d, 'MARIA GOMEZ A', 'x@x.test');
        $r = $this->res($d, 'x@x.test');
        $this->assertSame(ResultadoScope::APROBADO, $r->tipo);
        $this->assertSame($this->grupos($d, ['MARIA GOMEZ A', 'MARIA GOMEZ B']), $r->grupos);
        $this->assertNotContains($d['g']['MARIA GOMEZ C'], $r->grupos);
        $this->assertCount(2, $r->decisionIds);
        $this->assertContains($a['decision_id'], $r->decisionIds);
    }

    public function test_un_scope_masivo_exige_ademas_correo_autorizado(): void
    {
        $d = $this->doc('3', ['CARLOS RUIZ UNO' => ['correos' => ['g@x.test'], 'n' => 100], 'CARLOS RUIZ DOS' => ['correos' => ['g@x.test']]]);
        $this->assertSame(100, EvidenciaIdentidad::de($d['caso'])['por_hash'][$d['g']['CARLOS RUIZ UNO']]['certificados']);
        $this->misma($d, ['CARLOS RUIZ UNO', 'CARLOS RUIZ DOS'], ['alcance_masivo' => true]);

        $r = $this->res($d, 'g@x.test');
        $this->assertSame([ResultadoScope::BLOQUEADO, 'scope_masivo_requiere_correo_autorizado'], [$r->tipo, $r->motivoInterno], 'la misma persona sola no concede vía');

        // 10B-3C-3: la autorización de un grupo masivo es una SOLICITUD (doble control); sin segunda aprobación no es aplicable.
        config(['credential_flow.identidad.decisiones_enabled' => true, 'credential_flow.identidad.multi_scope_enabled' => true, 'credential_flow.identidad.mass_scope_enabled' => true]);
        $a = $this->autorizar($d, 'CARLOS RUIZ UNO', 'g@x.test', ['alcance_masivo' => true, 'evidencia_externa' => true, 'fuente_evidencia' => 'propiedad_buzon']);
        $this->assertTrue($a['aprobacion_pendiente']);
        $r = $this->res($d, 'g@x.test');
        $this->assertFalse($r->esAprobado());
        $this->assertSame('pendiente_segunda_aprobacion', $r->motivoInterno);

        app(AutorizacionMasiva::class)->aprobar($a['decision_id'], $this->superAdmin()->id, 'Revisión de la segunda aprobación.', true);
        $r = $this->res($d, 'g@x.test');
        $this->assertSame(ResultadoScope::APROBADO, $r->tipo);
        // El scope masivo es SIEMPRE el grupo autorizado: la misma persona no lo amplía.
        $this->assertSame($this->grupos($d, ['CARLOS RUIZ UNO']), $r->grupos);
        $this->assertTrue($r->masivo && $r->aplicable());
    }

    public function test_varios_correos_candidatos_exigen_autorizacion_explicita_para_cada_uno(): void
    {
        $d = $this->doc('4', ['LUIS DIAZ M1' => ['correos' => ['m1@x.test', 'm2@x.test']], 'LUIS DIAZ M2' => ['correos' => ['m1@x.test', 'm2@x.test']]]);
        $this->misma($d, ['LUIS DIAZ M1', 'LUIS DIAZ M2']);
        foreach (['m1@x.test', 'm2@x.test'] as $c) {
            $r = $this->res($d, $c);
            $this->assertSame([ResultadoScope::BLOQUEADO, 'varios_candidatos_requieren_correo_autorizado'], [$r->tipo, $r->motivoInterno]);
        }

        $this->autorizar($d, 'LUIS DIAZ M1', 'm1@x.test');
        $this->assertSame(ResultadoScope::APROBADO, $this->res($d, 'm1@x.test')->tipo);
        $this->assertFalse($this->res($d, 'm2@x.test')->habilita(), 'solo los correos explícitamente autorizados producen scope');
    }

    public function test_la_pantalla_y_la_auditoria_usan_la_misma_regla_acotada(): void
    {
        $this->assertSame(['m1', 'm2'], ModeloAcotado::candidatos(['A' => ['m1', 'm2'], 'B' => ['m1', 'm2']], ['A', 'B']));
        $this->assertSame(['m2'], ModeloAcotado::candidatos(['A' => ['m1', 'm2'], 'B' => ['m2'], 'C' => ['m1']], ['A', 'B']), 'm1 también está en C: escapa');
        $d = $this->doc('5', ['SARA LOPEZ P1' => ['correos' => ['p@x.test']], 'SARA LOPEZ P2' => ['correos' => ['p@x.test']]]);
        $r = $this->misma($d, ['SARA LOPEZ P1', 'SARA LOPEZ P2']);
        $ev = DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $d['caso']->id)->where('accion', 'identidad_decision_creada')->first();
        $evid = json_decode($ev->evidencia, true);
        $this->assertSame([EvidenciaIdentidad::hashCorreo('p@x.test')], $evid['correos_admisibles']);
        $this->assertFalse($evid['requiere_correo_autorizado']);
        $this->assertStringNotContainsString('p@x.test', $ev->evidencia, 'solo HMAC en la auditoría');
        $this->assertGreaterThan(0, $r['decision_id']);

        $g = $this->doc('6', ['TOMAS VEGA Q1' => ['correos' => ['q1@x.test', 'q2@x.test']], 'TOMAS VEGA Q2' => ['correos' => ['q1@x.test', 'q2@x.test']]]);
        $this->misma($g, ['TOMAS VEGA Q1', 'TOMAS VEGA Q2']);
        $ev = json_decode(DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $g['caso']->id)->where('accion', 'identidad_decision_creada')->value('evidencia'), true);
        $this->assertTrue($ev['requiere_correo_autorizado']);

        $this->encender();
        $caso = $this->actingAs($this->admin())->get(route('credential-flow.historico.casos.show', $g['caso']->id))->viewData('page')['props']['caso'];
        $this->assertTrue($caso['identidad']['modelo_acotado']);
    }

    // ── personas_distintas ───────────────────────────────────────────────────

    public function test_personas_distintas_solo_restringe_y_cada_grupo_necesita_su_via_autorizada(): void
    {
        $d = $this->doc('7', ['ELSA MORA R1' => ['correos' => ['r@x.test']], 'ELSA MORA R2' => ['correos' => ['r@x.test']]]);
        $this->distintas($d, ['ELSA MORA R1', 'ELSA MORA R2']);

        $r = $this->res($d, 'r@x.test');
        $this->assertSame([ResultadoScope::BLOQUEADO, 'personas_distintas_y_correo_compartido'], [$r->tipo, $r->motivoInterno]);

        // Autorización explícita del correo compartido para UN grupo: abre solo ese grupo, nunca el otro.
        $this->autorizar($d, 'ELSA MORA R1', 'r@x.test');
        $r = $this->res($d, 'r@x.test');
        $this->assertSame(ResultadoScope::APROBADO, $r->tipo);
        $this->assertSame([$d['g']['ELSA MORA R1']], $r->grupos);
    }

    public function test_un_scope_con_mismo_evento_o_nombres_realmente_distintos_no_se_aplica_en_3b(): void
    {
        // Nombres realmente distintos con un correo compartido: aunque exista la decisión (con evidencia externa declarada), 3B no la aplica.
        $d = $this->doc('60', ['ALFREDO MONTOYA' => ['correos' => ['rr@x.test']], 'ZENAIDA QUINTERO' => ['correos' => ['rr@x.test']]]);
        $this->misma($d, ['ALFREDO MONTOYA', 'ZENAIDA QUINTERO']);
        $r = $this->res($d, 'rr@x.test');
        $this->assertSame([ResultadoScope::BLOQUEADO, 'riesgo_reforzado_pendiente_3c'], [$r->tipo, $r->motivoInterno]);
        $this->autorizar($d, 'ALFREDO MONTOYA', 'rr@x.test');
        $this->assertSame('riesgo_reforzado_pendiente_3c', $this->res($d, 'rr@x.test')->motivoInterno, 'la autorización de correo tampoco levanta el diferimiento a 3C');

        // Mismo evento (DIF_NOMBRE): los dos grupos tienen certificados en el MISMO evento.
        $e = $this->doc('61', ['SONIA VARGAS A' => ['correos' => ['ss@x.test']], 'SONIA VARGAS B' => ['correos' => ['ss@x.test']]]);
        DB::table('cf_certificados_legado')->where('documento_clave', $e['clave'])->update(['evento_id' => 1]);
        $this->misma($e, ['SONIA VARGAS A', 'SONIA VARGAS B']);
        $this->assertSame('riesgo_reforzado_pendiente_3c', $this->res($e, 'ss@x.test')->motivoInterno);
    }

    // ── Terminales ───────────────────────────────────────────────────────────

    public function test_las_terminales_vigentes_cierran_el_acceso_y_las_revocadas_no_cuentan(): void
    {
        $d = $this->doc('8', ['RAUL SOTO T1' => ['correos' => ['t@x.test']], 'RAUL SOTO T2' => ['correos' => ['t@x.test']]]);
        $s = $this->svc()->crear($d['caso']->id, $this->actorId(), 'requiere_soporte', self::MOTIVO);
        $this->assertSame([ResultadoScope::SOPORTE, 'decision_terminal_vigente'], [$this->res($d, 't@x.test')->tipo, $this->res($d, 't@x.test')->motivoInterno]);
        $this->svc()->revocar($s['decision_id'], $this->actorId(), 'Soporte ya respondió el caso.');
        $this->assertSame(ResultadoScope::SIN_VIA, $this->res($d, 't@x.test')->tipo, 'la terminal revocada no cuenta');

        $n = $this->svc()->crear($d['caso']->id, $this->actorId(), 'no_resoluble', self::MOTIVO);
        $this->assertSame(ResultadoScope::SOPORTE, $this->res($d, 't@x.test')->tipo);
        $this->svc()->revocar($n['decision_id'], $this->actorId(), 'Hay nueva evidencia del caso.');
        // Con una misma_persona vigente tras revocar la terminal: concede (las revocadas no bloquean).
        $this->misma($d, ['RAUL SOTO T1', 'RAUL SOTO T2']);
        $this->assertSame(ResultadoScope::APROBADO, $this->res($d, 't@x.test')->tipo);
    }

    public function test_una_terminal_vigente_gana_a_cualquier_concesion_aunque_la_base_este_corrompida(): void
    {
        $d = $this->doc('9', ['INES PAZ U1' => ['correos' => ['u@x.test']], 'INES PAZ U2' => ['correos' => ['u@x.test']]]);
        $this->misma($d, ['INES PAZ U1', 'INES PAZ U2']);
        DB::table('cf_decisiones_identidad')->insert(['conciliacion_id' => $d['caso']->id, 'documento_hash' => EvidenciaIdentidad::hashDocumento($d['clave']), 'tipo' => 'requiere_soporte', 'estado' => 'vigente', 'motivo' => 'x', 'creada_por' => 1,
            'vigente_clave' => str_repeat('a', 64), 'vigente_caso_clave' => $d['caso']->id, 'created_at' => now(), 'updated_at' => now()]);

        $r = $this->res($d, 'u@x.test');
        $this->assertSame([ResultadoScope::CONFLICTO, 'terminal_con_otras_decisiones'], [$r->tipo, $r->motivoInterno], 'la integridad (0) precede a la terminal (1)');
    }

    // ── Otro documento ───────────────────────────────────────────────────────

    public function test_un_correo_usado_en_otro_documento_no_trae_grupos_ajenos(): void
    {
        $d1 = $this->doc('10', ['OMAR LEON V1' => ['correos' => ['v@x.test']], 'OMAR LEON V2' => ['correos' => ['v@x.test']]]);
        $d2 = $this->doc('11', ['W UNO' => ['correos' => ['v@x.test']]]);
        $this->misma($d1, ['OMAR LEON V1', 'OMAR LEON V2']);

        $r = $this->res($d1, 'v@x.test');
        $this->assertSame($this->grupos($d1, ['OMAR LEON V1', 'OMAR LEON V2']), $r->grupos);
        $this->assertSame([], array_intersect($r->grupos, array_values($d2['g'])));
        $r2 = $this->res($d2, 'v@x.test');
        $this->assertSame(ResultadoScope::HISTORICO_NORMAL, $r2->tipo, 'el segundo documento sigue su gate histórico, sin decisiones del primero');
        $this->assertNull($r2->grupos);
    }

    // ── Corrupción: falla cerrada ────────────────────────────────────────────

    public function test_la_corrupcion_de_las_decisiones_falla_cerrada_y_no_emite_otp(): void
    {
        $this->encender();
        $casos = [
            'grupo_inexistente' => function ($d, $dec) {
                DB::table('cf_decisiones_identidad_grupos')->where('decision_id', $dec)->limit(1)->update(['grupo_hash' => str_repeat('f', 64)]);
            },
            'revocada_con_detalle_activo' => function ($d, $dec) {
                DB::table('cf_decisiones_identidad')->where('id', $dec)->update(['estado' => 'revocada', 'revocada_por' => 1, 'revocada_at' => now(), 'vigente_clave' => null]);
            },
            'caso_en_estado_incompatible' => function ($d, $dec) {
                DB::table('cf_conciliaciones')->where('id', $d['caso']->id)->update(['estado' => 'descartado']);
            },
            'vigente_con_datos_de_revocacion' => function ($d, $dec) {
                DB::table('cf_decisiones_identidad')->where('id', $dec)->update(['revocada_at' => now()]);
            },
            'grupos_insuficientes_o_con_correo' => function ($d, $dec) {
                DB::table('cf_decisiones_identidad_grupos')->where('decision_id', $dec)->limit(1)->delete();
            },
        ];
        $i = 20;
        foreach ($casos as $esperado => $corromper) {
            $i++;
            $correo = "z{$i}@x.test";
            $d = $this->doc((string) $i, ["Z{$i} UNO" => ['correos' => [$correo]], "Z{$i} DOS" => ['correos' => [$correo]]]);
            $dec = $this->misma($d, ["Z{$i} UNO", "Z{$i} DOS"])['decision_id'];
            $this->assertSame(ResultadoScope::APROBADO, $this->res($d, $correo)->tipo, $esperado.' (antes)');
            $corromper($d, $dec);
            $r = $this->res($d, $correo);
            $this->assertSame(ResultadoScope::CONFLICTO, $r->tipo, $esperado);
            $this->assertSame($esperado, $r->motivoInterno);
            Mail::fake();
            app(ServicioOtp::class)->solicitar($d['clave'], $correo, '10.0.0.1', 'ua');
            app(DeferredCallbackCollection::class)->invoke();
            $this->assertTrue(Mail::sent(CodigoAccesoMail::class)->isEmpty(), 'sin OTP: '.$esperado);
        }
    }

    public function test_correo_autorizado_inconsistente_o_dos_alcances_contradictorios_tambien_fallan_cerrado(): void
    {
        $d = $this->doc('40', ['LAURA RIOS K1' => ['correos' => ['k@x.test']], 'LAURA RIOS K2' => ['correos' => ['k@x.test']]]);
        $a = $this->autorizar($d, 'LAURA RIOS K1', 'k@x.test');
        DB::table('cf_decisiones_identidad_correos')->where('decision_id', $a['decision_id'])->update(['correo_hmac' => str_repeat('9', 64)]);
        $this->assertSame('correo_ausente_del_grupo', $this->res($d, 'k@x.test')->motivoInterno);

        $e = $this->doc('41', ['PABLO CRUZ L1' => ['correos' => ['l@x.test']], 'PABLO CRUZ L2' => ['correos' => ['l@x.test']]]);
        $this->misma($e, ['PABLO CRUZ L1', 'PABLO CRUZ L2']);
        $h = EvidenciaIdentidad::hashDocumento($e['clave']);
        $p = DB::table('cf_decisiones_identidad')->insertGetId(['conciliacion_id' => $e['caso']->id, 'documento_hash' => $h, 'tipo' => 'personas_distintas', 'estado' => 'vigente', 'motivo' => 'x', 'creada_por' => 1,
            'vigente_clave' => str_repeat('c', 64), 'created_at' => now(), 'updated_at' => now()]);
        foreach ($e['g'] as $g) {
            DB::table('cf_decisiones_identidad_grupos')->insert(['decision_id' => $p, 'documento_hash' => $h, 'grupo_hash' => $g, 'vigente_tipo' => 'personas_distintas', 'created_at' => now(), 'updated_at' => now()]);
        }
        $r = $this->res($e, 'l@x.test');
        $this->assertSame([ResultadoScope::CONFLICTO, 'misma_persona_contradice_personas_distintas'], [$r->tipo, $r->motivoInterno]);
    }

    // ── OTP con scope congelado ──────────────────────────────────────────────

    private function solicitar(array $d, string $correo, string $ip = '10.0.0.1'): ?string
    {
        Mail::fake();
        $this->assertNull(null);
        app(ServicioOtp::class)->solicitar($d['clave'], $correo, $ip, 'ua');
        app(DeferredCallbackCollection::class)->invoke();   // el envío se difiere a después de responder
        $m = Mail::sent(CodigoAccesoMail::class);

        return $m->isEmpty() ? null : $m->last()->codigo;
    }

    private function filaOtp(): ?object
    {
        return DB::table('cf_accesos_otp')->orderByDesc('id')->first();
    }

    public function test_con_el_interruptor_apagado_nada_cambia_y_el_resolver_ni_se_consulta(): void
    {
        $d = $this->doc('50', ['O UNO' => ['correos' => ['o@x.test']], 'O DOS' => ['correos' => ['o@x.test']]]);
        $this->misma($d, ['O UNO', 'O DOS']);
        $tablas = [];
        DB::listen(function ($q) use (&$tablas) {
            if (str_contains($q->sql, 'cf_decisiones_identidad')) {
                $tablas[] = $q->sql;
            }
        });

        $this->assertNull($this->solicitar($d, 'o@x.test'), 'con el interruptor apagado el documento ambiguo sigue sin OTP');
        $this->assertSame(0, DB::table('cf_accesos_otp')->count());
        $this->assertSame([], $tablas, 'ni siquiera se consultan las decisiones');
    }

    public function test_un_documento_normal_emite_otp_historico_sin_scope_con_el_motor_encendido(): void
    {
        $this->encender();
        $d = ['clave' => '2000001'];
        $codigo = $this->solicitar($d, 'gina@example.test');
        $this->assertNotNull($codigo);
        $f = $this->filaOtp();
        $this->assertSame([null, null, null], [$f->scope_hash, $f->scope_decisiones, $f->scope_grupos]);
        $this->assertSame(['grupo' => $f->grupo_hash, 'scope' => null], app(ServicioOtp::class)->validar('2000001', 'gina@example.test', $codigo));
    }

    public function test_un_scope_aprobado_emite_otp_con_el_scope_congelado_sin_pii(): void
    {
        $this->encender();
        $d = $this->doc('51', ['DIEGO ROJAS S1' => ['correos' => ['secreto@x.test']], 'DIEGO ROJAS S2' => ['correos' => ['secreto@x.test']]]);
        $dec = $this->misma($d, ['DIEGO ROJAS S1', 'DIEGO ROJAS S2']);

        $codigo = $this->solicitar($d, 'secreto@x.test');
        $this->assertNotNull($codigo);
        $f = $this->filaOtp();
        $r = $this->res($d, 'secreto@x.test');
        $this->assertSame([$r->scopeHash, [$dec['decision_id']], 2], [$f->scope_hash, json_decode($f->scope_decisiones, true), (int) $f->scope_grupos]);
        $this->assertSame($f->scope_hash, $f->grupo_hash, 'grupo_hash nunca queda NULL (= documento completo): lleva el scope_hash y no coincide con ningún grupo real');
        $this->assertNotContains($f->grupo_hash, array_values($d['g']));
        $this->assertStringNotContainsString('secreto@x.test', json_encode($f));
        $this->assertStringNotContainsString($d['clave'], json_encode($f));

        $v = app(ServicioOtp::class)->validar($d['clave'], 'secreto@x.test', $codigo);
        $this->assertSame($r->scopeHash, $v['scope']->scopeHash);
        $this->assertSame($this->grupos($d, ['DIEGO ROJAS S1', 'DIEGO ROJAS S2']), $v['scope']->grupos);
    }

    public function test_con_el_motor_encendido_y_la_sesion_multigrupo_apagada_un_otp_con_scope_no_abre_sesion(): void
    {
        $this->encender();
        config(['credential_flow.identidad.multi_scope_enabled' => false]);   // ON/OFF: el scope se prepara pero NO hay sesión multi-grupo
        $d = $this->doc('52', ['NORA PINTO E1' => ['correos' => ['e@x.test']], 'NORA PINTO E2' => ['correos' => ['e@x.test']]]);
        $this->misma($d, ['NORA PINTO E1', 'NORA PINTO E2']);

        $this->post(route('portal.solicitar'), ['documento' => $d['clave'], 'correo' => 'e@x.test'])->assertRedirect(route('portal.codigo'));
        $codigo = Mail::sent(CodigoAccesoMail::class)->last()->codigo;
        $this->post(route('portal.validar'), ['codigo' => $codigo])->assertRedirect(route('portal.codigo'))->assertSessionHas('error');
        $this->get(route('portal.panel'))->assertRedirect(route('portal.inicio'));
        $this->assertNull(session('cf_portal.contexto'));
    }

    public function test_revocar_entre_la_solicitud_y_la_validacion_no_da_acceso_e_invalida_el_desafio(): void
    {
        $this->encender();
        $d = $this->doc('53', ['HUGO MESA G1' => ['correos' => ['g@x.test']], 'HUGO MESA G2' => ['correos' => ['g@x.test']]]);
        $dec = $this->misma($d, ['HUGO MESA G1', 'HUGO MESA G2'])['decision_id'];
        $codigo = $this->solicitar($d, 'g@x.test');
        $this->assertNotNull($codigo);

        $this->svc()->revocar($dec, $this->actorId(), 'Se retira la unión por nueva información.');

        $this->assertNull(app(ServicioOtp::class)->validar($d['clave'], 'g@x.test', $codigo));
        $f = $this->filaOtp();
        $this->assertNull($f->usado_at);
        $this->assertNotNull($f->invalidado_at);
        $this->assertSame(1, (int) $f->intentos, 'cuenta como intento');
        // El mismo código no sirve después, aunque se vuelva a decidir lo mismo.
        $this->misma($d, ['HUGO MESA G1', 'HUGO MESA G2']);
        $this->assertNull(app(ServicioOtp::class)->validar($d['clave'], 'g@x.test', $codigo));
    }

    public function test_revocar_una_decision_y_crear_otra_distinta_invalida_el_otp_emitido(): void
    {
        $this->encender();
        $d = $this->doc('54', ['ANA TORO H1' => ['correos' => ['h@x.test']], 'ANA TORO H2' => ['correos' => ['h@x.test']], 'ANA TORO H3' => ['correos' => ['h3@x.test']]]);
        $a = $this->misma($d, ['ANA TORO H1', 'ANA TORO H2'])['decision_id'];
        $codigo = $this->solicitar($d, 'h@x.test');
        $this->svc()->revocar($a, $this->actorId(), 'Se corrige la decisión por nueva información.');
        $this->misma($d, ['ANA TORO H1', 'ANA TORO H2']);   // nueva decisión con OTRO id

        $this->assertNull(app(ServicioOtp::class)->validar($d['clave'], 'h@x.test', $codigo), 'el OTP emitido con la decisión A falla aunque el scope textual sea el mismo');
    }

    public function test_una_decision_creada_despues_del_otp_no_amplia_un_desafio_historico(): void
    {
        $this->encender();
        // Documento con correo EXCLUSIVO en A (gate histórico: abre solo A).
        $d = $this->doc('55', ['RITA SOLER I1' => ['correos' => ['i@x.test']], 'RITA SOLER I2' => ['correos' => ['i2@x.test']]]);
        $codigo = $this->solicitar($d, 'i@x.test');
        $this->assertNotNull($codigo);
        $this->assertNull($this->filaOtp()->scope_hash);

        $this->misma($d, ['RITA SOLER I1', 'RITA SOLER I2']);   // decisión posterior
        $v = app(ServicioOtp::class)->validar($d['clave'], 'i@x.test', $codigo);

        $this->assertNull($v['scope'], 'el OTP conserva el alcance del momento de la solicitud');
        $this->assertSame(NombreConservador::grupoId('RITA SOLER I1'), $v['grupo']);
    }

    public function test_apagar_el_interruptor_despues_de_emitir_invalida_el_otp_con_scope(): void
    {
        $this->encender();
        $d = $this->doc('56', ['LUCIA BOTERO J1' => ['correos' => ['j@x.test']], 'LUCIA BOTERO J2' => ['correos' => ['j@x.test']]]);
        $this->misma($d, ['LUCIA BOTERO J1', 'LUCIA BOTERO J2']);
        $codigo = $this->solicitar($d, 'j@x.test');

        $this->encender(false);
        $this->assertNull(app(ServicioOtp::class)->validar($d['clave'], 'j@x.test', $codigo));
        $this->assertNotNull($this->filaOtp()->invalidado_at);
    }

    public function test_los_limites_del_otp_no_dependen_del_scope(): void
    {
        $this->encender();
        $d = $this->doc('57', ['MARTA NIETO N1' => ['correos' => ['n@x.test']], 'MARTA NIETO N2' => ['correos' => ['n@x.test']]]);
        $this->misma($d, ['MARTA NIETO N1', 'MARTA NIETO N2']);

        $this->assertNotNull($this->solicitar($d, 'n@x.test'));
        $this->assertNull($this->solicitar($d, 'n@x.test', '10.9.9.9'), '60 segundos entre envíos por documento+correo, con otra IP también');
        $this->assertSame(1, DB::table('cf_accesos_otp')->count());
        // Intentos fallidos: el contador y el bloqueo por 5 siguen siendo los de siempre.
        for ($i = 0; $i < 5; $i++) {
            app(ServicioOtp::class)->validar($d['clave'], 'n@x.test', '000000');
        }
        $this->assertNotNull($this->filaOtp()->bloqueado_at);
    }

    public function test_la_canonicalizacion_del_correo_es_la_de_correo_normalizar(): void
    {
        $this->encender();
        $d = $this->doc('58', ['IVAN ORTIZ C1' => ['correos' => ['mayus@x.test']], 'IVAN ORTIZ C2' => ['correos' => ['mayus@x.test']]]);
        $this->misma($d, ['IVAN ORTIZ C1', 'IVAN ORTIZ C2']);
        $this->assertNotNull($this->solicitar($d, '  MAYUS@X.Test '), 'el portal normaliza antes de resolver');
        $this->assertSame(AccesoPortal::correoNormalizado('  MAYUS@X.Test '), 'mayus@x.test');
        // Todos los correos válidos de la base ya son canónicos.
        foreach (DB::table('cf_correos')->where('estado', 'valido')->pluck('correo_normalizado') as $c) {
            $this->assertSame($c, AccesoPortal::correoNormalizado($c));
        }
    }

    public function test_el_documento_invalido_nunca_llega_al_resolver(): void
    {
        $this->encender();
        $this->assertNull(AccesoPortal::claveDocumento(''));
        $this->assertNull($this->solicitar(['clave' => ''], 'cualquiera@x.test'));
        $this->assertSame(0, DB::table('cf_accesos_otp')->count());
    }

    // ── Rendimiento razonable y regresión de OTP ─────────────────────────────

    public function test_el_resolver_con_decisiones_hace_un_numero_acotado_de_consultas(): void
    {
        $d = $this->doc('59', ['JOSE ARIAS X1' => ['correos' => ['xx@x.test']], 'JOSE ARIAS X2' => ['correos' => ['xx@x.test']]]);
        $this->misma($d, ['JOSE ARIAS X1', 'JOSE ARIAS X2']);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->res($d, 'xx@x.test');
        $this->assertLessThanOrEqual(14, count(DB::getQueryLog()));
        DB::disableQueryLog();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->resolver()->resolver('2000001', 'gina@example.test');
        $this->assertLessThanOrEqual(8, count(DB::getQueryLog()), 'sin decisiones: 3 consultas de decisiones + el gate histórico');
    }

    public function test_hmac_de_los_dominios_son_distintos_pero_parten_del_mismo_valor_canonico(): void
    {
        $this->assertNotSame(ServicioOtp::hashCorreo('a@x.test'), EvidenciaIdentidad::hashCorreo('a@x.test'));
        $this->assertNotSame(ServicioOtp::hashDocumento('12345'), EvidenciaIdentidad::hashDocumento('12345'));
        $this->assertTrue(Hash::check('x', Hash::make('x')));
    }
}
