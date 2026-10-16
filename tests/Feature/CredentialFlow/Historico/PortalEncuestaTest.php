<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Models\CredentialFlow\Encuesta;
use App\Models\CredentialFlow\EncuestaOpcion;
use App\Models\CredentialFlow\EncuestaPregunta;
use App\Models\CredentialFlow\EncuestaVersion;
use App\Support\CredentialFlow\Encuestas\EncuestaPortal;
use App\Support\CredentialFlow\Encuestas\ServicioEncuestas;
use App\Support\CredentialFlow\Encuestas\VersionPortal;
use App\Support\CredentialFlow\Portal\AccesoPortal;
use App\Support\CredentialFlow\Portal\Hmac;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fase 13A: encuesta OPCIONAL del portal. Una respuesta por certificado + versión, «Más tarde» solo en sesión, pertenencia por la sesión, 404 genérico
 * y la descarga jamás se bloquea. Versión y datos sintéticos (la del portal es la copia fiel de la «versión 3» histórica de prueba).
 */
class PortalEncuestaTest extends PortalTestCase
{
    /** Encuesta histórica archivada con una versión «actual» y la versión del portal creada como copia fiel. */
    private function encuestaDelPortal(): EncuestaVersion
    {
        $e = Encuesta::create(['nombre' => 'Encuesta histórica', 'estado' => 'archivada', 'activa' => false, 'origen' => 'legado']);
        $v = EncuestaVersion::create(['encuesta_id' => $e->id, 'numero' => 3, 'titulo' => 'Estructura actual', 'publicada_at' => now()->subYear(), 'activa_desde' => now()->subYear(), 'activa_hasta' => now()->subDay()]);
        $p1 = EncuestaPregunta::create(['version_id' => $v->id, 'orden' => 1, 'clave' => 'pregunta1', 'texto' => '¿Cómo califica el desarrollo?', 'tipo' => 'opcion_unica']);
        foreach (['Excelente', 'Bueno', 'Regular'] as $i => $o) {
            EncuestaOpcion::create(['pregunta_id' => $p1->id, 'orden' => $i + 1, 'valor' => $o, 'etiqueta' => $o]);
        }
        $p2 = EncuestaPregunta::create(['version_id' => $v->id, 'orden' => 2, 'clave' => 'pregunta4', 'texto' => '¿Atención adecuada?', 'tipo' => 'opcion_unica']);
        foreach (['Si', 'No'] as $i => $o) {
            EncuestaOpcion::create(['pregunta_id' => $p2->id, 'orden' => $i + 1, 'valor' => $o, 'etiqueta' => $o]);
        }
        EncuestaPregunta::create(['version_id' => $v->id, 'orden' => 3, 'clave' => 'pregunta7', 'texto' => '¿Qué aspectos destacaría?', 'tipo' => 'texto', 'configuracion' => ['max_caracteres' => 40]]);

        return (new VersionPortal)->aplicar();
    }

    private function conSesion(): int
    {
        $this->entrar()->assertRedirect(route('portal.panel'));

        return $this->idCert(1);
    }

    private function valido(): array
    {
        return ['r' => ['pregunta1' => 'Excelente', 'pregunta4' => 'Si', 'pregunta7' => 'Muy buen contenido']];
    }

    public function test_requiere_sesion_del_portal(): void
    {
        $this->encuestaDelPortal();

        $this->get(route('portal.encuesta', $this->idCert(1)))->assertRedirect(route('portal.inicio'));
        $this->post(route('portal.encuesta.guardar', $this->idCert(1)), $this->valido())->assertRedirect(route('portal.inicio'));
        $this->post(route('portal.encuesta.despues', $this->idCert(1)))->assertRedirect(route('portal.inicio'));
        $this->assertSame(0, DB::table('cf_encuestas_respuestas')->count());
    }

    public function test_certificado_ajeno_inexistente_o_no_descargable_es_404_generico(): void
    {
        $this->encuestaDelPortal();
        $this->conSesion();

        $ajeno = $this->get(route('portal.encuesta', $this->idCert(7)));
        $inexistente = $this->get(route('portal.encuesta', 99999999));
        $ajeno->assertNotFound();
        $inexistente->assertNotFound();
        $this->assertSame($this->normalizar($ajeno)[0], $this->normalizar($inexistente)[0]);
        foreach ([16, 17, 18, 19] as $p) {   // en revisión / revocado / reemplazado: sin descarga ⇒ sin encuesta
            $this->get(route('portal.encuesta', $this->idCert($p)))->assertNotFound();
        }
        $this->post(route('portal.encuesta.guardar', $this->idCert(7)), $this->valido())->assertNotFound();
        $this->post(route('portal.encuesta.despues', $this->idCert(7)))->assertNotFound();
        $this->assertSame(0, DB::table('cf_encuestas_respuestas')->count());
    }

    public function test_muestra_el_formulario_de_la_encuesta_activa_con_el_tono_institucional(): void
    {
        $this->encuestaDelPortal();
        $id = $this->conSesion();

        $r = $this->get(route('portal.encuesta', $id))->assertOk();
        $r->assertSee('Evaluación del evento')->assertSee('¿Cómo califica el desarrollo?')->assertSee('Enviar respuestas')->assertSee('Volver a certificados')
            ->assertSee('data-formulario="encuesta"', false)->assertSee('_token', false);
        $this->assertSame(0, preg_match('/\b(tu|tus|usted|ustedes|su|sus)\b/iu', $this->texto($r->getContent())));
    }

    public function test_sin_encuesta_activa_no_se_ofrece_nada(): void
    {
        $id = $this->conSesion();

        $this->get(route('portal.encuesta', $id))->assertRedirect(route('portal.panel'))->assertSessionHas('aviso');
        $this->get(route('portal.panel'))->assertOk()->assertDontSee('data-encuesta="', false)->assertDontSee('<aside', false);
    }

    public function test_version_no_vigente_no_se_ofrece_ni_se_guarda(): void
    {
        $v = $this->encuestaDelPortal();
        $id = $this->conSesion();
        $v->update(['activa_hasta' => now()->subMinute()]);

        $this->get(route('portal.encuesta', $id))->assertRedirect(route('portal.panel'));
        $this->post(route('portal.encuesta.guardar', $id), $this->valido())->assertRedirect(route('portal.panel'))->assertSessionHas('aviso');
        $this->assertSame(0, DB::table('cf_encuestas_respuestas')->count());
    }

