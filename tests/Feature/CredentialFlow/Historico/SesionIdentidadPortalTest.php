<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Conciliacion;
use App\Models\CredentialFlow\Emision;
use App\Support\CredentialFlow\Conciliaciones\DetectorConciliaciones;
use App\Support\CredentialFlow\Emisiones\EmisorCredencial;
use App\Support\CredentialFlow\Identidad\DecisionesIdentidad;
use App\Support\CredentialFlow\Identidad\EvidenciaIdentidad;
use App\Support\CredentialFlow\Identidad\IdentidadFlags;
use App\Support\CredentialFlow\Identidad\ScopeIdentidadAprobado;
use App\Support\CredentialFlow\Portal\AccesoPortal;
use App\Support\CredentialFlow\Portal\NombreConservador;
use App\Support\CredentialFlow\Portal\SesionPortal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

/**
 * Fase 10B-3B-2: sesión multi-grupo + panel + descarga + revocación efectiva + cierre/reapertura del caso, con el middleware REAL y el OTP por correo falso.
 * Documento 3000002: María Gómez (evento 1, cert 40) y Mario Gómez (evento 2, cert 41) comparten el único correo; se añade un tercer grupo con su propio correo.
 */
class SesionIdentidadPortalTest extends PortalTestCase
{
    private const DOC = '3000002';

    private const CORREO_X = 'x@example.test';

    private const MOTIVO = 'Decisión administrativa de prueba.';

    private const EVID = 'Confirmado por el organizador del evento mediante correo institucional.';

