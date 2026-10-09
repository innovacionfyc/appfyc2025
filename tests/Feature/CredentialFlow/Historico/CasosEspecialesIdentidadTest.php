<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Models\CredentialFlow\Conciliacion;
use App\Models\CredentialFlow\DecisionIdentidad;
use App\Support\CredentialFlow\Conciliaciones\CasosEspeciales;
use App\Support\CredentialFlow\Conciliaciones\DetectorConciliaciones;
use App\Support\CredentialFlow\Conciliaciones\DetectorGruposSinVia;
use App\Support\CredentialFlow\Conciliaciones\EvidenciaExterna;
use App\Support\CredentialFlow\Conciliaciones\ResolucionNoPermitida;
use App\Support\CredentialFlow\Identidad\DecisionesIdentidad;
use App\Support\CredentialFlow\Identidad\EvidenciaIdentidad;
use App\Support\CredentialFlow\Identidad\ScopeIdentidadAprobado;
use App\Support\CredentialFlow\Portal\AccesoPortal;
use App\Support\CredentialFlow\Portal\NombreConservador;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

/**
 * Fase 10B-3C-4 (identidad): fila residual, nombres realmente distintos, grupos sin correo y grupos en disputa. NINGÚN caso clasificado como soporte / no resoluble /
 * pendiente de evidencia externa produce OTP, sesión, tarjeta ni descarga; el portal queda igual antes y después de cada acción administrativa.
 */
class CasosEspecialesIdentidadTest extends PortalTestCase
{
    private const MOTIVO = 'Decisión administrativa de prueba.';

    private const EVID = 'Certificación del organizador del evento sobre la propiedad del buzón.';

