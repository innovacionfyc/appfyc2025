<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Models\CredentialFlow\Emision;
use App\Support\CredentialFlow\Legado\RutasLegado;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/** Portal público · Fase 7: panel de certificados, estados humanos y descarga de PDF (lazy freeze). Datos sintéticos. */
class PortalPanelTest extends PortalTestCase
{
    private function tarjetas(TestResponse $r): array
    {
        preg_match_all('/data-certificado="([a-z_]+)"/', $r->getContent(), $m);

        return $m[1];
    }

    private function panelDe(string $doc, string $correo): TestResponse
    {
        $this->entrar($doc, $correo)->assertRedirect(route('portal.panel'));

        return $this->get(route('portal.panel'))->assertOk();
    }

    private function emisionModerna(): Emision
    {
        $lote = $this->loteCon(1);
        $this->emitirPor($lote, $lote->participantes()->firstOrFail())->assertCreated();
        $this->app['auth']->forgetGuards();

        return Emision::firstOrFail();
    }

    public function test_el_panel_muestra_una_tarjeta_por_certificado_y_estado_humano(): void
    {
        $r = $this->panelDe('1.000.001', self::CORREO);

        $tipos = $this->tarjetas($r);
        sort($tipos);
        $this->assertSame(['disponible', 'plantilla', 'revision', 'revision', 'revocado'], $tipos);   // el «reemplazado» sin emisión asociada cae en revisión (10B-2B-2C)
        foreach (['Disponible para descargar.', 'Descargar PDF', 'Código del certificado: 5237', 'Estamos recuperando este certificado. Escríbenos y te ayudamos.',
            'Este certificado necesita revisión. Escríbenos y te ayudamos.', 'Certificado revocado.', 'Curso Alfa 2024', 'Curso Beta 2025'] as $texto) {
            $r->assertSee($texto);
        }
        $this->assertCount(5, $this->tarjetas($r), 'Un certificado por evento');
    }

    public function test_el_panel_no_muestra_datos_tecnicos_ni_personales_de_mas(): void
    {
        $html = $this->panelDe('1000001', self::CORREO)->getContent();

        foreach ([self::CORREO, self::CORREO2, 'hugo@', 'old_id', 'corrida', 'grupo', 'duplicad', 'conflict', 'conciliacion', 'hash', 'sha256', 'snapshot', 'plantilla_id', 'pendiente_', 'revision_documento', 'SQLSTATE', 'advertencia'] as $prohibido) {
            $this->assertStringNotContainsStringIgnoringCase($prohibido, $html, "Filtra {$prohibido}");
        }
        $this->assertStringNotContainsString('credential-flow/legado', $html);
    }

    public function test_los_duplicados_identicos_son_una_sola_tarjeta_que_resuelve_al_canonico(): void
    {
        $r = $this->panelDe('2.000.001', 'gina@example.test');

        $this->assertSame(['disponible'], $this->tarjetas($r));
        $r->assertSee('Código del certificado: 5300');
    }

    public function test_el_conflictivo_es_una_sola_tarjeta_pendiente_sin_mostrar_variantes(): void
    {
        $r = $this->panelDe('2000002', 'hugo@example.test');

        $tipos = $this->tarjetas($r);
        sort($tipos);
        $this->assertSame(['revision', 'revision_conciliacion'], $tipos);
        $this->assertSame(1, substr_count($r->getContent(), 'Estamos revisando este certificado. Escríbenos y te ayudamos.'));
        $this->assertStringNotContainsString('HUGO NUEVE X', $r->getContent());
    }

    public function test_el_revisar_documento_aparece_solo_si_otro_certificado_autentica_el_documento(): void
    {
        $r = $this->panelDe('1000001', self::CORREO);

        $r->assertSee('Este certificado necesita revisión. Escríbenos y te ayudamos.');
        // El mismo documento solo con la fila en revisión no concede OTP (verificado también en PortalOtpTest).
        $this->post(route('portal.salir'));
        DB::table('cf_certificados_legado')->where('documento_clave', '1000001')->update(['conciliacion_estado' => 'revision_documento']);
        Mail::fake();
        $this->solicitar('1000001', self::CORREO);
        $this->assertNull($this->ultimoCodigo());
    }