    private int $idC = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Tercer grupo del mismo documento, FUERA de cualquier scope A+B, con su propio correo exclusivo.
        $molde = (array) DB::table('cf_certificados_legado')->where('id', $this->idCert(40))->first();
        unset($molde['id']);
        $this->idC = DB::table('cf_certificados_legado')->insertGetId(['nombre_completo' => 'MARIA GOMEZ C', 'evento_id' => 3, 'codigo_legado' => null, 'conciliacion_estado' => 'ok'] + $molde);
        DB::table('cf_correos')->insert(['certificado_legado_id' => $this->idC, 'correo' => 'c@example.test', 'correo_normalizado' => 'c@example.test', 'estado' => 'valido', 'orden' => 1, 'es_principal' => 1, 'origen' => 'legado', 'created_at' => now(), 'updated_at' => now()]);
        app(DetectorConciliaciones::class)->ejecutar();
        // El documento tiene un correo exclusivo (c@) que ya autentica el grupo C, así que el detector no crea caso: se crea como lo haría con un documento bloqueado.
        if (Conciliacion::where('referencia_clave', EvidenciaIdentidad::hashDocumento(self::DOC))->doesntExist()) {
            $caso = Conciliacion::create(['tipo' => 'identidad_ambigua', 'estado' => 'abierto', 'referencia_tipo' => 'documento', 'referencia_clave' => EvidenciaIdentidad::hashDocumento(self::DOC), 'motivo_origen' => 'CORREO_CRUZA_GRUPOS', 'clave_idempotencia' => 'identidad_ambigua:documento:sesion-test']);
            foreach (DB::table('cf_certificados_legado')->where('documento_clave', self::DOC)->orderBy('id')->get(['id', 'nombre_completo']) as $i => $c) {
                DB::table('cf_conciliaciones_certificados')->insert(['conciliacion_id' => $caso->id, 'certificado_legado_id' => $c->id, 'rol' => 'grupo_'.($i + 1), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    // ── Utilidades ───────────────────────────────────────────────────────────

    private function flags(bool $decisiones, bool $multi): void
    {
        config(['credential_flow.identidad.decisiones_enabled' => $decisiones, 'credential_flow.identidad.multi_scope_enabled' => $multi]);
    }

    private function caso(): Conciliacion
    {
        return Conciliacion::where('referencia_clave', EvidenciaIdentidad::hashDocumento(self::DOC))->firstOrFail();
    }

    private function g(string $nombre): string
    {
        return NombreConservador::grupoId($nombre);
    }

    private function actorId(): int
    {
        return $this->admin()->id;
    }

    private function svc(): DecisionesIdentidad
    {
        return app(DecisionesIdentidad::class);
    }

    private function misma(array $extra = [], ?array $nombres = null): int
    {
        return $this->svc()->crear($this->caso()->id, $this->actorId(), 'misma_persona', self::MOTIVO, [
            'grupos' => array_map(fn ($n) => $this->g($n), $nombres ?? ['MARIA GOMEZ', 'MARIO GOMEZ']), 'evidencia' => self::EVID, 'confirmo' => true, 'evidencia_externa' => true,
        ] + $extra)['decision_id'];
    }

    private function autorizar(string $nombre, string $correo = self::CORREO_X): int
    {
        return $this->svc()->crear($this->caso()->id, $this->actorId(), 'correo_autorizado', self::MOTIVO, [
            'grupo' => $this->g($nombre), 'correo_hmac' => EvidenciaIdentidad::hashCorreo($correo), 'evidencia' => self::EVID, 'reforzada' => true,
        ])['decision_id'];
    }

    private function revocar(int $id): void
    {
        $this->svc()->revocar($id, $this->actorId(), 'Se retira la decisión por nueva información.');
    }

    /** @return list<string> tipos de las tarjetas del panel */
    private function tipos(TestResponse $r): array
    {
        preg_match_all('/data-certificado="([a-z_]+)"/', $r->getContent(), $m);

        return $m[1];
    }

    private function ingresar(string $correo = self::CORREO_X): TestResponse
    {
        Mail::fake();
        DB::table('cf_accesos_otp')->delete();   // cada ingreso de prueba parte sin el límite de 60 s del anterior
        $this->post(route('portal.solicitar'), ['documento' => self::DOC, 'correo' => $correo])->assertRedirect(route('portal.codigo'));
        $codigo = $this->ultimoCodigo();
        $this->assertNotNull($codigo, 'se esperaba un OTP');

        return $this->validarCodigo($codigo);
    }

    private function emision(): Emision
    {
        $lote = $this->loteCon(0);

        return app(EmisorCredencial::class)->emitir($this->participante($lote, 'PERSONA CORREGIDA', 'ZZ99990'), $lote->fresh(), null);
    }

    // ── Los cuatro interruptores ─────────────────────────────────────────────

    public static function combinaciones(): array
    {
        return ['off/off' => [false, false, false, false], 'on/off' => [true, false, true, false], 'off/on' => [false, true, false, false], 'on/on' => [true, true, true, true]];
    }

    /** @dataProvider combinaciones */
    public function test_las_cuatro_combinaciones_de_interruptores(bool $decisiones, bool $multi, bool $otp, bool $sesion): void
    {
        $this->flags($decisiones, $multi);
        $this->misma();

        $this->post(route('portal.solicitar'), ['documento' => self::DOC, 'correo' => self::CORREO_X])->assertRedirect(route('portal.codigo'));
        $codigo = $this->ultimoCodigo();
        $this->assertSame($otp, $codigo !== null, 'emisión de OTP');
        if ($codigo !== null) {
            $this->validarCodigo($codigo);
        }
        $this->assertSame($sesion, session('cf_portal.contexto') !== null, 'sesión');
        $this->get(route('portal.panel'))->assertStatus($sesion ? 200 : 302);
    }

    // ── misma persona A+B: sesión, panel, descarga, fuera de scope ────────────

    public function test_misma_persona_abre_a_mas_b_nunca_c_y_la_sesion_no_guarda_pii(): void
    {
        $this->flags(true, true);
        $dec = $this->misma();
        $this->ingresar()->assertRedirect(route('portal.panel'));

        $ctx = session('cf_portal.contexto');
        $esperados = [$this->g('MARIA GOMEZ'), $this->g('MARIO GOMEZ')];
        sort($esperados);
        $this->assertSame($esperados, $ctx['grupos']);
        $this->assertSame([$dec], $ctx['decisiones']);
        $this->assertSame($ctx['scope_hash'], $ctx['grupo'], 'grupo nunca es null en una sesión aprobada');
        $this->assertCount(1, $ctx['decisiones_claves']);
        $json = json_encode($ctx);
        foreach (['MARIA', 'MARIO', 'GOMEZ', 'example.test'] as $pii) {
            $this->assertStringNotContainsString($pii, $json, 'sin PII en el payload de sesión');
        }

        $panel = $this->get(route('portal.panel'))->assertOk();
        $ids = collect((new AccesoPortal)->tarjetas(self::DOC, $esperados))->pluck('id')->all();
        $this->assertCount(2, $this->tipos($panel), 'una tarjeta por certificado lógico de A y B');
        $this->assertNotContains($this->idC, $ids);
        // Descarga de A (evento 1, renderizable): 200. C, del mismo documento y fuera del scope: 404 uniforme igual que un id inexistente.
        $this->get(route('portal.descargar', $this->idCert(40)))->assertOk();
        $this->get(route('portal.descargar', $this->idC))->assertNotFound();
        $this->get(route('portal.descargar', 99999999))->assertNotFound();
    }

    public function test_correo_autorizado_sin_union_abre_solo_su_grupo_y_con_misma_persona_el_conjunto(): void
    {
        $this->flags(true, true);
        $this->autorizar('MARIA GOMEZ');
        $this->ingresar()->assertRedirect(route('portal.panel'));
        $this->assertSame([$this->g('MARIA GOMEZ')], session('cf_portal.contexto')['grupos']);
        $this->assertCount(1, $this->tipos($this->get(route('portal.panel'))));
        $this->get(route('portal.descargar', $this->idCert(41)))->assertNotFound();
        $this->post(route('portal.salir'));

        $this->misma();   // A+B misma persona compatible
        $this->ingresar()->assertRedirect(route('portal.panel'));
        $g = [$this->g('MARIA GOMEZ'), $this->g('MARIO GOMEZ')];
        sort($g);
        $this->assertSame($g, session('cf_portal.contexto')['grupos']);
        $this->assertCount(2, session('cf_portal.contexto')['decisiones']);
    }

    public function test_personas_distintas_nunca_amplia_y_con_autorizacion_propia_cada_sesion_ve_solo_su_grupo(): void
    {
        $this->flags(true, true);
        $this->svc()->crear($this->caso()->id, $this->actorId(), 'personas_distintas', self::MOTIVO, ['grupos' => [$this->g('MARIA GOMEZ'), $this->g('MARIO GOMEZ')], 'confirmo' => true]);
        $this->post(route('portal.solicitar'), ['documento' => self::DOC, 'correo' => self::CORREO_X]);
        $this->assertNull($this->ultimoCodigo(), 'correo compartido + personas distintas: sin OTP');

        $this->autorizar('MARIO GOMEZ');
        $this->ingresar()->assertRedirect(route('portal.panel'));
        $this->assertSame([$this->g('MARIO GOMEZ')], session('cf_portal.contexto')['grupos']);
        $this->get(route('portal.descargar', $this->idCert(40)))->assertNotFound();
    }

    public function test_terminales_no_crean_sesion(): void
    {
        $this->flags(true, true);
        $this->svc()->crear($this->caso()->id, $this->actorId(), 'requiere_soporte', self::MOTIVO);
        $this->post(route('portal.solicitar'), ['documento' => self::DOC, 'correo' => self::CORREO_X]);
        $this->assertNull($this->ultimoCodigo());
        $this->assertNull(session('cf_portal.contexto'));
    }

    // ── Riesgo diferido a 3C ─────────────────────────────────────────────────

    public function test_el_riesgo_reforzado_y_el_masivo_siguen_diferidos_a_3c(): void
    {
        $this->flags(true, true);
        // Mismo evento (DIF_NOMBRE): ambos grupos con certificados en el evento 1.
        DB::table('cf_certificados_legado')->where('id', $this->idCert(41))->update(['evento_id' => 1]);
        $this->misma();
        $this->post(route('portal.solicitar'), ['documento' => self::DOC, 'correo' => self::CORREO_X]);
        $this->assertNull($this->ultimoCodigo(), 'riesgo_reforzado_pendiente_3c');
        $this->assertSame('abierto', $this->caso()->estado, 'tampoco cierra el caso');
    }

    public function test_un_scope_masivo_calcula_pero_no_abre_sesion_ni_cierra_el_caso(): void
    {
        $this->flags(true, true);
        $molde = (array) DB::table('cf_certificados_legado')->where('id', $this->idCert(40))->first();
        unset($molde['id']);
        $filas = [];
        for ($i = 0; $i < 100; $i++) {
            $id = DB::table('cf_certificados_legado')->insertGetId($molde);
            $filas[] = ['conciliacion_id' => $this->caso()->id, 'certificado_legado_id' => $id, 'rol' => 'grupo_1', 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('cf_conciliaciones_certificados')->insert($filas);
        $this->misma(['alcance_masivo' => true]);
        $this->svc()->crear($this->caso()->id, $this->actorId(), 'correo_autorizado', self::MOTIVO, ['grupo' => $this->g('MARIA GOMEZ'), 'correo_hmac' => EvidenciaIdentidad::hashCorreo(self::CORREO_X), 'evidencia' => self::EVID, 'reforzada' => true, 'alcance_masivo' => true, 'evidencia_externa' => true, 'fuente_evidencia' => 'propiedad_buzon']);
        $r = app(ScopeIdentidadAprobado::class)->resolver(self::DOC, self::CORREO_X);
        $this->assertFalse($r->esAprobado(), '10B-3C-3: sin segunda aprobación (y sin interruptor masivo) no hay scope aprobado');

        $this->post(route('portal.solicitar'), ['documento' => self::DOC, 'correo' => self::CORREO_X]);
        $this->assertNull($this->ultimoCodigo(), 'masivo sin segunda aprobación: sin OTP');
        $this->assertNull(session('cf_portal.contexto'));
        $this->assertSame('abierto', $this->caso()->estado);
    }

    // ── Revocación efectiva ──────────────────────────────────────────────────

    public function test_revocar_con_sesion_viva_la_mata_entera_en_la_siguiente_peticion(): void
    {
        $this->flags(true, true);
        $dec = $this->misma();
        $this->ingresar()->assertRedirect(route('portal.panel'));
        $this->get(route('portal.panel'))->assertOk();

        $this->revocar($dec);

        $this->get(route('portal.panel'))->assertRedirect(route('portal.inicio'))->assertSessionHas('aviso', SesionPortal::MENSAJE_EXPIRADO);
        $this->assertNull(session('cf_portal.contexto'));
        $this->get(route('portal.descargar', $this->idCert(40)))->assertRedirect(route('portal.inicio'));
        $this->get(route('portal.panel'))->assertRedirect(route('portal.inicio'));
    }

    public function test_sustituir_una_decision_por_otra_con_el_mismo_grupo_mata_la_sesion_vieja(): void
    {
        $this->flags(true, true);
        $uno = $this->misma();
        $this->ingresar();
        $this->get(route('portal.panel'))->assertOk();

        $this->revocar($uno);
        $dos = $this->misma();   // mismo scope textual, OTRA decisión

        $this->assertNotSame($uno, $dos);
        $this->get(route('portal.panel'))->assertRedirect(route('portal.inicio'));
        $this->ingresar()->assertRedirect(route('portal.panel'));   // un acceso nuevo sí, con la decisión nueva
        $this->assertSame([$dos], session('cf_portal.contexto')['decisiones']);
    }

    public function test_apagar_un_interruptor_mata_las_sesiones_con_scope(): void
    {
        $this->flags(true, true);
        $this->misma();
        $this->ingresar();
        $this->get(route('portal.panel'))->assertOk();

        $this->flags(true, false);

        $this->get(route('portal.panel'))->assertRedirect(route('portal.inicio'));
    }

    public function test_un_payload_de_sesion_corrupto_cierra_la_sesion(): void
    {
        $this->flags(true, true);
        $dec = $this->misma();
        $this->ingresar();
        $ok = session('cf_portal.contexto');
        $this->get(route('portal.panel'))->assertOk();

        $malos = [
            'grupos vacíos' => ['grupos' => []],
            'grupo inválido' => ['grupos' => ['no-es-un-hash']],
            'grupo ≠ scope_hash' => ['grupo' => null],
            'sin decisiones' => ['decisiones' => [], 'decisiones_claves' => []],
            'claves desparejadas' => ['decisiones_claves' => []],
            'decisión duplicada' => ['decisiones' => [$dec, $dec], 'decisiones_claves' => [$ok['decisiones_claves'][0], $ok['decisiones_claves'][0]]],
            'clave equivocada' => ['decisiones_claves' => [str_repeat('0', 64)]],
            'scope_hash inválido' => ['scope_hash' => 'x', 'grupo' => 'x'],
        ];
        foreach ($malos as $nombre => $cambio) {
            $this->withSession(['cf_portal.contexto' => $cambio + $ok])->get(route('portal.panel'))->assertRedirect(route('portal.inicio'));
            $this->assertNull(session('cf_portal.contexto'), $nombre);
        }
        // Sesión histórica con lista de grupos (sin scope): también corrupta.
        $historica = ['documento' => '1000001', 'grupo' => null, 'grupos' => [$this->g('X')], 'authenticated_at' => time(), 'last_activity' => time(), 'absolute_expires_at' => time() + 600];
        $this->withSession(['cf_portal.contexto' => $historica])->get(route('portal.panel'))->assertRedirect(route('portal.inicio'));
    }

    // ── perteneceA ───────────────────────────────────────────────────────────

    public function test_pertenece_a_con_null_un_grupo_una_lista_y_la_lista_vacia(): void
    {
        $a = CertificadoLegado::findOrFail($this->idCert(40));
        $b = CertificadoLegado::findOrFail($this->idCert(41));
        $ga = $this->g('MARIA GOMEZ');
        $gb = $this->g('MARIO GOMEZ');

        $this->assertTrue(AccesoPortal::perteneceA($a, self::DOC, null), 'null = documento completo');
        $this->assertTrue(AccesoPortal::perteneceA($a, self::DOC, $ga), 'compatibilidad: un grupo en string');
        $this->assertTrue(AccesoPortal::perteneceA($a, self::DOC, [$ga]));
        $this->assertFalse(AccesoPortal::perteneceA($b, self::DOC, [$ga]));
        $this->assertTrue(AccesoPortal::perteneceA($b, self::DOC, [$gb, $ga]));
        $this->assertFalse(AccesoPortal::perteneceA($a, self::DOC, []), 'una lista vacía JAMÁS es documento completo');
        $this->assertFalse(AccesoPortal::perteneceA($a, '9999999', null), 'otro documento');
        $this->assertSame([], (new AccesoPortal)->tarjetas(self::DOC, []));
    }

    // ── Reemplazo moderno dentro del scope ───────────────────────────────────

    public function test_el_reemplazo_moderno_dentro_del_scope_hereda_la_autorizacion_del_historico(): void
    {
        $this->flags(true, true);
        $e = $this->emision();
        DB::table('cf_certificados_legado')->where('id', $this->idCert(41))->update(['estado' => 'reemplazado', 'reemplazado_por_emision_id' => $e->id]);
        $this->misma();
        $this->ingresar()->assertRedirect(route('portal.panel'));

        $this->assertContains('actualizado', $this->tipos($this->get(route('portal.panel'))));
        $d = $this->get(route('portal.descargar', $this->idCert(41)))->assertOk();
        $this->assertSame(Emision::find($e->id)->pdf_hash, hash_file('sha256', $d->baseResponse->getFile()->getPathname()));
        $this->assertSame(1, DB::table('cf_descargas')->where('emision_id', $e->id)->count());
        // El reemplazo de un certificado de C (fuera del scope) no es visible ni descargable.
        DB::table('cf_certificados_legado')->where('id', $this->idC)->update(['estado' => 'reemplazado', 'reemplazado_por_emision_id' => $this->emision()->id]);
        $this->get(route('portal.descargar', $this->idC))->assertNotFound();
    }

    // ── Logout, replay, límites ──────────────────────────────────────────────

    public function test_logout_destruye_la_sesion_y_la_url_deja_de_servir(): void
    {
        $this->flags(true, true);
        $this->misma();
        $this->ingresar();
        $this->get(route('portal.descargar', $this->idCert(40)))->assertOk();

        $this->post(route('portal.salir'));

        $this->assertNull(session('cf_portal.contexto'));
        $this->get(route('portal.descargar', $this->idCert(40)))->assertRedirect(route('portal.inicio'));
    }

    public function test_el_limite_de_descargas_aplica_tambien_a_una_sesion_con_scope(): void
    {
        $this->flags(true, true);
        config(['credential_flow.portal.limite_descarga_por_minuto' => 2]);
        $this->misma();
        $this->ingresar();
        $this->get(route('portal.descargar', $this->idCert(40)))->assertOk();
        $this->get(route('portal.descargar', $this->idCert(40)))->assertOk();
        $this->get(route('portal.descargar', $this->idCert(40)))->assertStatus(429);
    }

    // ── Cierre y reapertura del caso ─────────────────────────────────────────

    public function test_la_decision_aplicable_cierra_el_caso_con_su_evento_y_revocarla_lo_reabre(): void
    {
        $this->flags(true, true);
        $dec = $this->misma();
        $caso = $this->caso();

        $this->assertSame(['resuelto', 'identidad_aplicada', $this->actorId()], [$caso->estado, $caso->resolucion, (int) $caso->resuelto_por]);
        $ev = DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso->id)->where('accion', 'identidad_acceso_aplicado')->get();
        $this->assertCount(1, $ev);
        $evid = json_decode($ev[0]->evidencia, true);
        $this->assertSame([[$dec], 2, $this->actorId()], [$evid['decisiones'], $evid['grupos'], $evid['aplicado_por']]);
        $this->assertSame(64, strlen($evid['scope_hash']));
        $this->assertStringNotContainsString(self::CORREO_X, $ev[0]->evidencia);

        // Abrir el panel no registra ningún evento más.
        $this->ingresar();
        $this->get(route('portal.panel'));
        $this->assertSame(1, DB::table('cf_conciliaciones_eventos')->where('accion', 'identidad_acceso_aplicado')->count());

        $this->revocar($dec);
        $caso = $this->caso();
        $this->assertSame(['abierto', null], [$caso->estado, $caso->resolucion]);
        $ev = DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso->id)->where('accion', 'identidad_acceso_revocado')->first();
        $this->assertNotNull($ev);
        $this->assertTrue(json_decode($ev->evidencia, true)['reabierto']);
        // Nada se borra: la resolución quedó en la bitácora.
        $this->assertSame(1, DB::table('cf_conciliaciones_eventos')->where('accion', 'identidad_acceso_aplicado')->count());
    }

    public function test_si_queda_otra_via_equivalente_el_caso_sigue_resuelto(): void
    {
        $this->flags(true, true);
        $misma = $this->misma();
        $aut = $this->autorizar('MARIA GOMEZ');   // admite decisiones nuevas con el caso ya resuelto
        $this->assertSame('resuelto', $this->caso()->estado);

        $this->revocar($aut);   // la misma persona sola aún concede (único candidato)
        $this->assertSame(['resuelto', 'identidad_aplicada'], [$this->caso()->estado, $this->caso()->resolucion]);
        $this->assertSame(0, DB::table('cf_conciliaciones_eventos')->where('accion', 'identidad_acceso_revocado')->count());

        $this->revocar($misma);
        $this->assertSame('abierto', $this->caso()->estado);
    }

    public function test_sin_los_dos_interruptores_crear_una_decision_no_cierra_el_caso(): void
    {
        foreach ([[false, false], [true, false], [false, true]] as [$d, $m]) {
            $this->flags($d, $m);
            $dec = $this->misma();
            $this->assertSame('abierto', $this->caso()->estado, "decisiones=$d multi=$m");
            $this->revocar($dec);
        }
        $this->assertSame(0, DB::table('cf_conciliaciones_eventos')->where('accion', 'identidad_acceso_aplicado')->count());
    }

    public function test_personas_distintas_sin_via_individual_sigue_en_soporte_y_no_se_cierra(): void
    {
        $this->flags(true, true);
        $this->svc()->crear($this->caso()->id, $this->actorId(), 'personas_distintas', self::MOTIVO, ['grupos' => [$this->g('MARIA GOMEZ'), $this->g('MARIO GOMEZ')], 'confirmo' => true]);
        $this->assertSame('requiere_soporte', $this->caso()->estado);
        $this->assertNull($this->caso()->resolucion);
    }

    // ── Regresión: sin decisiones nada cambia ────────────────────────────────

    public function test_sin_decisiones_el_portal_es_identico_con_los_interruptores_encendidos(): void
    {
        $acceso = new AccesoPortal;
        $docs = DB::table('cf_certificados_legado')->distinct()->pluck('documento_clave');
        $correos = DB::table('cf_correos')->where('estado', 'valido')->whereNotNull('certificado_legado_id')->pluck('correo_normalizado')->unique();
        $foto = function () use ($acceso, $docs, $correos) {
            $f = [];
            foreach ($docs as $d) {
                foreach ($correos as $c) {
                    $f[$d.'|'.$c] = $acceso->alcance((string) $d, $c);
                }
                $f['t|'.$d] = $acceso->tarjetas((string) $d);
            }

            return md5(json_encode($f));
        };
        $this->flags(false, false);
        $apagado = $foto();
        $this->flags(true, true);
        $this->assertSame($apagado, $foto());
        $this->post(route('portal.solicitar'), ['documento' => '3000002', 'correo' => self::CORREO_X]);
        $this->assertNull($this->ultimoCodigo(), 'el documento ambiguo sigue sin vía');
    }

    public function test_una_sesion_historica_no_hace_consultas_de_decisiones(): void
    {
        $this->flags(true, true);
        $this->entrar('1.000.001', self::CORREO)->assertRedirect(route('portal.panel'));
        $q = $this->sentencias(function () {
            $this->get(route('portal.panel'))->assertOk();
            $this->get(route('portal.descargar', $this->idCert(1)));
        });

        $this->assertCount(0, array_filter($q, fn ($s) => str_contains($s, 'cf_decisiones_identidad')), 'ninguna consulta de decisiones en una sesión histórica');
    }

    public function test_la_sesion_con_scope_revalida_con_una_sola_consulta_y_el_panel_no_hace_n_mas_1(): void
    {
        $this->flags(true, true);
        $this->misma();
        $this->ingresar();
        $q = $this->sentencias(fn () => $this->get(route('portal.panel'))->assertOk());
        $this->assertCount(1, array_filter($q, fn ($s) => str_contains($s, 'cf_decisiones_identidad')), 'revalidación: UNA consulta por PK');

        $a = [$this->g('MARIA GOMEZ'), $this->g('MARIO GOMEZ')];
        $dos = count($this->sentencias(fn () => (new AccesoPortal)->tarjetas(self::DOC, $a)));
        $tres = count($this->sentencias(fn () => (new AccesoPortal)->tarjetas(self::DOC, [...$a, $this->g('MARIA GOMEZ C')])));
        $this->assertLessThanOrEqual($dos + 1, $tres, 'añadir un grupo al scope no añade consultas por grupo');
    }

    public function test_las_banderas_siguen_apagadas_por_defecto_en_la_configuracion(): void
    {
        $cfg = require base_path('config/credential_flow.php');
        $this->assertFalse($cfg['identidad']['decisiones_enabled']);
        $this->assertFalse($cfg['identidad']['multi_scope_enabled']);
        $this->flags(false, false);
        $this->assertFalse(IdentidadFlags::aplicacionHabilitada());
    }
}