    public function test_guarda_la_respuesta_con_origen_credential_flow_y_sin_bloquear_nada(): void
    {
        $v = $this->encuestaDelPortal();
        $id = $this->conSesion();

        $this->post(route('portal.encuesta.guardar', $id), $this->valido())->assertRedirect(route('portal.panel'))->assertSessionHas('exito', 'Gracias por compartir la experiencia. La información recibida contribuye a mejorar nuestros eventos y servicios.');

        $r = DB::table('cf_encuestas_respuestas')->first();
        $this->assertSame(1, DB::table('cf_encuestas_respuestas')->count());
        $this->assertSame('credential_flow', $r->origen);
        $this->assertSame($v->id, (int) $r->version_id);
        $this->assertSame($id, (int) $r->certificado_legado_id);
        $this->assertNull($r->emision_id);
        $this->assertNotNull($r->evento_id);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $r->documento_hash);
        $this->assertSame(3, DB::table('cf_encuestas_respuestas_detalle')->where('respuesta_id', $r->id)->count());
        $this->assertStringNotContainsString('1000001', json_encode($r));
    }

    public function test_una_respuesta_por_certificado_y_version_y_deja_de_ofrecerse(): void
    {
        $this->encuestaDelPortal();
        $id = $this->conSesion();
        $this->post(route('portal.encuesta.guardar', $id), $this->valido());

        $this->post(route('portal.encuesta.guardar', $id), $this->valido())->assertRedirect(route('portal.panel'))->assertSessionHas('aviso', 'La encuesta de este evento ya fue respondida.');
        $this->assertSame(1, DB::table('cf_encuestas_respuestas')->count());
        $this->get(route('portal.encuesta', $id))->assertRedirect(route('portal.panel'));

        $panel = $this->get(route('portal.panel'))->assertOk();
        $panel->assertSee('Encuesta respondida')->assertDontSee('Evaluar evento')->assertDontSee('<aside', false);
    }

    /** Inserta una respuesta (histórica o moderna) con el vínculo indicado; devuelve su id. */
    private function respuesta(int $version, ?int $certificado, string $origen = 'legado', ?string $clasificacion = null, ?int $evento = null, ?string $hash = null): int
    {
        return (int) DB::table('cf_encuestas_respuestas')->insertGetId([
            'version_id' => $version, 'certificado_legado_id' => $certificado, 'evento_id' => $evento, 'documento_hash' => $hash, 'clasificacion' => $clasificacion,
            'completada_at' => now()->subYear(), 'origen' => $origen, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_respuesta_historica_vinculada_da_el_certificado_por_respondido_en_toda_la_ui(): void
    {
        $this->encuestaDelPortal();
        $id = $this->conSesion();
        $v3 = EncuestaVersion::where('numero', 3)->first();
        $this->respuesta($v3->id, $id, 'legado', 'vinculada');

        $panel = $this->get(route('portal.panel'))->assertOk();
        $panel->assertSee('Encuesta respondida')->assertDontSee('Evaluar evento')->assertDontSee('Responder encuesta')->assertDontSee('<aside', false);
        $this->get(route('portal.encuesta', $id))->assertRedirect(route('portal.panel'))->assertSessionHas('aviso', 'La encuesta de este evento ya fue respondida.');
        // Tampoco se puede guardar una respuesta moderna, aunque la versión sea otra (continuidad de la encuesta histórica).
        $this->post(route('portal.encuesta.guardar', $id), $this->valido())->assertRedirect(route('portal.panel'))->assertSessionHas('aviso', 'La encuesta de este evento ya fue respondida.');
        $this->assertSame(1, DB::table('cf_encuestas_respuestas')->count());   // el histórico no se toca y no se crea nada
        // Y la descarga sigue funcionando sin invitación.
        $this->get(route('portal.descargar', $id))->assertOk();
    }

    public function test_cualquier_origen_y_version_vinculados_bloquean_la_oferta(): void
    {
        $v4 = $this->encuestaDelPortal();
        $id = $this->conSesion();
        $this->respuesta($v4->id, $id, 'credential_flow');   // moderna de la propia versión del portal

        $this->get(route('portal.panel'))->assertOk()->assertSee('Encuesta respondida')->assertDontSee('Evaluar evento');
        $this->get(route('portal.encuesta', $id))->assertRedirect(route('portal.panel'));
    }

    public function test_respuesta_de_la_copia_consolidada_del_mismo_grupo_cuenta_como_respondida(): void
    {
        $this->encuestaDelPortal();
        $this->entrar('2000001', 'gina@example.test')->assertRedirect(route('portal.panel'));
        $canonico = $this->idCert(7);
        $copia = (int) DB::table('cf_certificados_legado')->where('conciliacion_estado', 'duplicado_consolidado')->where('documento_clave', '2000001')->value('id');
        $v3 = EncuestaVersion::where('numero', 3)->first();
        $this->respuesta($v3->id, $copia, 'legado', 'vinculada');   // la respuesta quedó ligada a la copia del mismo certificado lógico

        $this->get(route('portal.panel'))->assertOk()->assertSee('Encuesta respondida')->assertDontSee('Evaluar evento');
        $this->get(route('portal.encuesta', $canonico))->assertRedirect(route('portal.panel'));
    }

    public function test_respuestas_ambiguas_o_sin_vinculo_no_bloquean_certificados_concretos(): void
    {
        $this->encuestaDelPortal();
        $id = $this->conSesion();
        $v3 = EncuestaVersion::where('numero', 3)->first();
        $evento = (int) DB::table('cf_certificados_legado')->where('id', $id)->value('evento_id');
        $hash = Hmac::de('documento', AccesoPortal::claveDocumento(self::DOCUMENTO));
        // Mismo evento y mismo documento (HMAC) pero SIN vínculo con el certificado: no se infiere nada.
        $this->respuesta($v3->id, null, 'legado', 'participante_ambiguo', $evento, $hash);
        $this->respuesta($v3->id, null, 'legado', 'participante_no_encontrado', $evento, $hash);
        $this->respuesta($v3->id, null, 'legado', 'evento_historico_eliminado', null, $hash);

        $this->get(route('portal.panel'))->assertOk()->assertSee('Evaluar evento')->assertDontSee('Encuesta respondida');
        $this->post(route('portal.encuesta.guardar', $id), $this->valido())->assertRedirect(route('portal.panel'))->assertSessionHas('exito');
        $this->assertSame(1, DB::table('cf_encuestas_respuestas')->where('origen', 'credential_flow')->count());
        $this->assertSame(3, DB::table('cf_encuestas_respuestas')->where('origen', 'legado')->count());   // el histórico queda intacto
    }

    public function test_certificado_sin_respuesta_puede_responder_la_version_del_portal_y_otro_respondido_no_lo_afecta(): void
    {
        $v4 = $this->encuestaDelPortal();
        $this->entrar('2000001', 'gina@example.test')->assertRedirect(route('portal.panel'));
        $otro = (int) DB::table('cf_certificados_legado')->where('documento_clave', '1000001')->orderBy('id')->value('id');
        $this->respuesta(EncuestaVersion::where('numero', 3)->first()->id, $otro, 'legado', 'vinculada');   // un certificado AJENO ya respondido

        $this->get(route('portal.panel'))->assertOk()->assertSee('Evaluar evento')->assertSee('<aside', false);
        $this->assertSame(1, preg_match('#/certificados/encuesta/(\d+)#', $this->get(route('portal.panel'))->getContent(), $m));
        $this->post(route('portal.encuesta.guardar', (int) $m[1]), $this->valido())->assertRedirect(route('portal.panel'))->assertSessionHas('exito');
        $this->assertSame(1, DB::table('cf_encuestas_respuestas')->where('version_id', $v4->id)->count());
    }

    public function test_mas_tarde_solo_vive_en_la_sesion_y_no_escribe_en_base_de_datos(): void
    {
        $this->encuestaDelPortal();
        $id = $this->conSesion();
        $this->get(route('portal.panel'))->assertOk()->assertSee('Evaluar evento')->assertSee('<aside', false)->assertSee('Más tarde');
        $antes = [DB::table('cf_encuestas_respuestas')->count(), DB::table('cf_encuestas_respuestas_detalle')->count()];

        $this->post(route('portal.encuesta.despues', $id))->assertRedirect(route('portal.panel'));

        $this->get(route('portal.panel'))->assertOk()->assertDontSee('Evaluar evento')->assertDontSee('<aside', false);
        $this->assertSame($antes, [DB::table('cf_encuestas_respuestas')->count(), DB::table('cf_encuestas_respuestas_detalle')->count()]);
        // Vive solo en la sesión: al cerrarla la preferencia se pierde (la próxima sesión vuelve a ofrecer la encuesta).
        $this->assertContains($id, session(EncuestaPortal::SESION_DESPUES));
        $this->post(route('portal.salir'));
        $this->assertNull(session(EncuestaPortal::SESION_DESPUES));
    }

    public function test_la_descarga_no_se_bloquea_con_encuesta_pendiente(): void
    {
        $this->encuestaDelPortal();
        $id = $this->conSesion();

        $this->get(route('portal.descargar', $id))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(1, DB::table('cf_descargas')->where('origen', 'credential_flow')->count());
        $this->assertSame(0, DB::table('cf_encuestas_respuestas')->count());
    }

    public function test_rechaza_opcion_invalida_clave_desconocida_y_texto_largo_sin_guardar(): void
    {
        $this->encuestaDelPortal();
        $id = $this->conSesion();

        $malas = [
            'opcion invalida' => ['r' => ['pregunta1' => 'Pésimo', 'pregunta4' => 'Si']],
            'clave desconocida' => ['r' => ['pregunta1' => 'Excelente', 'pregunta99' => 'x']],
            'texto largo' => ['r' => ['pregunta1' => 'Excelente', 'pregunta7' => str_repeat('x', 41)]],
            'campo fuera de r' => ['r' => ['pregunta1' => 'Excelente'], 'otro' => 'x'],
            'r no es arreglo' => ['r' => 'Excelente'],
        ];
        foreach ($malas as $caso => $datos) {
            $this->post(route('portal.encuesta.guardar', $id), $datos)->assertRedirect(route('portal.encuesta', $id))->assertSessionHas('errores_encuesta');
            $this->assertSame(0, DB::table('cf_encuestas_respuestas')->count(), $caso);
        }
        $this->get(route('portal.encuesta', $id))->assertOk();
    }

    private function limites(int $sesion, int $identidad, int $ip): void
    {
        config([
            'credential_flow.portal.limite_encuesta_por_sesion_por_minuto' => $sesion,
            'credential_flow.portal.limite_encuesta_por_identidad_por_minuto' => $identidad,
            'credential_flow.portal.limite_encuesta_por_ip_por_minuto' => $ip,
        ]);
    }

    public function test_limite_por_sesion_y_no_afecta_la_descarga_ni_el_panel(): void
    {
        $this->encuestaDelPortal();
        $id = $this->conSesion();
        $this->limites(2, 100, 100);

        $this->get(route('portal.encuesta', $id))->assertOk();
        $this->get(route('portal.encuesta', $id))->assertOk();
        $this->get(route('portal.encuesta', $id))->assertStatus(429);
        $this->post(route('portal.encuesta.guardar', $id), $this->valido())->assertStatus(429);
        $this->post(route('portal.encuesta.despues', $id))->assertStatus(429);
        $this->get(route('portal.descargar', $id))->assertOk();          // descarga: límite propio
        $this->get(route('portal.panel'))->assertOk();                   // el panel no cuenta contra la encuesta
        $this->assertSame(0, DB::table('cf_encuestas_respuestas')->count());
    }

    public function test_limite_por_identidad_no_se_evade_cambiando_ip_ni_agente(): void
    {
        $this->encuestaDelPortal();
        $id = $this->conSesion();
        $this->limites(100, 3, 100);

        foreach (['10.1.0.1' => 'Agente-A', '10.1.0.2' => 'Agente-B', '10.1.0.3' => 'Agente-C'] as $ip => $agente) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->withHeaders(['User-Agent' => $agente])->get(route('portal.encuesta', $id))->assertOk();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.0.4'])->withHeaders(['User-Agent' => 'Agente-D'])->get(route('portal.encuesta', $id))->assertStatus(429);
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.0.5'])->withHeaders(['X-Forwarded-For' => '10.9.9.9', 'User-Agent' => 'Otro'])->get(route('portal.encuesta', $id))->assertStatus(429);
    }

    public function test_personas_distintas_detras_de_la_misma_ip_no_se_bloquean_entre_si(): void
    {
        $this->encuestaDelPortal();
        $ana = $this->conSesion();
        $this->limites(100, 2, 100);
        $ip = ['REMOTE_ADDR' => '172.16.0.9'];
        $this->withServerVariables($ip)->get(route('portal.encuesta', $ana))->assertOk();
        $this->withServerVariables($ip)->get(route('portal.encuesta', $ana))->assertOk();
        $this->withServerVariables($ip)->get(route('portal.encuesta', $ana))->assertStatus(429);

        // Otra persona, MISMA IP: sigue pudiendo usar la encuesta.
        $this->post(route('portal.salir'));
        $this->withServerVariables($ip)->entrar('2000001', 'gina@example.test')->assertRedirect(route('portal.panel'));
        $this->assertSame(1, preg_match('#/certificados/encuesta/(\d+)#', $this->withServerVariables($ip)->get(route('portal.panel'))->getContent(), $m));
        $this->withServerVariables($ip)->get(route('portal.encuesta', (int) $m[1]))->assertOk();
    }

    public function test_el_tope_por_ip_es_holgado_pero_existe(): void
    {
        $this->encuestaDelPortal();
        $id = $this->conSesion();
        $this->limites(100, 100, 2);

        $this->get(route('portal.encuesta', $id))->assertOk();
        $this->get(route('portal.encuesta', $id))->assertOk();
        $this->get(route('portal.encuesta', $id))->assertStatus(429);
    }

    public function test_certificado_ajeno_sigue_siendo_404_dentro_del_limite_y_cuenta_contra_el_mismo(): void
    {
        $this->encuestaDelPortal();
        $this->conSesion();
        $this->limites(2, 100, 100);

        $this->get(route('portal.encuesta', $this->idCert(7)))->assertNotFound();
        $this->get(route('portal.encuesta', $this->idCert(7)))->assertNotFound();
        $this->get(route('portal.encuesta', $this->idCert(7)))->assertStatus(429);   // sondear certificados ajenos agota el cupo
    }

    public function test_el_panel_marca_elegibles_y_solo_esos(): void
    {
        $this->encuestaDelPortal();
        $this->conSesion();

        $html = $this->get(route('portal.panel'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-encuesta="evaluar"'));   // un solo certificado descargable en el panel de prueba
        $this->assertStringContainsString('Evaluación del evento', $html);
        $this->assertStringContainsString('Responder encuesta', $html);
        $this->assertStringContainsString('hidden', $html);
        $this->assertSame(0, preg_match('/\b(tu|tus|usted|ustedes|su|sus)\b/iu', $this->texto($html)));
    }

    public function test_pantallas_de_acceso_sin_tuteo_ni_usted(): void
    {
        $this->get(route('portal.inicio'))->assertOk();
        $inicio = $this->texto($this->get(route('portal.inicio'))->getContent());
        $this->solicitar('1.000.001', self::CORREO);
        $codigo = $this->texto($this->get(route('portal.codigo'))->getContent());

        foreach ([$inicio, $codigo] as $t) {
            $this->assertSame(0, preg_match('/\b(tu|tus|tuyo|usted|ustedes|su|sus|revisa|ingresa|escribe|escríbenos|cuéntanos|puedes|vuelve)\b/iu', $t), $t);
        }
        $this->assertStringContainsString('Número de documento', $inicio);
        $this->assertStringContainsString('Código de acceso', $codigo);
    }

    // ── Versión del portal (comando y reversión) ─────────────────────────────

    public function test_la_version_del_portal_es_copia_fiel_y_no_toca_el_historico(): void
    {
        $v4 = $this->encuestaDelPortal();
        $v3 = EncuestaVersion::where('numero', 3)->first();

        $this->assertSame(4, (int) $v4->numero);
        $this->assertSame(
            EncuestaPregunta::where('version_id', $v3->id)->orderBy('orden')->get(['orden', 'clave', 'texto', 'tipo', 'obligatoria', 'activa'])->toArray(),
            EncuestaPregunta::where('version_id', $v4->id)->orderBy('orden')->get(['orden', 'clave', 'texto', 'tipo', 'obligatoria', 'activa'])->toArray()
        );
        $opciones = fn (int $version) => DB::table('cf_encuestas_opciones as o')->join('cf_encuestas_preguntas as p', 'p.id', '=', 'o.pregunta_id')->where('p.version_id', $version)
            ->orderBy('p.orden')->orderBy('o.orden')->get(['p.clave', 'o.valor', 'o.etiqueta', 'o.activa'])->map(fn ($x) => (array) $x)->all();
        $this->assertSame($opciones($v3->id), $opciones($v4->id));
        $this->assertSame(5, count($opciones($v4->id)));
        $this->assertNotNull($v3->fresh()->activa_hasta);   // la versión 3 no se reabre
        $this->assertSame($v4->id, (new ServicioEncuestas)->activa()->id);
        $this->assertFalse((bool) $v4->obligatoria);
    }

    public function test_la_vigencia_de_la_version_del_portal_es_propia_y_no_copia_la_vencida(): void
    {
        $this->travelTo(now()->setMicrosecond(0));
        $v4 = $this->encuestaDelPortal();
        $v3 = EncuestaVersion::where('numero', 3)->first();

        $this->assertNotNull($v3->activa_hasta);                        // la histórica quedó vencida y no se reabre
        $this->assertTrue($v3->activa_hasta->lt(now()));
        $this->assertNull($v4->activa_hasta);                           // sin vencimiento
        $this->assertTrue($v4->activa_desde->equalTo(now()));           // vigente desde la ejecución
        $this->assertFalse($v4->activa_desde->equalTo($v3->activa_desde));
        $this->assertNotNull($v4->publicada_at);
        $this->assertSame($v4->id, (new ServicioEncuestas)->activa(now()->addYears(5))->id);   // y no caduca
    }

    public function test_comando_simular_no_escribe_y_la_ejecucion_es_idempotente(): void
    {
        $e = Encuesta::create(['nombre' => 'Histórica', 'estado' => 'archivada', 'activa' => false, 'origen' => 'legado']);
        $v = EncuestaVersion::create(['encuesta_id' => $e->id, 'numero' => 3, 'titulo' => 'Actual', 'publicada_at' => now()->subYear(), 'activa_desde' => now()->subYear(), 'activa_hasta' => now()->subDay()]);
        EncuestaPregunta::create(['version_id' => $v->id, 'orden' => 1, 'clave' => 'pregunta5', 'texto' => '¿Por qué?', 'tipo' => 'texto']);

        $this->artisan('credential-flow:encuestas:version-portal')->assertFailed();
        $this->artisan('credential-flow:encuestas:version-portal', ['--simular' => true, '--confirmar' => true])->assertFailed();
        $this->artisan('credential-flow:encuestas:version-portal', ['--simular' => true])->assertSuccessful();
        $this->assertSame(1, EncuestaVersion::count());
        $this->assertFalse((bool) $e->fresh()->activa);

        $this->artisan('credential-flow:encuestas:version-portal', ['--confirmar' => true])->assertSuccessful();
        $this->artisan('credential-flow:encuestas:version-portal', ['--confirmar' => true])->assertSuccessful();
        $this->assertSame(2, EncuestaVersion::count());
        $this->assertTrue((bool) $e->fresh()->activa);
        $this->assertSame('publicada', $e->fresh()->estado);
    }

    public function test_reversion_solo_sin_respuestas(): void
    {
        $v4 = $this->encuestaDelPortal();
        $id = $this->conSesion();
        $this->post(route('portal.encuesta.guardar', $id), $this->valido())->assertSessionHas('exito');

        $this->artisan('credential-flow:encuestas:version-portal-rollback')->assertFailed();
        $this->artisan('credential-flow:encuestas:version-portal-rollback', ['--confirmar' => true])->assertFailed();
        $this->assertNotNull(EncuestaVersion::find($v4->id));
        $this->assertSame(1, DB::table('cf_encuestas_respuestas')->count());

        DB::table('cf_encuestas_respuestas_detalle')->delete();
        DB::table('cf_encuestas_respuestas')->delete();
        $this->artisan('credential-flow:encuestas:version-portal-rollback', ['--confirmar' => true])->assertSuccessful();

        $this->assertNull(EncuestaVersion::find($v4->id));
        $this->assertSame(0, EncuestaPregunta::where('version_id', $v4->id)->count());
        $this->assertSame(1, EncuestaVersion::count());          // queda solo la histórica
        $enc = Encuesta::first();
        $this->assertFalse((bool) $enc->activa);
        $this->assertSame('archivada', $enc->estado);
        $this->assertNull((new ServicioEncuestas)->activa());
    }

    // ── Duplicados consolidados, concurrencia y despliegue sin versión del portal ──

    /** Gina (2000001) tiene un certificado lógico con dos filas: devuelve [id de la tarjeta del panel, id de la otra fila]. */
    private function tarjetaYCopiaDeGina(): array
    {
        $this->entrar('2000001', 'gina@example.test')->assertRedirect(route('portal.panel'));
        $this->assertSame(1, preg_match('#/certificados/encuesta/(\d+)#', $this->get(route('portal.panel'))->getContent(), $m));
        $tarjeta = (int) $m[1];
        $otra = (int) DB::table('cf_certificados_legado')->where('documento_clave', '2000001')->where('id', '!=', $tarjeta)->value('id');
        $this->assertGreaterThan(0, $otra);
        $this->assertSame(
            DB::table('cf_certificados_legado')->where('id', $tarjeta)->value('grupo_duplicado'),
            DB::table('cf_certificados_legado')->where('id', $otra)->value('grupo_duplicado')
        );

        return [$tarjeta, $otra];
    }

    public function test_dos_filas_del_mismo_grupo_consolidado_no_permiten_dos_encuestas(): void
    {
        $v4 = $this->encuestaDelPortal();
        [$tarjeta, $otra] = $this->tarjetaYCopiaDeGina();

        // La otra fila del grupo no es una tarjeta del panel: no se puede abrir ni responder por ella.
        $this->get(route('portal.encuesta', $otra))->assertNotFound();
        $this->post(route('portal.encuesta.guardar', $otra), $this->valido())->assertNotFound();
        $this->post(route('portal.encuesta.guardar', $tarjeta), $this->valido())->assertRedirect(route('portal.panel'))->assertSessionHas('exito');
        $this->post(route('portal.encuesta.guardar', $tarjeta), $this->valido())->assertRedirect(route('portal.panel'))->assertSessionHas('aviso', 'La encuesta de este evento ya fue respondida.');
        $this->post(route('portal.encuesta.guardar', $otra), $this->valido())->assertNotFound();

        $this->assertSame(1, DB::table('cf_encuestas_respuestas')->where('version_id', $v4->id)->count());
        $this->get(route('portal.panel'))->assertOk()->assertSee('Encuesta respondida')->assertDontSee('Evaluar evento');
    }

    public function test_una_respuesta_historica_en_cualquier_fila_del_grupo_bloquea_la_nueva(): void
    {
        $this->encuestaDelPortal();
        [$tarjeta, $otra] = $this->tarjetaYCopiaDeGina();
        $this->respuesta(EncuestaVersion::where('numero', 3)->first()->id, $otra, 'legado', 'vinculada');

        $this->post(route('portal.encuesta.guardar', $tarjeta), $this->valido())->assertRedirect(route('portal.panel'))->assertSessionHas('aviso', 'La encuesta de este evento ya fue respondida.');
        $this->assertSame(0, DB::table('cf_encuestas_respuestas')->where('origen', 'credential_flow')->count());
    }

    public function test_solicitudes_simultaneas_del_mismo_certificado_logico_se_serializan(): void
    {
        $v4 = $this->encuestaDelPortal();
        [$tarjeta, $otra] = $this->tarjetaYCopiaDeGina();
        $portal = app(EncuestaPortal::class);
        // Ambas filas comparten la misma clave de exclusión (grupo + documento); un certificado sin grupo usa la suya.
        $this->assertSame($portal->claveEvento($tarjeta), $portal->claveEvento($otra));
        $this->assertNotSame($portal->claveEvento($tarjeta), $portal->claveEvento($this->idCert(1)));

        // Otra solicitud (otro proceso) conserva el candado: esta no guarda nada y no se corrompe nada.
        $candado = Cache::lock('cf-portal-encuesta:'.$portal->claveEvento($tarjeta), 30);
        $this->assertTrue($candado->get());
        $this->post(route('portal.encuesta.guardar', $tarjeta), $this->valido())->assertRedirect(route('portal.encuesta', $tarjeta))->assertSessionHas('errores_encuesta');
        $this->assertSame(0, DB::table('cf_encuestas_respuestas')->where('version_id', $v4->id)->count());

        // Liberado el candado, la primera solicitud guarda y la siguiente ya ve la respuesta.
        $candado->release();
        $this->post(route('portal.encuesta.guardar', $tarjeta), $this->valido())->assertSessionHas('exito');
        $this->post(route('portal.encuesta.guardar', $tarjeta), $this->valido())->assertSessionHas('aviso');
        $this->assertSame(1, DB::table('cf_encuestas_respuestas')->where('version_id', $v4->id)->count());
    }

    public function test_la_version_del_portal_replica_siete_preguntas_y_diecisiete_opciones(): void
    {
        $e = Encuesta::create(['nombre' => 'Encuesta histórica', 'estado' => 'archivada', 'activa' => false, 'origen' => 'legado']);
        $v3 = EncuestaVersion::create(['encuesta_id' => $e->id, 'numero' => 3, 'titulo' => 'Actual', 'publicada_at' => now()->subYear(), 'activa_desde' => now()->subYear(), 'activa_hasta' => now()->subDay()]);
        $cinco = ['Excelente', 'Muy bueno', 'Bueno', 'Regular', 'Deficiente'];
        $estructura = [
            ['pregunta1', 'opcion_unica', $cinco], ['pregunta2', 'opcion_unica', $cinco], ['pregunta3', 'opcion_unica', $cinco],
            ['pregunta4', 'opcion_unica', ['Si', 'No']], ['pregunta5', 'texto', []], ['pregunta6', 'texto', []], ['pregunta7', 'texto', []],
        ];
        foreach ($estructura as $i => [$clave, $tipo, $opciones]) {
            $p = EncuestaPregunta::create(['version_id' => $v3->id, 'orden' => $i + 1, 'clave' => $clave, 'texto' => "Texto {$clave}", 'tipo' => $tipo,
                'configuracion' => $tipo === 'texto' ? ['max_caracteres' => 500] : null]);
            foreach ($opciones as $j => $o) {
                EncuestaOpcion::create(['pregunta_id' => $p->id, 'orden' => $j + 1, 'valor' => $o, 'etiqueta' => $o]);
            }
        }

        $servicio = new VersionPortal;
        $plan = $servicio->plan();
        $this->assertSame([7, 17, 'crear', 4], [$plan['preguntas'], $plan['opciones'], $plan['accion'], $plan['numero']]);

        $v4 = $servicio->aplicar();
        $this->assertSame(7, EncuestaPregunta::where('version_id', $v4->id)->count());
        $this->assertSame(17, DB::table('cf_encuestas_opciones as o')->join('cf_encuestas_preguntas as p', 'p.id', '=', 'o.pregunta_id')->where('p.version_id', $v4->id)->count());
        $this->assertSame(
            EncuestaPregunta::where('version_id', $v3->id)->orderBy('orden')->get(['orden', 'clave', 'tipo', 'configuracion'])->toArray(),
            EncuestaPregunta::where('version_id', $v4->id)->orderBy('orden')->get(['orden', 'clave', 'tipo', 'configuracion'])->toArray()
        );
        // Idempotente: repetir no crea otra versión ni duplica preguntas.
        $this->assertSame($v4->id, $servicio->aplicar()->id);
        $this->assertSame(2, EncuestaVersion::count());
        $this->assertSame(14, EncuestaPregunta::count());
        $this->assertSame('crear', $plan['accion']);
        $this->assertSame('sin_cambios', $servicio->plan()['accion']);
        // La versión 3 y el histórico quedan intactos.
        $this->assertSame(7, EncuestaPregunta::where('version_id', $v3->id)->count());
        $this->assertNotNull($v3->fresh()->activa_hasta);
    }

    public function test_sin_la_version_del_portal_el_codigo_nuevo_no_altera_el_portal_ni_escribe_datos(): void
    {
        // Encuesta histórica archivada y SIN versión del portal: es el estado de producción al desplegar solo el código.
        $e = Encuesta::create(['nombre' => 'Encuesta histórica', 'estado' => 'archivada', 'activa' => false, 'origen' => 'legado']);
        $v = EncuestaVersion::create(['encuesta_id' => $e->id, 'numero' => 3, 'titulo' => 'Actual', 'publicada_at' => now()->subYear(), 'activa_desde' => now()->subYear(), 'activa_hasta' => now()->subDay()]);
        EncuestaPregunta::create(['version_id' => $v->id, 'orden' => 1, 'clave' => 'pregunta5', 'texto' => 'Texto', 'tipo' => 'texto']);
        $antes = [Encuesta::count(), EncuestaVersion::count(), EncuestaPregunta::count(), DB::table('cf_encuestas_respuestas')->count(), DB::table('cf_descargas')->count()];
        $id = $this->conSesion();

        $panel = $this->get(route('portal.panel'))->assertOk();
        $panel->assertSee('Certificados disponibles')->assertSee('Descargar PDF')->assertSee('Verificar')
            ->assertDontSee('Evaluar evento')->assertDontSee('Encuesta respondida')->assertDontSee('<aside', false)->assertDontSee('data-encuesta="', false);
        $this->assertNull((new ServicioEncuestas)->activa());
        $this->get(route('portal.encuesta', $id))->assertRedirect(route('portal.panel'))->assertSessionHas('aviso', 'La encuesta no está disponible en este momento.');
        $this->post(route('portal.encuesta.guardar', $id), $this->valido())->assertRedirect(route('portal.panel'));
        $this->post(route('portal.encuesta.despues', $id))->assertRedirect(route('portal.panel'));
        $this->get(route('portal.descargar', $id))->assertOk()->assertHeader('Content-Type', 'application/pdf');   // la descarga no depende de la encuesta

        $this->assertSame(array_slice($antes, 0, 4), [Encuesta::count(), EncuestaVersion::count(), EncuestaPregunta::count(), DB::table('cf_encuestas_respuestas')->count()]);
        $this->assertFalse((bool) $e->fresh()->activa);
        $this->assertSame('archivada', $e->fresh()->estado);
    }

    // ── Fase 14B: una respuesta por persona y evento; formulario vacío ──────────────────────────

    /** Clona el certificado descargable de la persona de prueba: del MISMO evento (otro certificado sin consolidar) o de un evento nuevo. */
    private function clonar(bool $mismoEvento, string $nombreEvento = 'Evento Sintético'): int
    {
        $base = DB::table('cf_certificados_legado')->where('id', $this->idCert(1))->first();
        $c = (array) $base;
        unset($c['id']);
        if (! $mismoEvento) {
            $e = (array) DB::table('cf_eventos')->where('id', $base->evento_id)->first();
            unset($e['id']);
            $e['nombre'] = $nombreEvento;
            $e['nombre_normalizado'] = mb_strtolower(Str::ascii($nombreEvento));
            $e['anio'] = 2026;
            $c['evento_id'] = DB::table('cf_eventos')->insertGetId($e);
        }
        $c['codigo_legado'] = null;
        $c['grupo_duplicado'] = null;
        if (array_key_exists('old_id', $c)) {
            $c['old_id'] = 900000 + random_int(1, 99999);
        }

        return (int) DB::table('cf_certificados_legado')->insertGetId($c);
    }

    private function hashPersona(): string
    {
        return Hmac::de('documento', AccesoPortal::claveDocumento(self::DOCUMENTO));
    }

    private function contar(string $texto, string $html): int
    {
        return substr_count($html, $texto);
    }

    public function test_dos_certificados_del_mismo_evento_sin_consolidar_comparten_una_sola_respuesta(): void
    {
        $this->encuestaDelPortal();
        $a = $this->conSesion();
        $b = $this->clonar(true);
        $html = $this->get(route('portal.panel'))->assertOk()->getContent();
        $this->assertSame(2, $this->contar('data-encuesta="evaluar"', $html));   // dos tarjetas del mismo evento, ambas pendientes

        $this->post(route('portal.encuesta.guardar', $a), $this->valido())->assertRedirect(route('portal.panel'))->assertSessionHas('exito');

        $html = $this->get(route('portal.panel'))->assertOk()->getContent();
        $this->assertSame(0, $this->contar('data-encuesta="evaluar"', $html));
        $this->assertSame(2, $this->contar('data-encuesta="respondida"', $html));   // el evento quedó respondido en ambas tarjetas
        $this->get(route('portal.encuesta', $b))->assertRedirect(route('portal.panel'))->assertSessionHas('aviso', 'La encuesta de este evento ya fue respondida.');
        $this->post(route('portal.encuesta.guardar', $b), $this->valido())->assertRedirect(route('portal.panel'))->assertSessionHas('aviso', 'La encuesta de este evento ya fue respondida.');
        $this->assertSame(1, DB::table('cf_encuestas_respuestas')->where('origen', 'credential_flow')->count());
    }

    public function test_la_misma_persona_responde_eventos_distintos_de_forma_independiente(): void
    {
        $v4 = $this->encuestaDelPortal();
        $a = $this->conSesion();
        $b = $this->clonar(false, 'Evento B');
        $c = $this->clonar(false, 'Evento C');

        $this->post(route('portal.encuesta.guardar', $a), $this->valido())->assertSessionHas('exito');
        $html = $this->get(route('portal.panel'))->assertOk()->getContent();
        $this->assertSame(1, $this->contar('data-encuesta="respondida"', $html));
        $this->assertSame(2, $this->contar('data-encuesta="evaluar"', $html));   // B y C siguen disponibles

        $this->post(route('portal.encuesta.guardar', $b), $this->valido())->assertSessionHas('exito');
        $this->post(route('portal.encuesta.guardar', $c), $this->valido())->assertSessionHas('exito');
        $this->post(route('portal.encuesta.guardar', $b), $this->valido())->assertSessionHas('aviso', 'La encuesta de este evento ya fue respondida.');

        $filas = DB::table('cf_encuestas_respuestas')->where('version_id', $v4->id)->get();
        $this->assertCount(3, $filas);
        $this->assertCount(3, $filas->pluck('evento_id')->unique());
        $this->assertSame([$this->hashPersona()], $filas->pluck('documento_hash')->unique()->values()->all());
    }

    public function test_una_respuesta_moderna_de_otra_version_impide_responder_el_mismo_evento(): void
    {
        $this->encuestaDelPortal();
        $a = $this->conSesion();
        $b = $this->clonar(true);
        $otraVersion = EncuestaVersion::create(['encuesta_id' => Encuesta::first()->id, 'numero' => 5, 'titulo' => 'Otra', 'publicada_at' => now()->subDay(), 'activa_desde' => now()->subDay(), 'activa_hasta' => now()->subHour()]);
        $evento = (int) DB::table('cf_certificados_legado')->where('id', $a)->value('evento_id');
        // Respuesta moderna de OTRA versión, de la misma persona y del mismo evento, sin vínculo con ninguno de los dos certificados.
        $this->respuesta($otraVersion->id, null, 'credential_flow', null, $evento, $this->hashPersona());

        $html = $this->get(route('portal.panel'))->assertOk()->getContent();
        $this->assertSame(2, $this->contar('data-encuesta="respondida"', $html));
        $this->assertSame(0, $this->contar('data-encuesta="evaluar"', $html));
        $this->post(route('portal.encuesta.guardar', $b), $this->valido())->assertSessionHas('aviso', 'La encuesta de este evento ya fue respondida.');
        $this->assertSame(1, DB::table('cf_encuestas_respuestas')->count());
    }

    public function test_una_respuesta_historica_vinculada_a_otro_certificado_del_mismo_evento_bloquea_ambos(): void
    {
        $this->encuestaDelPortal();
        $a = $this->conSesion();
        $b = $this->clonar(true);
        $v3 = EncuestaVersion::where('numero', 3)->first();
        $evento = (int) DB::table('cf_certificados_legado')->where('id', $a)->value('evento_id');
        $this->respuesta($v3->id, $b, 'legado', 'vinculada', $evento, $this->hashPersona());

        $html = $this->get(route('portal.panel'))->assertOk()->getContent();
        $this->assertSame(2, $this->contar('data-encuesta="respondida"', $html));
        $this->post(route('portal.encuesta.guardar', $a), $this->valido())->assertSessionHas('aviso', 'La encuesta de este evento ya fue respondida.');
        $this->assertSame(0, DB::table('cf_encuestas_respuestas')->where('origen', 'credential_flow')->count());
    }

    public function test_una_respuesta_de_otro_evento_o_de_otra_persona_no_bloquea(): void
    {
        $this->encuestaDelPortal();
        $a = $this->conSesion();
        $v3 = EncuestaVersion::where('numero', 3)->first();
        $evento = (int) DB::table('cf_certificados_legado')->where('id', $a)->value('evento_id');
        $e = (array) DB::table('cf_eventos')->where('id', $evento)->first();
        unset($e['id']);
        $e['nombre'] = 'Evento sin certificado de esta persona';
        $e['nombre_normalizado'] = 'evento sin certificado de esta persona';
        $otroEvento = DB::table('cf_eventos')->insertGetId($e);
        $this->respuesta($v3->id, null, 'legado', 'vinculada', $otroEvento, $this->hashPersona());                      // misma persona, otro evento
        $this->respuesta($v3->id, null, 'credential_flow', null, $evento, Hmac::de('documento', 'OTRA-PERSONA-9999'));         // mismo evento, otra persona

        $this->get(route('portal.panel'))->assertOk()->assertSee('Evaluar evento')->assertDontSee('Encuesta respondida');
        $this->post(route('portal.encuesta.guardar', $a), $this->valido())->assertSessionHas('exito');
    }

    public function test_solicitudes_simultaneas_por_certificados_distintos_del_mismo_evento_no_guardan_dos_respuestas(): void
    {
        $v4 = $this->encuestaDelPortal();
        $a = $this->conSesion();
        $b = $this->clonar(true);
        $otroEvento = $this->clonar(false, 'Evento Otro');
        $portal = app(EncuestaPortal::class);
        $this->assertSame($portal->claveEvento($a), $portal->claveEvento($b));            // mismo evento + misma identidad ⇒ mismo candado
        $this->assertNotSame($portal->claveEvento($a), $portal->claveEvento($otroEvento));  // otro evento ⇒ otro candado

        // Otra solicitud conserva el candado de ESTA persona en ESTE evento: la solicitud por el otro certificado no guarda nada.
        $candado = Cache::lock('cf-portal-encuesta:'.$portal->claveEvento($a), 30);
        $this->assertTrue($candado->get());
        $this->post(route('portal.encuesta.guardar', $b), $this->valido())->assertRedirect(route('portal.encuesta', $b))->assertSessionHas('errores_encuesta');
        $this->assertSame(0, DB::table('cf_encuestas_respuestas')->where('version_id', $v4->id)->count());
        $candado->release();

        // La primera termina; la segunda ve la respuesta DENTRO de su sección protegida y no guarda otra.
        $this->post(route('portal.encuesta.guardar', $a), $this->valido())->assertSessionHas('exito');
        $this->post(route('portal.encuesta.guardar', $b), $this->valido())->assertSessionHas('aviso', 'La encuesta de este evento ya fue respondida.');
        $this->assertSame(1, DB::table('cf_encuestas_respuestas')->where('version_id', $v4->id)->count());
        // Y el otro evento sigue disponible.
        $this->post(route('portal.encuesta.guardar', $otroEvento), $this->valido())->assertSessionHas('exito');
    }

    public function test_un_formulario_sin_ninguna_respuesta_se_rechaza_y_no_crea_nada(): void
    {
        $this->encuestaDelPortal();
        $id = $this->conSesion();
        $vacios = [
            'sin campos' => [],
            'arreglo vacío' => ['r' => []],
            'todo vacío' => ['r' => ['pregunta1' => '', 'pregunta4' => '', 'pregunta7' => '']],
            'solo espacios' => ['r' => ['pregunta1' => '', 'pregunta7' => "   \n\t  "]],
        ];
        foreach ($vacios as $caso => $datos) {
            $this->post(route('portal.encuesta.guardar', $id), $datos)->assertRedirect(route('portal.encuesta', $id))
                ->assertSessionHas('errores_encuesta', ['_' => 'Responder al menos una pregunta para enviar la encuesta.']);
            $this->assertSame(0, DB::table('cf_encuestas_respuestas')->count(), $caso);
            $this->assertSame(0, DB::table('cf_encuestas_respuestas_detalle')->count(), $caso);
        }
        // No quedó marcada como respondida: sigue pudiéndose responder.
        $html = $this->get(route('portal.panel'))->assertOk()->getContent();
        $this->assertSame(1, $this->contar('data-encuesta="evaluar"', $html));
        $this->assertSame(0, $this->contar('data-encuesta="respondida"', $html));
        $this->get(route('portal.encuesta', $id))->assertOk();
    }

    public function test_una_sola_respuesta_con_contenido_es_suficiente(): void
    {
        $v4 = $this->encuestaDelPortal();
        $id = $this->conSesion();

        $this->post(route('portal.encuesta.guardar', $id), ['r' => ['pregunta1' => '', 'pregunta4' => 'No', 'pregunta7' => '']])->assertSessionHas('exito');

        $r = DB::table('cf_encuestas_respuestas')->where('version_id', $v4->id)->first();
        $this->assertSame(1, DB::table('cf_encuestas_respuestas_detalle')->where('respuesta_id', $r->id)->count());
        $this->assertSame('No', DB::table('cf_encuestas_respuestas_detalle')->where('respuesta_id', $r->id)->value('valor_texto'));
    }

    /** Texto visible: sin estilos, scripts ni etiquetas. */
    private function texto(string $html): string
    {
        $html = preg_replace(['#<style\b.*?</style>#si', '#<script\b.*?</script>#si'], '', $html);

        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html))));
    }
}