    public function test_reemplazado_por_moderno_muestra_una_tarjeta_actualizada_con_la_descarga_moderna(): void
    {
        $e = $this->emisionModerna();
        DB::table('cf_certificados_legado')->where('id', $this->idCert(19))->update(['reemplazado_por_emision_id' => $e->id]);

        $r = $this->panelDe('1000001', self::CORREO);

        $r->assertSee('Certificado actualizado')->assertSee('Descargar certificado')->assertSee('Código del certificado: '.$e->codigo);
        $this->assertStringNotContainsString('emision_id', $r->getContent());
        $this->assertStringNotContainsString('/emisiones/', $r->getContent(), 'el portal nunca expone ids de emisión');
        $this->get(route('portal.descargar', $this->idCert(19)))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_revocado_no_tiene_boton_de_descarga(): void
    {
        $this->panelDe('1000001', self::CORREO);

        $this->get(route('portal.descargar', $this->idCert(18)))->assertRedirect(route('portal.panel'));
        $this->assertSame([], Storage::disk('local')->allFiles('credential-flow/legado'));
    }

    // ── Descarga ──────────────────────────────────────────────────────────────

    public function test_descarga_elegible_sirve_el_pdf_y_registra_una_nueva_descarga_del_portal(): void
    {
        $importadas = DB::table('cf_descargas')->count();
        $this->panelDe('1000001', self::CORREO);

        $r = $this->get(route('portal.descargar', $this->idCert(1)));

        $r->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment', $r->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF-', file_get_contents($r->baseResponse->getFile()->getPathname()));
        $nueva = DB::table('cf_descargas')->orderByDesc('id')->first();
        $this->assertSame($importadas + 1, DB::table('cf_descargas')->count());
        $this->assertSame([$this->idCert(1), 'portal', 'credential_flow'], [(int) $nueva->certificado_legado_id, $nueva->via, $nueva->origen]);
        $this->assertNull($nueva->emision_id);
        $this->assertSame(64, strlen($nueva->ip_hash));
        $this->assertStringNotContainsString('127.0.0.1', json_encode($nueva));
    }

    public function test_las_descargas_importadas_no_se_confunden_con_las_nuevas(): void
    {
        $this->assertSame(DB::table('cf_descargas')->count(), DB::table('cf_descargas')->where('origen', 'legado_importado')->count(), 'Todas las importadas conservan su origen');
        $this->panelDe('1000001', self::CORREO);
        $this->get(route('portal.descargar', $this->idCert(1)))->assertOk();

        $this->assertSame(1, DB::table('cf_descargas')->where('origen', 'credential_flow')->count());
        $this->assertGreaterThan(0, DB::table('cf_descargas')->where('origen', 'legado_importado')->count());
    }

    public function test_un_pdf_ya_congelado_se_reutiliza_con_la_misma_huella_y_cada_descarga_se_registra(): void
    {
        $this->panelDe('1000001', self::CORREO);
        $this->get(route('portal.descargar', $this->idCert(1)))->assertOk();
        $sha = DB::table('cf_certificados_legado')->where('id', $this->idCert(1))->value('pdf_hash');
        $ruta = Storage::disk('local')->path(RutasLegado::certificado($this->idCert(1)));
        $mtime = filemtime($ruta);

        $r = $this->get(route('portal.descargar', $this->idCert(1)))->assertOk();

        $this->assertSame($sha, hash_file('sha256', $r->baseResponse->getFile()->getPathname()));
        $this->assertSame($sha, DB::table('cf_certificados_legado')->where('id', $this->idCert(1))->value('pdf_hash'));
        $this->assertSame($mtime, filemtime($ruta), 'No se regenera ni se reescribe');
        $this->assertSame(2, DB::table('cf_descargas')->where('origen', 'credential_flow')->count());
        $this->assertCount(1, Storage::disk('local')->allFiles('credential-flow/legado/certificados'));
    }

    public function test_descargar_la_fila_duplicada_entrega_el_pdf_del_canonico_sin_crear_otro_archivo(): void
    {
        $this->panelDe('2000001', 'gina@example.test');
        $copia = DB::table('cf_certificados_legado')->where('conciliacion_estado', 'duplicado_consolidado')->where('documento_clave', '2000001')->value('id');
        $canonico = $this->idCert(7);

        $this->get(route('portal.descargar', $copia))->assertOk();
        $this->get(route('portal.descargar', $canonico))->assertOk();

        $this->assertCount(1, Storage::disk('local')->allFiles('credential-flow/legado/certificados'));
        $this->assertSame([$canonico, $canonico], DB::table('cf_descargas')->where('origen', 'credential_flow')->pluck('certificado_legado_id')->map(fn ($i) => (int) $i)->all());
    }