    private int $idC = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // 3000002: María y Mario comparten x@ y se añade un tercer grupo con su propio correo (el documento queda PARCIAL: tiene vía por c@).
        $this->agregar('3000002', 'MARIA GOMEZ C', 3, 'c@example.test');
        $this->idC = (int) DB::table('cf_certificados_legado')->where('nombre_completo', 'MARIA GOMEZ C')->value('id');
        // 3000010: dos nombres REALMENTE distintos que comparten el único correo (sin vía para nadie): caso base de identidad.
        $this->agregar('3000010', 'PEDRO ALVAREZ', 1, 'p@example.test');
        $this->agregar('3000010', 'LUCIA FERNANDEZ', 2, 'p@example.test');
        app(DetectorConciliaciones::class)->ejecutar();
    }

    private function agregar(string $documento, string $nombre, int $evento, ?string $correo): void
    {
        $molde = (array) DB::table('cf_certificados_legado')->where('id', $this->idCert(40))->first();
        unset($molde['id']);
        $id = DB::table('cf_certificados_legado')->insertGetId(['documento' => $documento, 'documento_clave' => $documento, 'nombre_completo' => $nombre, 'evento_id' => $evento, 'codigo_legado' => null, 'conciliacion_estado' => 'ok'] + $molde);
        if ($correo !== null) {
            DB::table('cf_correos')->insert(['certificado_legado_id' => $id, 'correo' => $correo, 'correo_normalizado' => $correo, 'estado' => 'valido', 'orden' => 1, 'es_principal' => 1, 'origen' => 'legado', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function flags(bool $on = true): void
    {
        config(['credential_flow.identidad.decisiones_enabled' => $on, 'credential_flow.identidad.multi_scope_enabled' => $on, 'credential_flow.identidad.mass_scope_enabled' => $on]);
    }

    private function especiales(): CasosEspeciales
    {
        return app(CasosEspeciales::class);
    }

    private function svc(): DecisionesIdentidad
    {
        return app(DecisionesIdentidad::class);
    }

    private function a(): int
    {
        return $this->admin()->id;
    }

    private function g(string $n): string
    {
        return NombreConservador::grupoId($n);
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

    private function casoDoc(string $doc, ?string $motivo = null): ?Conciliacion
    {
        return Conciliacion::where('tipo', 'identidad_ambigua')->where('referencia_clave', EvidenciaIdentidad::hashDocumento($doc))->when($motivo, fn ($q) => $q->where('motivo_origen', $motivo))->orderBy('id')->first();
    }

    /** Lo que el portal concede hoy a cada (documento, correo) pedible. */
    private function alcances(): string
    {
        $acceso = new AccesoPortal;
        $r = [];
        foreach (DB::table('cf_correos as r')->join('cf_certificados_legado as k', 'k.id', '=', 'r.certificado_legado_id')->where('r.estado', 'valido')->distinct()->get(['k.documento_clave', 'r.correo_normalizado']) as $p) {
            if (AccesoPortal::claveDocumento((string) $p->documento_clave) === (string) $p->documento_clave) {
                $r[$p->documento_clave.'|'.$p->correo_normalizado] = $acceso->alcance((string) $p->documento_clave, (string) $p->correo_normalizado);
            }
        }
        ksort($r);

        return md5(json_encode($r));
    }

    private function hayOtp(string $doc, string $correo): bool
    {
        Mail::fake();
        DB::table('cf_accesos_otp')->delete();
        $this->post(route('portal.solicitar'), ['documento' => $doc, 'correo' => $correo]);

        return $this->ultimoCodigo() !== null;
    }

    private function ingresar(string $doc, string $correo): TestResponse
    {
        Mail::fake();
        DB::table('cf_accesos_otp')->delete();
        $this->post(route('portal.solicitar'), ['documento' => $doc, 'correo' => $correo]);
        $this->assertNotNull($this->ultimoCodigo(), 'se esperaba un OTP');

        return $this->validarCodigo($this->ultimoCodigo());
    }

    // ── Nombres realmente distintos ──────────────────────────────────────────

    public function test_nombres_realmente_distintos_solo_admiten_soporte_no_resoluble_y_evidencia(): void
    {
        $this->flags();
        $caso = $this->casoDoc('3000010', 'CORREO_CRUZA_GRUPOS');
        $this->assertNotNull($caso);
        $c = $this->especiales()->clasificar($caso);
        $this->assertSame(['nombres_distintos', CasosEspeciales::PEND_EXTERNA, true, true], [$c['categoria'], $c['destino'], $c['solo_terminales'], $c['admite_evidencia']]);
        $portal = $this->alcances();

        $grupos = [$this->g('PEDRO ALVAREZ'), $this->g('LUCIA FERNANDEZ')];
        foreach ([
            ['misma_persona', ['grupos' => $grupos, 'evidencia' => self::EVID, 'confirmo' => '1', 'evidencia_externa' => '1']],
            ['personas_distintas', ['grupos' => $grupos, 'confirmo' => '1']],
            ['correo_autorizado', ['grupo' => $grupos[0], 'correo_hmac' => EvidenciaIdentidad::hashCorreo('p@example.test'), 'evidencia' => self::EVID, 'reforzada' => '1']],
        ] as [$tipo, $p]) {
            $this->actingAs($this->admin())->post(route('credential-flow.historico.casos.identidad.crear', $caso->id), ['tipo' => $tipo, 'motivo' => self::MOTIVO] + $p)->assertSessionHas('error');
        }
        auth()->logout();
        $this->assertSame(0, DB::table('cf_decisiones_identidad')->count());
        // La interfaz tampoco ofrece más que las decisiones válidas para esta categoría.
        $props = $this->actingAs($this->admin())->get(route('credential-flow.historico.casos.show', $caso->id))->viewData('page')['props']['caso'];
        $this->assertTrue($props['especial']['solo_terminales']);
        auth()->logout();

        // Evidencia externa + decisión terminal explícita: permitidas, y no abren nada.
        app(EvidenciaExterna::class)->registrar($caso->id, $this->a(), 'certificacion_organizador', 'El organizador confirma que son personas distintas con un correo compartido.');
        $this->svc()->crear($caso->id, $this->a(), 'no_resoluble', self::MOTIVO);
        $this->assertSame(CasosEspeciales::NO_RESOLUBLE, $this->especiales()->clasificar($caso->fresh())['destino']);
        $this->assertSame($portal, $this->alcances());
        $this->assertFalse($this->hayOtp('3000010', 'p@example.test'));
        $this->assertNull(session('cf_portal.contexto'));
        $this->assertSame('bloqueado', app(ScopeIdentidadAprobado::class)->resolver('3000010', 'p@example.test')->tipo === 'soporte' ? 'bloqueado' : 'otro');
    }

    public function test_las_decisiones_terminales_son_reversibles_y_conservan_la_historia(): void
    {
        $this->flags();
        $caso = $this->casoDoc('3000010', 'CORREO_CRUZA_GRUPOS');
        $d1 = $this->svc()->crear($caso->id, $this->a(), 'requiere_soporte', 'Se manda a soporte hasta tener evidencia.')['decision_id'];
        $this->assertSame('requiere_soporte', $caso->fresh()->estado);
        $this->assertSame(CasosEspeciales::SOPORTE, $this->especiales()->clasificar($caso->fresh())['destino']);

        $this->svc()->revocar($d1, $this->a(), 'Llegó evidencia nueva: se revisa el caso.');
        $this->assertSame('abierto', $caso->fresh()->estado);
        $d2 = $this->svc()->crear($caso->id, $this->a(), 'no_resoluble', 'La evidencia confirma que no se puede resolver.')['decision_id'];

        $this->assertSame(2, DB::table('cf_decisiones_identidad')->count(), 'ninguna decisión se borra');
        $this->assertSame(['revocada', 'vigente'], DB::table('cf_decisiones_identidad')->whereIn('id', [$d1, $d2])->orderBy('id')->pluck('estado')->all());
        $this->assertSame(CasosEspeciales::NO_RESOLUBLE, $this->especiales()->clasificar($caso->fresh())['destino']);
        $acciones = DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso->id)->pluck('accion')->all();
        $this->assertContains('identidad_decision_revocada', $acciones);
        $this->assertSame(2, count(array_keys($acciones, 'identidad_decision_creada', true)));
    }

    // ── Grupos sin correo ────────────────────────────────────────────────────

    public function test_un_grupo_sin_correo_queda_en_soporte_sin_copiar_el_correo_del_hermano(): void
    {
        $this->flags();
        $portal = $this->alcances();
        app(DetectorGruposSinVia::class)->ejecutar();
        $caso = $this->casoDoc('3000003', 'GRUPO_SIN_VIA_SIN_CORREO');
        $this->assertNotNull($caso);
        $this->assertSame('requiere_soporte', $caso->estado, 'requiere soporte desde su detección');
        $c = $this->especiales()->clasificar($caso);
        $this->assertSame(['sin_correo', CasosEspeciales::PEND_EXTERNA, 'externa', true], [$c['categoria'], $c['destino'], $c['dependencia'], $c['solo_terminales']]);

        // Nunca se autoriza el correo del hermano al grupo sin correo.
        $e = $this->rechazo(fn () => $this->svc()->crear($caso->id, $this->a(), 'correo_autorizado', self::MOTIVO, ['grupo' => $this->g('ANA RUIZ X'), 'correo_hmac' => EvidenciaIdentidad::hashCorreo('ar@example.test'), 'evidencia' => self::EVID, 'reforzada' => true]));
        $this->assertSame(ResolucionNoPermitida::TIPO_NO_ADMITIDO, $e->codigo);
        $this->assertSame(0, DB::table('cf_correos')->whereIn('certificado_legado_id', DB::table('cf_certificados_legado')->where('nombre_completo', 'ANA RUIZ X')->pluck('id'))->count(), 'no se copió ningún correo');

        app(EvidenciaExterna::class)->registrar($caso->id, $this->a(), 'certificacion_organizador', 'El organizador confirma la identidad; falta un correo propio.');
        $this->svc()->crear($caso->id, $this->a(), 'requiere_soporte', self::MOTIVO);
        $this->assertSame($portal, $this->alcances());
        // El hermano entra solo a SU grupo; el grupo sin correo no aparece ni se descarga.
        $this->ingresar('3000003', 'ar@example.test')->assertRedirect(route('portal.panel'));
        $this->assertCount(1, $this->tipos($this->get(route('portal.panel'))));
        $this->get(route('portal.descargar', $this->idCert(51)))->assertNotFound();
    }

    /** @return list<string> */
    private function tipos(TestResponse $r): array
    {
        preg_match_all('/data-certificado="([a-z_]+)"/', $r->getContent(), $m);

        return $m[1];
    }

    // ── Disputa por el único correo ──────────────────────────────────────────

    public function test_dos_grupos_que_compiten_por_el_unico_correo_siguen_en_soporte(): void
    {
        $this->flags();
        app(DetectorGruposSinVia::class)->ejecutar();
        $caso = $this->casoDoc('3000002', 'GRUPO_SIN_VIA_EVIDENCIA_EXTERNA');
        $this->assertNotNull($caso, 'María y Mario compiten por x@: una sola unidad de soporte');
        $c = $this->especiales()->clasificar($caso);
        $this->assertSame(['disputa_correo', CasosEspeciales::PEND_EXTERNA], [$c['categoria'], $c['destino']]);
        $portal = $this->alcances();

        foreach (['MARIA GOMEZ', 'MARIO GOMEZ'] as $n) {
            $e = $this->rechazo(fn () => $this->svc()->crear($caso->id, $this->a(), 'correo_autorizado', self::MOTIVO, ['grupo' => $this->g($n), 'correo_hmac' => EvidenciaIdentidad::hashCorreo('x@example.test'), 'evidencia' => self::EVID, 'reforzada' => true]));
            $this->assertSame(ResolucionNoPermitida::TIPO_NO_ADMITIDO, $e->codigo, $n);
        }
        $this->assertSame($portal, $this->alcances());
        $this->assertFalse($this->hayOtp('3000002', 'x@example.test'));
    }

    public function test_si_un_admin_asigna_el_correo_a_uno_el_otro_sigue_bloqueado_y_queda_visible_como_residual(): void
    {
        $this->flags();
        // Caso base de identidad del documento (como lo crea el detector en un documento bloqueado).
        $base = Conciliacion::create(['tipo' => 'identidad_ambigua', 'estado' => 'abierto', 'referencia_tipo' => 'documento', 'referencia_clave' => EvidenciaIdentidad::hashDocumento('3000002'), 'motivo_origen' => 'CORREO_CRUZA_GRUPOS', 'clave_idempotencia' => 'identidad_ambigua:documento:residual']);
        foreach (DB::table('cf_certificados_legado')->where('documento_clave', '3000002')->orderBy('id')->get(['id']) as $i => $c) {
            DB::table('cf_conciliaciones_certificados')->insert(['conciliacion_id' => $base->id, 'certificado_legado_id' => $c->id, 'rol' => 'grupo_'.($i + 1), 'created_at' => now(), 'updated_at' => now()]);
        }
        $hx = EvidenciaIdentidad::hashCorreo('x@example.test');

        $this->svc()->crear($base->id, $this->a(), 'correo_autorizado', self::MOTIVO, ['grupo' => $this->g('MARIA GOMEZ'), 'correo_hmac' => $hx, 'evidencia' => self::EVID, 'reforzada' => true]);

        // El otro grupo NO puede recibir el mismo correo.
        $this->assertSame(ResolucionNoPermitida::DECISION_CONFLICTIVA, $this->rechazo(fn () => $this->svc()->crear($base->id, $this->a(), 'correo_autorizado', self::MOTIVO, ['grupo' => $this->g('MARIO GOMEZ'), 'correo_hmac' => $hx, 'evidencia' => self::EVID, 'reforzada' => true]))->codigo);
        $this->assertSame(1, DB::table('cf_decisiones_identidad_correos')->where('vigente', 1)->count());

        // El caso principal se cierra, pero el residual queda VISIBLE con su propio estado (no escondido tras el caso resuelto).
        $this->assertSame(['resuelto', 'identidad_aplicada'], [$base->fresh()->estado, $base->fresh()->resolucion]);
        $residual = $this->casoDoc('3000002', 'GRUPO_SIN_VIA_EVIDENCIA_EXTERNA');
        $this->assertNotNull($residual);
        $this->assertSame('requiere_soporte', $residual->estado);
        $c = $this->especiales()->clasificar($residual);
        $this->assertSame(['fila_residual', CasosEspeciales::PEND_EXTERNA, true], [$c['categoria'], $c['destino'], $c['solo_terminales']]);
        // Idempotente: volver a aplicar no crea otro.
        app(DetectorGruposSinVia::class)->ejecutarDocumento('3000002');
        $this->assertSame(1, Conciliacion::where('motivo_origen', 'GRUPO_SIN_VIA_EVIDENCIA_EXTERNA')->count());

        // Portal: x@ entra SOLO al grupo autorizado; el residual no aparece ni se descarga; c@ conserva su acceso.
        $this->ingresar('3000002', 'x@example.test')->assertRedirect(route('portal.panel'));
        $this->assertSame([$this->g('MARIA GOMEZ')], session('cf_portal.contexto')['grupos']);
        $this->get(route('portal.descargar', $this->idCert(41)))->assertNotFound();
        $this->assertTrue($this->hayOtp('3000002', 'c@example.test'));
        // El residual solo admite terminales: ni autorizar el correo (ya tiene dueño) ni misma persona.
        $this->assertSame(ResolucionNoPermitida::TIPO_NO_ADMITIDO, $this->rechazo(fn () => $this->svc()->crear($residual->id, $this->a(), 'correo_autorizado', self::MOTIVO, ['grupo' => $this->g('MARIO GOMEZ'), 'correo_hmac' => $hx, 'evidencia' => self::EVID, 'reforzada' => true]))->codigo);

        // Revocar la decisión que daba acceso: la sesión muere y el residual permanece (no se borra nada).
        $dec = (int) DB::table('cf_decisiones_identidad')->where('estado', 'vigente')->value('id');
        $this->svc()->revocar($dec, $this->a(), 'Se retira la decisión por nueva información.');
        $this->get(route('portal.panel'))->assertRedirect(route('portal.inicio'));
        $this->assertNotNull(Conciliacion::find($residual->id));
        $this->assertSame('abierto', $base->fresh()->estado);
    }

    // ── Documento inválido ───────────────────────────────────────────────────

    public function test_un_documento_invalido_solo_admite_terminales_y_no_gana_acceso(): void
    {
        $this->flags();
        $caso = Conciliacion::create(['tipo' => 'identidad_ambigua', 'estado' => 'abierto', 'referencia_tipo' => 'documento', 'referencia_clave' => EvidenciaIdentidad::hashDocumento(''), 'motivo_origen' => 'DOCUMENTO_INVALIDO', 'clave_idempotencia' => 'identidad_ambigua:documento:invalido']);
        $c = $this->especiales()->clasificar($caso);
        $this->assertSame(['documento_invalido', CasosEspeciales::SOPORTE, true, true], [$c['categoria'], $c['destino'], $c['solo_terminales'], $c['admite_evidencia']]);
        $this->assertStringContainsString('no crea ninguna identidad', mb_strtolower((string) $c['siguiente']));
        app(EvidenciaExterna::class)->registrar($caso->id, $this->a(), 'documento_correcto', 'Se recibió el documento correcto de las personas por otro canal.');
        $this->assertSame(CasosEspeciales::SOPORTE, $this->especiales()->clasificar($caso->fresh())['destino']);
        $this->assertSame(0, DB::table('cf_decisiones_identidad')->count(), 'registrar evidencia no crea decisiones');
    }

    // ── Cero acceso indebido y privacidad ────────────────────────────────────

    public function test_ningun_caso_especial_produce_otp_sesion_tarjeta_ni_descarga(): void
    {
        $this->flags();
        $antes = $this->alcances();
        app(DetectorGruposSinVia::class)->ejecutar();
        foreach (Conciliacion::whereIn('motivo_origen', ['GRUPO_SIN_VIA_SIN_CORREO', 'GRUPO_SIN_VIA_EVIDENCIA_EXTERNA'])->get() as $caso) {
            app(EvidenciaExterna::class)->registrar($caso->id, $this->a(), 'otra', 'Evidencia externa de prueba para el caso especial, sin datos personales.');
            $this->svc()->crear($caso->id, $this->a(), 'requiere_soporte', self::MOTIVO);
        }
        $nd = $this->casoDoc('3000010', 'CORREO_CRUZA_GRUPOS');
        $this->svc()->crear($nd->id, $this->a(), 'no_resoluble', self::MOTIVO);

        $this->assertSame($antes, $this->alcances(), 'antes = después para AccesoPortal');
        $this->assertFalse($this->hayOtp('3000010', 'p@example.test'), 'sin vía segura no hay OTP');
        $this->assertFalse($this->hayOtp('3000002', 'x@example.test'));
        $this->assertSame(0, DB::table('cf_decisiones_identidad_correos')->count(), 'ninguna autorización de correo');
        $this->assertSame(0, DB::table('cf_decisiones_identidad')->whereIn('tipo', ['misma_persona', 'correo_autorizado'])->count());
        // Respuesta pública uniforme: el mismo mensaje con y sin vía.
        $sin = $this->post(route('portal.solicitar'), ['documento' => '3000010', 'correo' => 'p@example.test']);
        $inexistente = $this->post(route('portal.solicitar'), ['documento' => '3999999', 'correo' => 'nadie@example.test']);
        $this->assertSame($sin->headers->get('Location'), $inexistente->headers->get('Location'));
    }

    public function test_el_detalle_del_caso_especial_de_identidad_es_enmascarado(): void
    {
        $this->flags();
        app(DetectorGruposSinVia::class)->ejecutar();
        $caso = $this->casoDoc('3000003', 'GRUPO_SIN_VIA_SIN_CORREO');
        app(EvidenciaExterna::class)->registrar($caso->id, $this->a(), 'otra', 'Evidencia de prueba sin datos personales para el detalle.');
        $props = $this->actingAs($this->admin())->get(route('credential-flow.historico.casos.show', $caso->id))->assertOk()->viewData('page')['props']['caso'];
        $this->assertSame('sin_correo', $props['especial']['categoria']);
        $json = json_encode($props, JSON_UNESCAPED_UNICODE);
        foreach (['ar@example.test', '3000003', 'ANA RUIZ'] as $pii) {
            $this->assertStringNotContainsString($pii, $json);
        }
        $this->assertSame(['requiere_soporte', 'no_resoluble'], $props['identidad']['tipos'], 'solo se ofrecen decisiones válidas para esta categoría');
    }

    public function test_los_casos_de_identidad_siguen_sin_pii_en_el_listado(): void
    {
        $this->flags();
        app(DetectorGruposSinVia::class)->ejecutar();
        $props = json_encode($this->actingAs($this->admin())->get(route('credential-flow.historico.casos.index'))->assertOk()->viewData('page')['props'], JSON_UNESCAPED_UNICODE);
        foreach (['@example.test', 'ANA RUIZ', 'PEDRO', 'LUCIA'] as $pii) {
            $this->assertStringNotContainsString($pii, $props);
        }
        $this->assertInstanceOf(DecisionIdentidad::class, new DecisionIdentidad);
    }
}
