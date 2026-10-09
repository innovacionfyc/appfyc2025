<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Models\CredentialFlow\Conciliacion;
use App\Models\CredentialFlow\DecisionIdentidadAprobacion;
use App\Models\Usuario;
use App\Support\CredentialFlow\Conciliaciones\ConsultaConciliaciones;
use App\Support\CredentialFlow\Conciliaciones\DetectorConciliaciones;
use App\Support\CredentialFlow\Conciliaciones\ResolucionNoPermitida;
use App\Support\CredentialFlow\Identidad\AutorizacionMasiva;
use App\Support\CredentialFlow\Identidad\DecisionesIdentidad;
use App\Support\CredentialFlow\Identidad\EvidenciaIdentidad;
use App\Support\CredentialFlow\Identidad\IdentidadFlags;
use App\Support\CredentialFlow\Identidad\ModeloAcotado;
use App\Support\CredentialFlow\Identidad\ResultadoScope;
use App\Support\CredentialFlow\Identidad\ScopeIdentidadAprobado;
use App\Support\CredentialFlow\Portal\NombreConservador;
use App\Support\CredentialFlow\Portal\SesionPortal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

/**
 * Fase 10B-3C-3: AUTORIZACIÓN MASIVA con DOBLE CONTROL. Réplica a pequeña escala del documento real de 624 certificados: el grupo grande (MARIA GOMEZ, 101
 * certificados, 8 de ellos pendientes de plantilla) y la fila suelta (MARIO GOMEZ, otro evento) comparten el único correo. La política es `correo_autorizado`
 * AL GRUPO GRANDE (scope de 1 grupo), con evidencia externa, confirmación masiva, segundo administrador y el interruptor masivo; la fila suelta NUNCA entra.
 */
class AutorizacionMasivaTest extends PortalTestCase
{
    private const DOC = '3000002';

    private const CORREO_X = 'x@example.test';

    private const MOTIVO = 'Decisión administrativa de prueba.';

    private const EVID = 'Certificación del organizador del evento sobre la propiedad del buzón.';

