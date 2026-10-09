<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Models\CredentialFlow\Conciliacion;
use App\Support\CredentialFlow\Identidad\DecisionesIdentidad;
use App\Support\CredentialFlow\Identidad\EvidenciaIdentidad;
use App\Support\CredentialFlow\Portal\AccesoPortal;
use App\Support\CredentialFlow\Portal\NombreConservador;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

/**
 * Fase 10B-3C-0, flujo completo por HTTP: una decisión `misma_persona` que NO concede no cambia lo que ya entra por el gate histórico (OTP histórico, sesión
 * histórica, panel y descarga idénticos) y nunca cierra el caso. Documento 3000099: S entra con `c` (exclusivo); G solo tiene `x`, compartido con S.
 */
class FallbackGateHistoricoPortalTest extends PortalTestCase
{
    private const DOC = '3000099';

    private int $certS = 0;

    private int $certG = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['credential_flow.identidad.decisiones_enabled' => true, 'credential_flow.identidad.multi_scope_enabled' => true]);
        $molde = (array) DB::table('cf_certificados_legado')->where('id', $this->idCert(40))->first();
        unset($molde['id']);
        $this->certS = DB::table('cf_certificados_legado')->insertGetId(['documento' => self::DOC, 'documento_clave' => self::DOC, 'nombre_completo' => 'LAURA RIOS S', 'evento_id' => 1, 'codigo_legado' => null, 'conciliacion_estado' => 'ok'] + $molde);
        $this->certG = DB::table('cf_certificados_legado')->insertGetId(['documento' => self::DOC, 'documento_clave' => self::DOC, 'nombre_completo' => 'LAURA RIOS G', 'evento_id' => 2, 'codigo_legado' => null, 'conciliacion_estado' => 'ok'] + $molde);
        foreach ([[$this->certS, 'c99@example.test'], [$this->certS, 'x99@example.test'], [$this->certG, 'x99@example.test']] as $i => [$id, $mail]) {
            DB::table('cf_correos')->insert(['certificado_legado_id' => $id, 'correo' => $mail, 'correo_normalizado' => $mail, 'estado' => 'valido', 'orden' => $i + 1, 'es_principal' => 0, 'origen' => 'legado', 'created_at' => now(), 'updated_at' => now()]);
        }
        $caso = Conciliacion::create(['tipo' => 'identidad_ambigua', 'estado' => 'abierto', 'referencia_tipo' => 'documento', 'referencia_clave' => EvidenciaIdentidad::hashDocumento(self::DOC), 'motivo_origen' => 'CORREO_CRUZA_GRUPOS', 'clave_idempotencia' => 'identidad_ambigua:documento:fb-portal']);
        foreach ([$this->certS, $this->certG] as $i => $id) {
            DB::table('cf_conciliaciones_certificados')->insert(['conciliacion_id' => $caso->id, 'certificado_legado_id' => $id, 'rol' => 'grupo_'.($i + 1), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function caso(): Conciliacion
    {
        return Conciliacion::where('referencia_clave', EvidenciaIdentidad::hashDocumento(self::DOC))->firstOrFail();
    }

    private function g(string $n): string
    {
        return NombreConservador::grupoId($n);
    }

    private function ingresar(string $correo): TestResponse
    {
        Mail::fake();
        DB::table('cf_accesos_otp')->delete();
        $this->post(route('portal.solicitar'), ['documento' => self::DOC, 'correo' => $correo])->assertRedirect(route('portal.codigo'));
        $codigo = $this->ultimoCodigo();
        $this->assertNotNull($codigo, 'se esperaba un OTP histórico');

        return $this->validarCodigo($codigo);
    }

    private function tipos(TestResponse $r): array
    {
        preg_match_all('/data-certificado="([a-z_]+)"/', $r->getContent(), $m);

        return $m[1];
    }

    private function misma(): int
    {
        return app(DecisionesIdentidad::class)->crear($this->caso()->id, $this->admin()->id, 'misma_persona', 'Decisión administrativa de prueba.', [
            'grupos' => [$this->g('LAURA RIOS S'), $this->g('LAURA RIOS G')], 'evidencia' => 'Confirmado por el organizador del evento.', 'confirmo' => true, 'evidencia_externa' => true,
        ])['decision_id'];
    }

    public function test_una_misma_persona_que_no_concede_deja_el_flujo_historico_identico_de_extremo_a_extremo(): void
    {
        // ANTES de la decisión.
        $this->ingresar('c99@example.test')->assertRedirect(route('portal.panel'));
        $panelAntes = $this->tipos($this->get(route('portal.panel')));
        $ctxAntes = session('cf_portal.contexto');
        $this->get(route('portal.descargar', $this->certS))->assertOk();
        $this->get(route('portal.descargar', $this->certG))->assertNotFound();
        $this->post(route('portal.salir'));

        $this->misma();   // dos candidatos (c y x): no concede

        // DESPUÉS: mismo OTP histórico (sin scope), misma sesión histórica, mismo panel, misma descarga.
        $this->ingresar('c99@example.test')->assertRedirect(route('portal.panel'));
        $this->assertNull(DB::table('cf_accesos_otp')->value('scope_hash'), 'OTP histórico: scope_hash NULL');
        $ctx = session('cf_portal.contexto');
        $this->assertArrayNotHasKey('scope_hash', $ctx);
        $this->assertArrayNotHasKey('grupos', $ctx);
        $this->assertSame($ctxAntes['grupo'], $ctx['grupo'], 'sesión histórica de un solo grupo');
        $this->assertSame($this->g('LAURA RIOS S'), $ctx['grupo']);
        $this->assertSame($panelAntes, $this->tipos($this->get(route('portal.panel'))));
        $this->assertCount(1, (new AccesoPortal)->tarjetas(self::DOC, $this->g('LAURA RIOS S')));
        $this->get(route('portal.descargar', $this->certS))->assertOk();
        $this->get(route('portal.descargar', $this->certG))->assertNotFound('la decisión no aplicable NO amplía');
        $this->post(route('portal.salir'));

        // El correo compartido sigue sin vía.
        Mail::fake();
        $this->post(route('portal.solicitar'), ['documento' => self::DOC, 'correo' => 'x99@example.test']);
        $this->assertNull($this->ultimoCodigo());
        // La decisión no cerró el caso ni creó eventos de aplicación.
        $this->assertSame(['abierto', null], [$this->caso()->estado, $this->caso()->resolucion]);
        $this->assertSame(0, DB::table('cf_conciliaciones_eventos')->where('accion', 'identidad_acceso_aplicado')->count());
    }

    public function test_con_la_sesion_viva_crear_una_decision_no_aplicable_no_la_afecta(): void
    {
        $this->ingresar('c99@example.test');
        $this->get(route('portal.panel'))->assertOk();

        $this->misma();

        $this->get(route('portal.panel'))->assertOk();
        $this->get(route('portal.descargar', $this->certS))->assertOk();
    }

    public function test_personas_distintas_tampoco_quita_el_acceso_exclusivo(): void
    {
        app(DecisionesIdentidad::class)->crear($this->caso()->id, $this->admin()->id, 'personas_distintas', 'Decisión administrativa de prueba.', ['grupos' => [$this->g('LAURA RIOS S'), $this->g('LAURA RIOS G')], 'confirmo' => true]);

        $this->ingresar('c99@example.test')->assertRedirect(route('portal.panel'));
        $this->get(route('portal.descargar', $this->certS))->assertOk();
        $this->get(route('portal.descargar', $this->certG))->assertNotFound();
        $this->post(route('portal.salir'));
        Mail::fake();
        $this->post(route('portal.solicitar'), ['documento' => self::DOC, 'correo' => 'x99@example.test']);
        $this->assertNull($this->ultimoCodigo(), 'el compartido sigue bloqueado');
    }

    public function test_con_los_interruptores_apagados_todo_es_exactamente_historico(): void
    {
        $this->misma();
        config(['credential_flow.identidad.decisiones_enabled' => false, 'credential_flow.identidad.multi_scope_enabled' => false]);

        $this->ingresar('c99@example.test')->assertRedirect(route('portal.panel'));
        $this->get(route('portal.descargar', $this->certS))->assertOk();
        $this->get(route('portal.descargar', $this->certG))->assertNotFound();
    }
}