    public function test_no_se_puede_descargar_el_certificado_de_otro_documento_ni_uno_inexistente_igual_404(): void
    {
        $this->panelDe('2000001', 'gina@example.test');

        $ajeno = $this->get(route('portal.descargar', $this->idCert(1)));
        $inexistente = $this->get(route('portal.descargar', 99999999));

        $ajeno->assertNotFound();
        $inexistente->assertNotFound();
        $this->assertSame($this->normalizar($ajeno)[0], $this->normalizar($inexistente)[0]);
        $this->assertSame(0, DB::table('cf_descargas')->where('origen', 'credential_flow')->count());
        $this->assertSame([], Storage::disk('local')->allFiles('credential-flow/legado'));
    }

    public function test_no_elegibles_no_descargan_ni_registran_descarga(): void
    {
        $this->panelDe('1000001', self::CORREO);

        foreach ([16, 17, 18, 19] as $p) {
            $this->get(route('portal.descargar', $this->idCert($p)))->assertRedirect(route('portal.panel'))->assertSessionHas('error');
        }
        $this->assertSame(0, DB::table('cf_descargas')->where('origen', 'credential_flow')->count());
        $this->assertSame([], Storage::disk('local')->allFiles('credential-flow/legado'));
    }

    public function test_un_pdf_alterado_no_se_entrega_ni_se_regenera_y_no_registra_descarga(): void
    {
        $this->panelDe('1000001', self::CORREO);
        $this->get(route('portal.descargar', $this->idCert(1)))->assertOk();
        Storage::disk('local')->put(RutasLegado::certificado($this->idCert(1)), 'alterado');

        $r = $this->get(route('portal.descargar', $this->idCert(1)));

        $r->assertRedirect(route('portal.panel'))->assertSessionHas('error', 'No pudimos entregar este certificado en este momento. Escríbenos y te ayudamos.');
        $this->assertSame('alterado', Storage::disk('local')->get(RutasLegado::certificado($this->idCert(1))));
        $this->assertSame(1, DB::table('cf_descargas')->where('origen', 'credential_flow')->count(), 'Solo la descarga anterior');
    }

    public function test_un_certificado_revocado_despues_de_congelarse_conserva_su_archivo_pero_no_se_sirve(): void
    {
        $this->panelDe('1000001', self::CORREO);
        $this->get(route('portal.descargar', $this->idCert(1)))->assertOk();
        DB::table('cf_certificados_legado')->where('id', $this->idCert(1))->update(['estado' => 'revocado']);

        $this->get(route('portal.descargar', $this->idCert(1)))->assertRedirect(route('portal.panel'));

        $this->assertTrue(Storage::disk('local')->exists(RutasLegado::certificado($this->idCert(1))));
        $this->assertSame(1, DB::table('cf_descargas')->where('origen', 'credential_flow')->count());
    }

    public function test_limite_de_descargas_por_ip_responde_429(): void
    {
        config(['credential_flow.portal.limite_descarga_por_minuto' => 2]);
        $this->panelDe('1000001', self::CORREO);
        $this->get(route('portal.descargar', $this->idCert(1)))->assertOk();
        $this->get(route('portal.descargar', $this->idCert(1)))->assertOk();

        $this->get(route('portal.descargar', $this->idCert(1)))->assertStatus(429);
    }

    public function test_la_descarga_exige_sesion_vigente(): void
    {
        $this->panelDe('1000001', self::CORREO);
        $this->travel(31)->minutes();

        $this->get(route('portal.descargar', $this->idCert(1)))->assertRedirect(route('portal.inicio'));
        $this->assertSame(0, DB::table('cf_descargas')->where('origen', 'credential_flow')->count());
    }

    public function test_dos_personas_no_comparten_sesion_ni_ven_los_certificados_de_la_otra(): void
    {
        $this->panelDe('2000001', 'gina@example.test');
        $this->post(route('portal.salir'));

        $r = $this->panelDe('1000001', self::CORREO);

        $r->assertDontSee('Código del certificado: 5300');
        $this->assertCount(5, $this->tarjetas($r));
    }
}