    private int $caso = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // El grupo grande: 100 copias de María (101 en total), 8 pendientes de plantilla; la fila suelta (Mario, cert 41) queda aparte.
        $molde = (array) DB::table('cf_certificados_legado')->where('id', $this->idCert(40))->first();
        unset($molde['id']);
        $this->idC = DB::table('cf_certificados_legado')->insertGetId(['nombre_completo' => 'MARIA GOMEZ C', 'evento_id' => 3, 'codigo_legado' => null, 'conciliacion_estado' => 'ok'] + $molde);
        DB::table('cf_correos')->insert(['certificado_legado_id' => $this->idC, 'correo' => 'c@example.test', 'correo_normalizado' => 'c@example.test', 'estado' => 'valido', 'orden' => 1, 'es_principal' => 1, 'origen' => 'legado', 'created_at' => now(), 'updated_at' => now()]);
        app(DetectorConciliaciones::class)->ejecutar();
        $this->caso = $this->crearCaso();
        $this->inflar(100, 8);
    }

    private int $idC = 0;

    private function crearCaso(): int
    {
        $caso = Conciliacion::where('referencia_clave', EvidenciaIdentidad::hashDocumento(self::DOC))->first()
            ?? Conciliacion::create(['tipo' => 'identidad_ambigua', 'estado' => 'abierto', 'referencia_tipo' => 'documento', 'referencia_clave' => EvidenciaIdentidad::hashDocumento(self::DOC), 'motivo_origen' => 'CORREO_CRUZA_GRUPOS', 'clave_idempotencia' => 'identidad_ambigua:documento:masivo3c3']);
        if (DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $caso->id)->doesntExist()) {
            foreach (DB::table('cf_certificados_legado')->where('documento_clave', self::DOC)->orderBy('id')->get(['id']) as $i => $c) {
                DB::table('cf_conciliaciones_certificados')->insert(['conciliacion_id' => $caso->id, 'certificado_legado_id' => $c->id, 'rol' => 'grupo_'.($i + 1), 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        return (int) $caso->id;
    }

    /** Añade `$n` copias de María (mismo grupo) y deja `$pendientes` de ellas pendientes de plantilla. */
    private function inflar(int $n, int $pendientes): void
    {
        $molde = (array) DB::table('cf_certificados_legado')->where('id', $this->idCert(40))->first();
        unset($molde['id']);
        $filas = [];
        for ($i = 0; $i < $n; $i++) {
            $id = DB::table('cf_certificados_legado')->insertGetId($i < $pendientes ? ['conciliacion_estado' => 'pendiente_plantilla'] + $molde : $molde);
            $filas[] = ['conciliacion_id' => $this->caso, 'certificado_legado_id' => $id, 'rol' => 'grupo_1', 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('cf_conciliaciones_certificados')->insert($filas);
    }

    private function flags(bool $decisiones = true, bool $multi = true, bool $masa = true): void
    {
        config(['credential_flow.identidad.decisiones_enabled' => $decisiones, 'credential_flow.identidad.multi_scope_enabled' => $multi, 'credential_flow.identidad.mass_scope_enabled' => $masa]);
    }

    private function g(string $nombre): string
    {
        return NombreConservador::grupoId($nombre);
    }

    private function a(): int
    {
        return $this->admin()->id;
    }

    private function b(): int
    {
        return $this->superAdmin()->id;
    }

    private function svc(): DecisionesIdentidad
    {
        return app(DecisionesIdentidad::class);
    }

    private function masiva(): AutorizacionMasiva
    {
        return app(AutorizacionMasiva::class);
    }

    private function caso(): Conciliacion
    {
        return Conciliacion::findOrFail($this->caso);
    }

    private function params(array $extra = []): array
    {
        return array_merge(['grupo' => $this->g('MARIA GOMEZ'), 'correo_hmac' => EvidenciaIdentidad::hashCorreo(self::CORREO_X), 'evidencia' => self::EVID, 'reforzada' => true,
            'alcance_masivo' => true, 'evidencia_externa' => true, 'fuente_evidencia' => 'certificacion_organizador'], $extra);
    }

    /** ADMIN A solicita la autorización masiva. @return array{decision_id:int,creada:bool,aprobacion_pendiente?:bool} */
    private function solicitar3(array $extra = []): array
    {
        return $this->svc()->crear($this->caso, $this->a(), 'correo_autorizado', self::MOTIVO, $this->params($extra));
    }

    private function aprobar(int $decision, ?int $actor = null, bool $confirmo = true): array
    {
        return $this->masiva()->aprobar($decision, $actor ?? $this->b(), 'Revisé el blast radius, el grupo, la fuente y la evidencia.', $confirmo);
    }

    private function rechazo(callable $f): ResolucionNoPermitida
    {
        try {
            $f();
        } catch (ResolucionNoPermitida $e) {
            return $e;
        }
        $this->fail('Debía rechazarse.');
    }

    private function resolver(string $correo = self::CORREO_X): ResultadoScope
    {
        return app(ScopeIdentidadAprobado::class)->resolver(self::DOC, $correo);
    }

    private function ingresar(string $correo = self::CORREO_X): TestResponse
    {
        Mail::fake();
        DB::table('cf_accesos_otp')->delete();
        $this->post(route('portal.solicitar'), ['documento' => self::DOC, 'correo' => $correo])->assertRedirect(route('portal.codigo'));
        $codigo = $this->ultimoCodigo();
        $this->assertNotNull($codigo, 'se esperaba un OTP');

        return $this->validarCodigo($codigo);
    }

    private function hayOtp(string $correo = self::CORREO_X): bool
    {
        Mail::fake();
        DB::table('cf_accesos_otp')->delete();
        $this->post(route('portal.solicitar'), ['documento' => self::DOC, 'correo' => $correo]);

        return $this->ultimoCodigo() !== null;
    }

    /** @return list<string> */
    private function tipos(TestResponse $r): array
    {
        preg_match_all('/data-certificado="([a-z_]+)"/', $r->getContent(), $m);

        return $m[1];
    }

    // ── Flag, umbral y esquema ───────────────────────────────────────────────

    public function test_el_interruptor_masivo_esta_apagado_por_defecto_y_es_independiente(): void
    {
        $this->assertFalse(config('credential_flow.identidad.mass_scope_enabled'));
        $this->assertFalse(IdentidadFlags::masaHabilitada());
        $this->flags(true, true, false);
        $this->assertTrue(IdentidadFlags::multiScopeHabilitado());
        $this->assertFalse(IdentidadFlags::masaHabilitada());
        // Hacen falta LOS TRES.
        foreach ([[false, true, true], [true, false, true], [true, true, false]] as [$d, $m, $x]) {
            $this->flags($d, $m, $x);
            $this->assertFalse(IdentidadFlags::masaHabilitada());
        }
        $this->flags(true, true, true);
        $this->assertTrue(IdentidadFlags::masaHabilitada());
        config()->offsetUnset('credential_flow.identidad.mass_scope_enabled');
        $this->assertFalse(IdentidadFlags::masaHabilitada(), 'sin config: apagado');
    }

    public function test_el_umbral_masivo_esta_centralizado(): void
    {
        $this->assertSame(100, EvidenciaIdentidad::UMBRAL_MASIVO);
        $this->assertSame(EvidenciaIdentidad::UMBRAL_MASIVO, ModeloAcotado::UMBRAL_MASIVO);
        // Ningún otro servicio de identidad escribe el número 100 en su código.
        foreach (glob(base_path('app/Support/CredentialFlow/Identidad/*.php')) as $f) {
            if (str_ends_with($f, 'EvidenciaIdentidad.php')) {
                continue;
            }
            foreach (token_get_all((string) file_get_contents($f)) as $t) {
                $this->assertFalse(is_array($t) && $t[0] === T_LNUMBER && $t[1] === '100', basename($f).' no debe duplicar el umbral');
            }
        }
    }

    public function test_la_tabla_de_aprobaciones_garantiza_una_aprobacion_por_decision_y_un_aprobador_distinto(): void
    {
        $this->flags();
        $d = $this->solicitar3()['decision_id'];
        $fila = ['decision_id' => $d, 'documento_hash' => 'x', 'estado' => 'pendiente', 'fuente_evidencia' => 'otra', 'certificados' => 1, 'logicos' => 1, 'eventos' => 1, 'descargables' => 1,
            'fuera_de_alcance' => 0, 'solicitada_por' => 1, 'created_at' => now(), 'updated_at' => now()];
        // UNIQUE(decision_id): nunca dos aprobaciones para la misma decisión.
        try {
            DB::table('cf_decisiones_identidad_aprobaciones')->insert($fila);
            $this->fail('El UNIQUE de decision_id debía impedir la segunda fila.');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('cf_decisiones_identidad_aprobaciones')->count());
        }
        // El modelo rechaza un aprobador igual al solicitante y el borrado (la fila solo se revoca).
        $ap = DecisionIdentidadAprobacion::where('decision_id', $d)->firstOrFail();
        try {
            $ap->update(['aprobada_por' => $ap->solicitada_por, 'estado' => 'aprobada']);
            $this->fail('El aprobador debía ser distinto.');
        } catch (\InvalidArgumentException) {
            $this->assertSame('pendiente', $ap->fresh()->estado);
        }
        $this->expectException(\LogicException::class);
        $ap->delete();
    }

    // ── Solicitud (ADMIN A) ──────────────────────────────────────────────────

    public function test_la_solicitud_masiva_exige_confirmacion_evidencia_externa_fuente_y_texto(): void
    {
        $this->flags();
        $antes = DB::table('cf_decisiones_identidad')->count();
        $casos = [
            [['alcance_masivo' => false], ResolucionNoPermitida::CONFIRMACION_REQUERIDA],
            [['evidencia_externa' => false], ResolucionNoPermitida::EVIDENCIA_REQUERIDA],
            [['fuente_evidencia' => null], ResolucionNoPermitida::FUENTE_EVIDENCIA_REQUERIDA],
            [['fuente_evidencia' => 'inventada'], ResolucionNoPermitida::FUENTE_EVIDENCIA_REQUERIDA],
            [['evidencia' => null], ResolucionNoPermitida::EVIDENCIA_REQUERIDA],
            [['evidencia' => 'corta'], ResolucionNoPermitida::EVIDENCIA_REQUERIDA],
            [['evidencia' => str_repeat('x', 1001)], ResolucionNoPermitida::EVIDENCIA_REQUERIDA],
        ];
        foreach ($casos as [$cambio, $codigo]) {
            $e = $this->rechazo(fn () => $this->solicitar3($cambio));
            $this->assertSame($codigo, $e->codigo, json_encode($cambio));
        }
        $this->assertSame($antes, DB::table('cf_decisiones_identidad')->count());
        $this->assertSame(0, DB::table('cf_decisiones_identidad_aprobaciones')->count());
        foreach (DecisionIdentidadAprobacion::FUENTES as $fuente) {
            $this->assertNotEmpty($fuente);
        }
    }

    public function test_la_solicitud_deja_una_decision_vigente_pero_n_o_aplicable(): void
    {
        $this->flags();
        $r = $this->solicitar3();
        $this->assertTrue($r['creada'] && $r['aprobacion_pendiente']);
        $ap = DecisionIdentidadAprobacion::where('decision_id', $r['decision_id'])->firstOrFail();
        $this->assertSame(['pendiente', $this->a(), null, 'certificacion_organizador'], [$ap->estado, (int) $ap->solicitada_por, $ap->aprobada_por, $ap->fuente_evidencia]);
        // Blast radius congelado (solo conteos).
        $this->assertSame([101, 101, 93], [(int) $ap->certificados, (int) $ap->logicos, (int) $ap->descargables]);
        $this->assertSame(2 + 0, (int) $ap->fuera_de_alcance - 0, 'la fila suelta y el grupo C quedan fuera');

        $res = $this->resolver();
        $this->assertFalse($res->esAprobado());
        $this->assertSame('pendiente_segunda_aprobacion', $res->motivoInterno);
        $this->assertFalse($this->hayOtp(), 'sin segunda aprobación no hay OTP por esta vía');
        $c = $this->caso();
        $this->assertSame(['abierto', null], [$c->estado, $c->resolucion], 'el caso NO queda identidad_aplicada');
        $this->assertNull(session('cf_portal.contexto'));
        // Auditoría: solicitada (sin PII).
        $ev = DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $this->caso)->where('accion', AutorizacionMasiva::ACCION_SOLICITADA)->first();
        $this->assertNotNull($ev);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', json_decode($ev->evidencia, true)['grupo']);
        $this->assertSame(1, DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $this->caso)->where('accion', 'identidad_decision_creada')->count());
    }

    public function test_un_grupo_pequeno_no_usa_el_doble_control(): void
    {
        $this->flags();
        $r = $this->svc()->crear($this->caso, $this->a(), 'correo_autorizado', self::MOTIVO, ['grupo' => $this->g('MARIO GOMEZ'), 'correo_hmac' => EvidenciaIdentidad::hashCorreo(self::CORREO_X), 'evidencia' => self::EVID, 'reforzada' => true]);
        $this->assertFalse($r['aprobacion_pendiente']);
        $this->assertSame(0, DB::table('cf_decisiones_identidad_aprobaciones')->count());
        $res = $this->resolver();
        $this->assertTrue($res->aplicable() && ! $res->masivo, '3C-1: un scope pequeño aplica sin segunda aprobación');
        $this->assertSame([$this->g('MARIO GOMEZ')], $res->grupos);
    }

    // ── Segunda aprobación (ADMIN B) ─────────────────────────────────────────

    public function test_el_mismo_actor_no_puede_aprobar_su_propia_solicitud(): void
    {
        $this->flags();
        $d = $this->solicitar3()['decision_id'];
        $e = $this->rechazo(fn () => $this->aprobar($d, $this->a()));
        $this->assertSame(ResolucionNoPermitida::SEGUNDO_APROBADOR_REQUERIDO, $e->codigo);
        $this->assertSame('pendiente', DecisionIdentidadAprobacion::where('decision_id', $d)->value('estado'));
        $this->assertFalse($this->resolver()->esAprobado());
    }

    public function test_solo_un_administrador_y_con_confirmacion_reforzada_y_motivo(): void
    {
        $this->flags();
        $d = $this->solicitar3()['decision_id'];
        $this->assertSame(ResolucionNoPermitida::ACTOR_NO_AUTORIZADO, $this->rechazo(fn () => $this->aprobar($d, $this->comercial()->id))->codigo);
        $this->assertSame(ResolucionNoPermitida::CONFIRMACION_REQUERIDA, $this->rechazo(fn () => $this->aprobar($d, null, false))->codigo);
        $this->assertSame(ResolucionNoPermitida::TIPO_NO_ADMITIDO, $this->rechazo(fn () => $this->masiva()->aprobar($d, $this->b(), 'x', true))->codigo, 'motivo mínimo');
        $this->assertSame('pendiente', DecisionIdentidadAprobacion::where('decision_id', $d)->value('estado'));
    }

    public function test_aprobar_dos_veces_o_una_decision_pequena_o_inexistente_se_rechaza(): void
    {
        $this->flags();
        $d = $this->solicitar3()['decision_id'];
        $this->aprobar($d);
        $this->assertSame(ResolucionNoPermitida::YA_APROBADA, $this->rechazo(fn () => $this->aprobar($d, $this->a()))->codigo, 'ya aprobada manda sobre el resto');
        $this->assertSame(ResolucionNoPermitida::YA_APROBADA, $this->rechazo(fn () => $this->aprobar($d))->codigo);
        $this->assertSame(1, DecisionIdentidadAprobacion::where('decision_id', $d)->where('estado', 'aprobada')->count());

        // Un correo autoriza un solo grupo: se retira la masiva para probar con una decisión pequeña (sin segunda aprobación que dar).
        $this->svc()->revocar($d, $this->a(), 'Se retira la decisión por la prueba.');
        $peq = $this->svc()->crear($this->caso, $this->a(), 'correo_autorizado', self::MOTIVO, ['grupo' => $this->g('MARIO GOMEZ'), 'correo_hmac' => EvidenciaIdentidad::hashCorreo(self::CORREO_X), 'evidencia' => self::EVID, 'reforzada' => true])['decision_id'];
        $this->assertSame(ResolucionNoPermitida::APROBACION_NO_APLICA, $this->rechazo(fn () => $this->aprobar($peq))->codigo);
        $this->assertSame(ResolucionNoPermitida::TIPO_NO_ADMITIDO, $this->rechazo(fn () => $this->aprobar(999999))->codigo);
    }

    public function test_si_el_blast_radius_cambio_desde_la_solicitud_hay_que_volver_a_solicitar(): void
    {
        $this->flags();
        $d = $this->solicitar3()['decision_id'];
        $this->inflar(5, 0);   // 5 certificados más en el grupo autorizado
        $this->assertSame(ResolucionNoPermitida::CERTIFICADOS_CAMBIARON, $this->rechazo(fn () => $this->aprobar($d))->codigo);
        $this->assertSame('pendiente', DecisionIdentidadAprobacion::where('decision_id', $d)->value('estado'));
    }

    public function test_una_decision_revocada_no_se_puede_aprobar(): void
    {
        $this->flags();
        $d = $this->solicitar3()['decision_id'];
        $this->svc()->revocar($d, $this->a(), 'Se retira la solicitud por la prueba.');
        $this->assertSame('revocada', DecisionIdentidadAprobacion::where('decision_id', $d)->value('estado'));
        $this->assertSame(ResolucionNoPermitida::DECISION_YA_REVOCADA, $this->rechazo(fn () => $this->aprobar($d))->codigo);
    }

    // ── Resolver, OTP, sesión, panel, descarga ───────────────────────────────

    public function test_aprobada_el_resolver_concede_un_scope_de_u_n_grupo_y_el_caso_se_cierra(): void
    {
        $this->flags();
        $d = $this->solicitar3()['decision_id'];
        $r = $this->aprobar($d);
        $this->assertSame('resuelto', $r['caso_estado']);
        $c = $this->caso();
        $this->assertSame(['resuelto', 'identidad_aplicada'], [$c->estado, $c->resolucion]);

        $res = $this->resolver();
        $this->assertTrue($res->esAprobado() && $res->masivo && $res->aplicable());
        $this->assertSame([$this->g('MARIA GOMEZ')], $res->grupos, 'el scope es el grupo grande y nada más');
        $this->assertNotContains($this->g('MARIO GOMEZ'), $res->grupos);
        $this->assertCount(1, $res->aprobacionIds);
        $this->assertSame(ResultadoScope::hash(EvidenciaIdentidad::hashDocumento(self::DOC), EvidenciaIdentidad::hashCorreo(self::CORREO_X), $res->grupos, $res->decisionIds, $res->aprobacionIds), $res->scopeHash);
        $this->assertNotSame(ResultadoScope::hash(EvidenciaIdentidad::hashDocumento(self::DOC), EvidenciaIdentidad::hashCorreo(self::CORREO_X), $res->grupos, $res->decisionIds), $res->scopeHash, 'la aprobación forma parte del contrato');
        // Eventos de auditoría, sin PII, y el cierre reutiliza el evento de 3B-2.
        foreach ([AutorizacionMasiva::ACCION_SOLICITADA, AutorizacionMasiva::ACCION_APROBADA, 'identidad_acceso_aplicado', 'identidad_decision_creada'] as $accion) {
            $this->assertSame(1, DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $this->caso)->where('accion', $accion)->count(), $accion);
        }
        $txt = DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $this->caso)->get()->map(fn ($e) => $e->evidencia.$e->motivo)->implode(' ');
        $this->assertStringNotContainsString('@', $txt);
        $this->assertStringNotContainsString('GOMEZ', $txt);
        $this->assertStringNotContainsString(self::DOC, $txt);
    }

    public function test_con_los_tres_interruptores_hay_otp_sesion_panel_y_descarga_solo_del_grupo_grande(): void
    {
        $this->flags();
        $this->aprobar($this->solicitar3()['decision_id']);
        $this->ingresar()->assertRedirect(route('portal.panel'));

        $ctx = session('cf_portal.contexto');
        $this->assertSame([$this->g('MARIA GOMEZ')], $ctx['grupos']);
        $this->assertCount(1, $ctx['aprobaciones']);

        $tipos = $this->tipos($this->get(route('portal.panel'))->assertOk());
        $this->assertCount(101, $tipos, 'el grupo grande completo (100 copias + el original)');
        $this->assertSame(93, count(array_filter($tipos, fn ($t) => $t === 'disponible')), 'los 93 descargables');
        $this->assertSame(8, count(array_filter($tipos, fn ($t) => $t !== 'disponible')), 'los 8 pendientes de plantilla aparecen como revisión, sin PDF inventado');

        $this->get(route('portal.descargar', $this->idCert(40)))->assertOk();
        // ATAQUE DIRECTO: con la sesión del grupo grande, la fila suelta y el otro grupo → 404 uniforme.
        $this->get(route('portal.descargar', $this->idCert(41)))->assertNotFound();
        $this->get(route('portal.descargar', $this->idC))->assertNotFound();
        $this->assertStringNotContainsString('MARIO', $this->get(route('portal.panel'))->getContent());
    }

    public function test_con_el_interruptor_masivo_apagado_no_hay_otp_ni_cierre_pero_todo_se_conserva(): void
    {
        $this->flags(true, true, false);
        $d = $this->solicitar3()['decision_id'];
        $this->aprobar($d);
        $res = $this->resolver();
        $this->assertFalse($res->esAprobado());
        $this->assertSame('mass_scope_apagado', $res->motivoInterno);
        $this->assertFalse($this->hayOtp());
        $c = $this->caso();
        $this->assertNotSame('identidad_aplicada', $c->resolucion);
        $this->assertSame(['vigente', 'aprobada'], [DB::table('cf_decisiones_identidad')->where('id', $d)->value('estado'), DecisionIdentidadAprobacion::where('decision_id', $d)->value('estado')]);

        // Encenderlo después habilita sin repetir nada, y la aprobación ya existente cierra el caso en la siguiente decisión/aplicación.
        $this->flags();
        $this->assertTrue($this->hayOtp());
    }

    public function test_apagar_el_interruptor_masivo_con_una_sesion_viva_la_mata_en_la_siguiente_peticion(): void
    {
        $this->flags();
        $this->aprobar($this->solicitar3()['decision_id']);
        $this->ingresar();
        $this->get(route('portal.panel'))->assertOk();

        $this->flags(true, true, false);

        $this->get(route('portal.panel'))->assertRedirect(route('portal.inicio'))->assertSessionHas('aviso', SesionPortal::MENSAJE_EXPIRADO);
        $this->get(route('portal.descargar', $this->idCert(40)))->assertRedirect(route('portal.inicio'));
        $this->flags();
        $this->get(route('portal.panel'))->assertRedirect(route('portal.inicio'));   // la sesión ya murió: no resucita al encender el interruptor
    }

    public function test_revocar_solo_la_aprobacion_mata_la_sesion_reabre_el_caso_y_conserva_la_decision(): void
    {
        $this->flags();
        $d = $this->solicitar3()['decision_id'];
        $this->aprobar($d);
        $this->ingresar();
        $this->get(route('portal.panel'))->assertOk();

        $r = $this->masiva()->revocarAprobacion($d, $this->b(), 'Se retira la segunda aprobación por la prueba.');

        $this->assertSame('abierto', $r['caso_estado']);
        $this->get(route('portal.panel'))->assertRedirect(route('portal.inicio'));
        $this->assertSame('vigente', DB::table('cf_decisiones_identidad')->where('id', $d)->value('estado'), 'la decisión se conserva');
        $ap = DecisionIdentidadAprobacion::where('decision_id', $d)->first();
        $this->assertSame(['revocada', $this->b()], [$ap->estado, (int) $ap->revocada_por]);
        $this->assertSame($this->b(), (int) $ap->aprobada_por, 'la aprobación queda como historia');
        $this->assertFalse($this->hayOtp());
        $this->assertSame(1, DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $this->caso)->where('accion', AutorizacionMasiva::ACCION_REVOCADA)->count());
        $this->assertSame(1, DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $this->caso)->where('accion', 'identidad_acceso_revocado')->count());
        // No se puede re-aprobar la misma: hay que solicitar de nuevo.
        $this->assertSame(ResolucionNoPermitida::APROBACION_NO_APLICA, $this->rechazo(fn () => $this->aprobar($d))->codigo);
        $this->assertSame(ResolucionNoPermitida::APROBACION_NO_APLICA, $this->rechazo(fn () => $this->masiva()->revocarAprobacion($d, $this->b(), 'Otra revocación sin efecto.'))->codigo);
    }

    public function test_revocar_la_decision_mata_la_sesion_y_deja_la_aprobacion_como_historia(): void
    {
        $this->flags();
        $d = $this->solicitar3()['decision_id'];
        $this->aprobar($d);
        $this->ingresar();

        $this->svc()->revocar($d, $this->a(), 'Se retira la decisión por nueva información.');

        $this->get(route('portal.panel'))->assertRedirect(route('portal.inicio'));
        $this->assertSame('revocada', DecisionIdentidadAprobacion::where('decision_id', $d)->value('estado'));
        $this->assertSame(1, DB::table('cf_decisiones_identidad_aprobaciones')->count(), 'nada se borra');
        $this->assertNotSame('identidad_aplicada', $this->caso()->resolucion);
        // Se puede volver a solicitar y aprobar (nueva decisión, nueva aprobación).
        $d2 = $this->solicitar3()['decision_id'];
        $this->assertNotSame($d, $d2);
        $this->aprobar($d2);
        $this->assertTrue($this->hayOtp());
    }

    public function test_un_otp_emitido_antes_de_revocar_la_aprobacion_o_apagar_el_interruptor_no_abre_sesion(): void
    {
        $this->flags();
        $d = $this->solicitar3()['decision_id'];
        $this->aprobar($d);
        foreach (['aprobacion', 'interruptor'] as $caso) {
            Mail::fake();
            DB::table('cf_accesos_otp')->delete();
            $this->post(route('portal.solicitar'), ['documento' => self::DOC, 'correo' => self::CORREO_X]);
            $codigo = $this->ultimoCodigo();
            $this->assertNotNull($codigo, $caso);
            if ($caso === 'aprobacion') {
                $this->masiva()->revocarAprobacion($d, $this->b(), 'Se retira la segunda aprobación por la prueba.');
            } else {
                $this->flags(true, true, false);
            }
            $this->validarCodigo($codigo)->assertRedirect(route('portal.codigo'));
            $this->assertNull(session('cf_portal.contexto'), $caso);
            if ($caso === 'aprobacion') {
                // Para volver a autorizar hay que revocar la decisión y solicitar una NUEVA (otra aprobación, otro hash de scope).
                $this->flags();
                $this->svc()->revocar($d, $this->a(), 'Se retira la decisión para solicitar una nueva.');
                $d = $this->solicitar3()['decision_id'];
                $this->aprobar($d);
            }
        }
    }

    // ── Fila suelta, misma persona y no regresión ────────────────────────────

    public function test_misma_persona_entre_el_grupo_grande_y_la_fila_suelta_no_concede_ni_amplia(): void
    {
        $this->flags();
        $this->svc()->crear($this->caso, $this->a(), 'misma_persona', self::MOTIVO, ['grupos' => [$this->g('MARIA GOMEZ'), $this->g('MARIO GOMEZ')], 'evidencia' => self::EVID, 'confirmo' => true, 'evidencia_externa' => true, 'alcance_masivo' => true]);
        $this->assertFalse($this->resolver()->esAprobado(), 'la misma persona sola no concede en un scope masivo');
        $this->assertFalse($this->hayOtp());

        // Con la autorización masiva aprobada, el scope sigue siendo SOLO el grupo grande aunque exista esa misma_persona.
        $this->aprobar($this->solicitar3()['decision_id']);
        $res = $this->resolver();
        $this->assertSame([$this->g('MARIA GOMEZ')], $res->grupos);
        $this->ingresar();
        $this->get(route('portal.descargar', $this->idCert(41)))->assertNotFound();
    }

    public function test_el_gate_historico_de_otros_correos_no_se_toca(): void
    {
        $this->flags();
        $this->assertTrue($this->hayOtp('c@example.test'), 'el grupo C entra por su correo exclusivo');
        $d = $this->solicitar3()['decision_id'];   // solicitud pendiente
        $this->assertTrue($this->hayOtp('c@example.test'), 'una solicitud no aplicable no quita acceso histórico');
        $this->aprobar($d);
        $this->assertTrue($this->hayOtp('c@example.test'));
        $this->ingresar('c@example.test');
        $this->assertSame($this->g('MARIA GOMEZ C'), session('cf_portal.contexto')['grupo']);
    }

    // ── Administración (HTTP) y privacidad ───────────────────────────────────

    public function test_la_pantalla_muestra_el_riesgo_masivo_con_conteos_y_sin_datos_personales(): void
    {
        $this->flags();
        $d = $this->solicitar3()['decision_id'];
        $props = fn (int $uid) => $this->actingAs(Usuario::findOrFail($uid))->get(route('credential-flow.historico.casos.show', $this->caso))->assertOk()->viewData('page')['props']['caso']['identidad'];

        $a = $props($this->a());
        $this->assertSame(93, $a['masivo']['grupos'][$this->g('MARIA GOMEZ')]['descargables']);
        $this->assertSame(AutorizacionMasiva::TEXTO_CONFIRMACION, $a['masivo']['texto_confirmacion']);
        $dec = collect($a['decisiones'])->firstWhere('id', $d);
        $this->assertSame(['pendiente', true, false], [$dec['aprobacion']['estado'], $dec['aprobacion']['solicitada_por_usted'], $dec['aprobacion']['puede_aprobar']]);
        $this->assertSame([], $a['rutas_aprobar'], 'quien solicitó no recibe la ruta de aprobación');

        $b = $props($this->b());
        $decB = collect($b['decisiones'])->firstWhere('id', $d);
        $this->assertTrue($decB['aprobacion']['puede_aprobar']);
        $this->assertArrayHasKey($d, $b['rutas_aprobar']);
        $json = json_encode([$a, $b], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString(self::CORREO_X, $json, 'correo completo nunca');
        $this->assertStringNotContainsString(self::DOC, $json, 'documento completo nunca');
    }

    public function test_el_flujo_http_solicitar_con_a_y_aprobar_con_b_y_a_no_puede(): void
    {
        $this->flags();
        $a = Usuario::findOrFail($this->a());
        $b = Usuario::findOrFail($this->b());
        $this->actingAs($a)->post(route('credential-flow.historico.casos.identidad.crear', $this->caso), ['tipo' => 'correo_autorizado', 'motivo' => self::MOTIVO] + $this->params())->assertSessionHas('success');
        $d = (int) DB::table('cf_decisiones_identidad')->value('id');
        $ruta = route('credential-flow.historico.casos.identidad.aprobar-masiva', [$this->caso, $d]);

        $this->actingAs($a)->post($ruta, ['motivo' => 'Intento de autoaprobación.', 'confirmo_masiva' => true])->assertSessionHas('error');
        $this->assertSame('pendiente', DecisionIdentidadAprobacion::where('decision_id', $d)->value('estado'));
        $this->actingAs($b)->post($ruta, ['motivo' => 'Aprobación del segundo administrador.'])->assertSessionHasErrors('confirmo_masiva');
        $this->actingAs($this->comercial())->post($ruta, ['motivo' => 'Sin permiso de rol.', 'confirmo_masiva' => true])->assertForbidden();
        $this->actingAs($b)->post($ruta, ['motivo' => 'Aprobación del segundo administrador.', 'confirmo_masiva' => true])->assertSessionHas('success');
        $this->assertSame('aprobada', DecisionIdentidadAprobacion::where('decision_id', $d)->value('estado'));
        // Una decisión de OTRO caso nunca se aprueba por esta ruta.
        $this->actingAs($b)->post(route('credential-flow.historico.casos.identidad.aprobar-masiva', [999999, $d]), ['motivo' => 'Caso inexistente.', 'confirmo_masiva' => true])->assertNotFound();

        $rev = route('credential-flow.historico.casos.identidad.revocar-aprobacion', [$this->caso, $d]);
        $this->actingAs($b)->post($rev, ['motivo' => 'Se retira solo la aprobación.'])->assertSessionHas('success');
        $this->assertSame('revocada', DecisionIdentidadAprobacion::where('decision_id', $d)->value('estado'));
    }

    public function test_el_detalle_y_el_resolver_no_hacen_n_mas_1_con_mas_certificados(): void
    {
        $this->flags();
        $this->aprobar($this->solicitar3()['decision_id']);
        $n = fn (callable $f) => count($this->sentencias($f));
        $uno = $n(fn () => $this->resolver());
        $pantalla1 = $n(fn () => app(ConsultaConciliaciones::class)->detalle($this->caso()));
        $this->inflar(60, 0);
        $dos = $n(fn () => $this->resolver());
        $pantalla2 = $n(fn () => app(ConsultaConciliaciones::class)->detalle($this->caso()));
        $this->assertSame($uno, $dos, 'el resolver no hace consultas por certificado');
        $this->assertSame($pantalla1, $pantalla2, 'el detalle no hace consultas por certificado');
    }

    public function test_el_panel_y_la_descarga_no_hacen_n_mas_1_con_mas_certificados(): void
    {
        $this->flags();
        $this->aprobar($this->solicitar3()['decision_id']);
        $this->ingresar();
        $uno = count($this->sentencias(fn () => $this->get(route('portal.panel'))->assertOk()));
        $this->inflar(60, 0);
        // El scope congelado sigue siendo el mismo grupo: el panel crece en tarjetas, no en consultas.
        $dos = count($this->sentencias(fn () => $this->get(route('portal.panel'))->assertOk()));
        $this->assertLessThanOrEqual($uno + 1, $dos);
        $decisiones = array_filter($this->sentencias(fn () => $this->get(route('portal.panel'))), fn ($s) => str_contains($s, 'cf_decisiones_identidad'));
        $this->assertCount(1, $decisiones, 'revalidación: UNA consulta (decisión + caso + aprobación)');
    }

    public function test_el_otp_y_la_validacion_no_hacen_n_mas_1_con_mas_certificados(): void
    {
        $this->flags();
        $this->aprobar($this->solicitar3()['decision_id']);
        $medir = function () {
            Mail::fake();
            DB::table('cf_accesos_otp')->delete();
            $q1 = count($this->sentencias(fn () => $this->post(route('portal.solicitar'), ['documento' => self::DOC, 'correo' => self::CORREO_X])));
            $codigo = $this->ultimoCodigo();
            $q2 = count($this->sentencias(fn () => $this->validarCodigo($codigo)));

            return [$q1, $q2];
        };
        $uno = $medir();
        $this->post(route('portal.salir'));
        $this->inflar(60, 0);
        // El blast radius cambió: la aprobación sigue siendo válida (solo se compara al aprobar), y el costo no depende del número de certificados.
        $dos = $medir();
        $this->assertSame($uno, $dos, 'OTP y validación: mismas consultas con 60 certificados más');
    }
}
