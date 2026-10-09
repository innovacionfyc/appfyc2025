<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use App\Support\CredentialFlow\Emisiones\EmisorCredencial;
use App\Support\CredentialFlow\Portal\AccesoPortal;
use App\Support\CredentialFlow\Reemplazo\EmisionVigente;
use App\Support\CredentialFlow\Verificacion\UrlVerificacion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * Portal público · Fase 10B-2B-2C: un histórico REEMPLAZADO por una emisión moderna. La autorización nace SIEMPRE del histórico (documento, grupo y
 * sesión); la emisión moderna no autentica ni amplía el alcance. Una sola tarjeta con la emisión vigente de la cadena; descarga del PDF moderno ya
 * emitido (verificado) y registrada contra la emisión; anomalías sin detalle ni fallback al histórico.
 */
class PortalReemplazoTest extends PortalTestCase
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

    /** Emisión moderna de un participante NUEVO (sin correo, con otro documento y otro nombre: nada de esto autentica en el portal). */
    private function emision(string $nombre = 'PERSONA CORREGIDA', string $documento = 'ZZ99990'): Emision
    {
        $lote = $this->loteCon(0);
        $p = $this->participante($lote, $nombre, $documento);

        return app(EmisorCredencial::class)->emitir($p, $lote->fresh(), null);
    }

    /** Marca el histórico `$old` como reemplazado por la emisión (lo que hace ReemplazoHistorico). */
    private function reemplazar(int $old, Emision $e): void
    {
        DB::table('cf_certificados_legado')->where('id', $this->idCert($old))->update(['estado' => 'reemplazado', 'reemplazado_por_emision_id' => $e->id]);
    }

    private function cadena(int $eslabones): array
    {
        $es = [$this->emision()];
        for ($i = 1; $i < $eslabones; $i++) {
            $es[] = app(EmisorCredencial::class)->reemitir($es[$i - 1], 'Reemisión de prueba de la cadena.', null);
        }

        return $es;
    }

    private function publica(string $codigo): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->get('/verificar/'.$codigo);
    }

    // ── Gate habilitante ─────────────────────────────────────────────────────────────────────────────────

    public function test_un_historico_reemplazado_habilita_el_acceso_cuando_es_lo_unico_utilizable_del_alcance(): void
    {
        $abc = DB::table('cf_certificados_legado')->where('documento_clave', 'ABC123')->value('id');   // documento con solo `revision_documento`
        $correo = DB::table('cf_correos')->where('certificado_legado_id', $abc)->value('correo_normalizado');
        $this->assertNotNull($correo);
        $acceso = new AccesoPortal;
        $this->assertNull($acceso->alcance('ABC123', $correo), 'antes: ninguna fila habilitante');

        DB::table('cf_certificados_legado')->where('id', $abc)->update(['estado' => 'reemplazado', 'reemplazado_por_emision_id' => $this->emision()->id]);

        $this->assertSame(['grupo' => null], $acceso->alcance('ABC123', $correo), 'después: el alcance es el MISMO (documento completo)');
        $this->entrar('ABC123', $correo)->assertRedirect(route('portal.panel'));
    }

    public function test_reemplazado_sin_emision_no_habilita_y_un_estado_vigente_con_enlace_tampoco(): void
    {
        $abc = DB::table('cf_certificados_legado')->where('documento_clave', 'ABC123')->value('id');
        $correo = DB::table('cf_correos')->where('certificado_legado_id', $abc)->value('correo_normalizado');
        $acceso = new AccesoPortal;

        DB::table('cf_certificados_legado')->where('id', $abc)->update(['estado' => 'reemplazado']);   // sin emisión
        $this->assertNull($acceso->alcance('ABC123', $correo));
        DB::table('cf_certificados_legado')->where('id', $abc)->update(['estado' => 'vigente', 'reemplazado_por_emision_id' => $this->emision()->id]);   // enlace con estado inconsistente
        $this->assertNull($acceso->alcance('ABC123', $correo));
        $this->assertFalse(AccesoPortal::habilitaAcceso(CertificadoLegado::findOrFail($abc)));
    }

    public function test_un_reemplazo_no_cambia_el_alcance_de_los_demas_documentos(): void
    {
        $pares = [['1000001', self::CORREO], ['2000001', 'gina@example.test'], ['3000001', 'm1@example.test'], ['3000001', 'l1@example.test'], ['3000002', 'x@example.test'],
            ['3000003', 'ar@example.test'], ['3000004', 'ld@example.test'], ['3000005', 'ps@example.test'], ['3000005', 'os@example.test']];
        $acceso = new AccesoPortal;
        $antes = array_map(fn ($p) => $acceso->alcance(...$p), $pares);

        $this->reemplazar(19, $this->emision());   // un reemplazo en el documento de Ana

        $this->assertSame($antes, array_map(fn ($p) => $acceso->alcance(...$p), $pares));
    }

    // ── Multi-identidad: el reemplazo hereda el alcance exacto ───────────────────────────────────────────

    public function test_en_un_documento_multigrupo_el_reemplazo_pertenece_al_grupo_del_nombre_historico(): void
    {
        // María (30, 31, 32) y Mario (33, 34) comparten documento 3000001. Se reemplaza la fila 30 de MARÍA con una emisión de OTRO nombre y otro documento.
        $this->reemplazar(30, $this->emision('NOMBRE TOTALMENTE OTRO', 'QQ12345'));

        $maria = $this->panelDe('3000001', 'm1@example.test');
        $this->assertContains('actualizado', $this->tarjetas($maria));
        $this->get(route('portal.descargar', $this->idCert(30)))->assertOk();
        $this->post(route('portal.salir'));

        // Con el correo de MARIO, el alcance es el grupo de Mario: ni ve ni descarga el reemplazo de María (404 uniforme, igual que antes).
        $mario = $this->panelDe('3000001', 'l1@example.test');
        $this->assertNotContains('actualizado', $this->tarjetas($mario));
        $this->get(route('portal.descargar', $this->idCert(30)))->assertNotFound();
        $this->assertSame(1, DB::table('cf_descargas')->where('origen', 'credential_flow')->whereNotNull('emision_id')->count());
    }

    public function test_el_correo_compartido_entre_grupos_sigue_sin_otp_aunque_se_reemplacen_sus_certificados(): void
    {
        $this->reemplazar(40, $this->emision());
        $this->reemplazar(41, $this->emision('OTRA PERSONA', 'AB12345'));

        $this->solicitar('3000002', 'x@example.test');

        $this->assertNull($this->ultimoCodigo(), 'un reemplazo NO desbloquea una identidad bloqueada');
        $this->assertNull((new AccesoPortal)->alcance('3000002', 'x@example.test'));
    }

    public function test_el_documento_corregido_y_el_correo_vacio_del_participante_moderno_no_son_una_via_de_acceso(): void
    {
        $e = $this->emision('PERSONA CORREGIDA', 'ZZ99990');
        $this->reemplazar(19, $e);

        $this->assertNull(DB::table('cf_participantes')->where('id', $e->participante_id)->value('correo'));
        $this->assertSame(0, DB::table('cf_correos')->where('participante_id', $e->participante_id)->count());
        $this->solicitar('ZZ99990', self::CORREO);   // el documento corregido con el correo de Ana
        $this->assertNull($this->ultimoCodigo());
        $this->assertNull((new AccesoPortal)->alcance('ZZ99990', self::CORREO));
        $this->assertNotNull((new AccesoPortal)->alcance('1000001', self::CORREO), 'la entrada sigue siendo la identidad histórica');
    }

    // ── Tarjeta ──────────────────────────────────────────────────────────────────────────────────────────

    public function test_la_tarjeta_es_unica_y_muestra_la_emision_vigente(): void
    {
        $e = $this->emision();
        $this->reemplazar(19, $e);

        $r = $this->panelDe('1000001', self::CORREO);

        $this->assertCount(5, $this->tarjetas($r), 'una tarjeta por certificado lógico: el reemplazo no añade otra');
        $this->assertSame(1, count(array_filter($this->tarjetas($r), fn ($t) => $t === 'actualizado')));
        $r->assertSee('Certificado actualizado')->assertSee('Descargar certificado')->assertSee('Estado: Vigente')->assertSee('Código del certificado: '.$e->codigo);
        $html = $r->getContent();
        foreach (['PERSONA CORREGIDA', 'ZZ99990', '/emisiones/', 'emision_id', 'participante', 'reemplaz', 'sha256'] as $oculto) {
            $this->assertStringNotContainsStringIgnoringCase($oculto, $html);
        }
        $this->assertStringNotContainsString('Hay una versión más reciente', $html);
    }

    public function test_la_cadena_a_b_c_muestra_una_tarjeta_con_c_y_descarga_c(): void
    {
        [$a, $b, $c] = $this->cadena(3);
        $this->reemplazar(19, $a);

        $r = $this->panelDe('1000001', self::CORREO);

        $this->assertSame(1, count(array_filter($this->tarjetas($r), fn ($t) => $t === 'actualizado')));
        $r->assertSee('Código del certificado: '.$c->codigo);
        $this->assertStringNotContainsString($a->codigo, $r->getContent());
        $this->assertStringNotContainsString($b->codigo, $r->getContent());

        $d = $this->get(route('portal.descargar', $this->idCert(19)));
        $d->assertOk();
        $this->assertSame($c->fresh()->pdf_hash, hash_file('sha256', $d->baseResponse->getFile()->getPathname()), 'se entrega el PDF de C');
        $fila = DB::table('cf_descargas')->where('origen', 'credential_flow')->whereNotNull('emision_id')->first();
        $this->assertSame([$c->id, null, 'portal'], [(int) $fila->emision_id, $fila->certificado_legado_id, $fila->via]);
    }

    public function test_la_emision_revocada_muestra_revocado_sin_descarga_ni_fallback_al_historico(): void
    {
        $e = $this->emision();
        $this->reemplazar(19, $e);
        app(EmisorCredencial::class)->revocar($e, 'Revocación de prueba de la emisión.', null);
        $antes = DB::table('cf_descargas')->count();

        $r = $this->panelDe('1000001', self::CORREO);

        $this->assertContains('revocado', $this->tarjetas($r));
        $this->assertSame(2, count(array_filter($this->tarjetas($r), fn ($t) => $t === 'revocado')), 'el revocado histórico y este');
        $this->assertStringNotContainsString('href="'.route('portal.descargar', $this->idCert(19)).'"', $r->getContent());
        $this->get(route('portal.descargar', $this->idCert(19)))->assertRedirect(route('portal.panel'))->assertSessionHas('error');
        $this->assertSame($antes, DB::table('cf_descargas')->count());
    }

    // ── Descarga ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_la_descarga_moderna_tiene_los_headers_privados_el_nombre_generico_y_registra_la_descarga_contra_la_emision(): void
    {
        $e = $this->emision();
        $this->reemplazar(19, $e);
        $importadas = DB::table('cf_descargas')->where('origen', 'legado_importado')->count();
        $this->panelDe('1000001', self::CORREO);

        $r = $this->get(route('portal.descargar', $this->idCert(19)));

        $r->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment; filename=certificado.pdf', (string) $r->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $r->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertSame('no-referrer', $r->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('noindex', (string) $r->headers->get('X-Robots-Tag'));
        $this->assertSame($e->pdf_hash, hash_file('sha256', $r->baseResponse->getFile()->getPathname()));
        $d = DB::table('cf_descargas')->where('emision_id', $e->id)->first();
        $this->assertSame(['portal', 'credential_flow', null], [$d->via, $d->origen, $d->certificado_legado_id]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $d->ip_hash);
        $this->assertSame($importadas, DB::table('cf_descargas')->where('origen', 'legado_importado')->count(), 'las descargas históricas no se tocan');
        $this->assertSame(0, DB::table('cf_descargas')->where('certificado_legado_id', $this->idCert(19))->where('origen', 'credential_flow')->count(), 'nada contra el histórico');
    }

    public function test_la_ruta_historica_vieja_no_entrega_el_pdf_obsoleto_sino_la_emision_vigente(): void
    {
        // El histórico 1 (descargable) con PDF congelado; luego se reemplaza.
        $this->panelDe('1000001', self::CORREO);
        $this->get(route('portal.descargar', $this->idCert(1)))->assertOk();
        $congelado = DB::table('cf_certificados_legado')->where('id', $this->idCert(1))->value('pdf_archivo');
        $this->assertNotNull($congelado);
        $e = $this->emision();
        $this->reemplazar(1, $e);

        $r = $this->get(route('portal.descargar', $this->idCert(1)));

        $r->assertOk();
        $this->assertSame($e->pdf_hash, hash_file('sha256', $r->baseResponse->getFile()->getPathname()));
        $this->assertSame($congelado, DB::table('cf_certificados_legado')->where('id', $this->idCert(1))->value('pdf_archivo'), 'el PDF histórico congelado se conserva');
        $this->assertTrue(Storage::disk('local')->exists($congelado));
    }

    public function test_pdf_faltante_o_alterado_y_cadena_corrupta_no_descargan_ni_registran_y_solo_dejan_un_log_sin_datos_personales(): void
    {
        Log::spy();
        $a = $this->emision();
        $this->reemplazar(19, $a);
        $this->panelDe('1000001', self::CORREO);
        $descargas = DB::table('cf_descargas')->count();

        // PDF alterado: el SHA-256 ya no coincide.
        Storage::disk('local')->put($a->pdf_archivo, 'no es el PDF original');
        $this->get(route('portal.descargar', $this->idCert(19)))->assertRedirect(route('portal.panel'))->assertSessionHas('error', 'Este certificado necesita revisión.');
        // PDF faltante.
        Storage::disk('local')->delete($a->pdf_archivo);
        $this->get(route('portal.descargar', $this->idCert(19)))->assertRedirect(route('portal.panel'))->assertSessionHas('error', 'Este certificado necesita revisión.');
        // Ciclo en la cadena.
        $b = app(EmisorCredencial::class)->reemitir($this->emision(), 'Reemisión de prueba de la cadena.', null);
        DB::table('cf_certificados_legado')->where('id', $this->idCert(19))->update(['reemplazado_por_emision_id' => $b->reemplaza_id]);
        DB::table('cf_emisiones')->where('id', $b->reemplaza_id)->update(['reemplaza_id' => $b->id]);
        $this->get(route('portal.descargar', $this->idCert(19)))->assertRedirect(route('portal.panel'))->assertSessionHas('error', 'Este certificado necesita revisión.');
        $panel = $this->get(route('portal.panel'))->assertOk();
        $this->assertContains('revision', $this->tarjetas($panel));
        $panel->assertSee('Este certificado necesita revisión.');

        $this->assertSame($descargas, DB::table('cf_descargas')->count(), 'ningún intento fallido registra una descarga');
        Log::shouldHaveReceived('error')->atLeast()->once();
        Log::shouldHaveReceived('warning')->atLeast()->once();
        foreach (['PERSONA CORREGIDA', self::CORREO, 'ZZ99990', 'credential-flow/emisiones'] as $privado) {
            Log::shouldNotHaveReceived('error', fn ($m, $ctx = []) => str_contains($m.json_encode($ctx), $privado));
        }
    }

    public function test_el_codigo_publico_no_autoriza_la_descarga(): void
    {
        $e = $this->emision();
        $this->reemplazar(19, $e);

        // Sin sesión: ni el código moderno ni el histórico llevan al PDF.
        $this->publica($e->codigo)->assertOk();
        $this->get(route('portal.descargar', $this->idCert(19)))->assertRedirect(route('portal.inicio'));
        $this->get('/certificados/'.$e->id.'/descargar')->assertRedirect(route('portal.inicio'));
        $this->get('/certificados/'.$e->codigo.'/descargar')->assertNotFound();
        foreach ([$this->publica($e->codigo), $this->publica('5237')] as $r) {
            $this->assertStringNotContainsString('application/pdf', (string) $r->headers->get('Content-Type'));
        }
        // Con la sesión de OTRA persona (otro documento): el id del reemplazo no existe para ella.
        $this->panelDe('2000001', 'gina@example.test');
        $this->get(route('portal.descargar', $this->idCert(19)))->assertNotFound();
    }

    public function test_enumeracion_y_respuestas_uniformes_para_ids_inexistentes_ajenos_y_manipulados(): void
    {
        $this->reemplazar(19, $this->emision());
        $this->panelDe('2000001', 'gina@example.test');
        $ajeno = $this->get(route('portal.descargar', $this->idCert(19)));
        $inexistente = $this->get(route('portal.descargar', 99999999));
        $otroDocumento = $this->get(route('portal.descargar', $this->idCert(1)));

        foreach ([$ajeno, $inexistente, $otroDocumento] as $r) {
            $r->assertNotFound();
        }
        $this->assertSame($this->normalizar($ajeno)[1]['content-type'] ?? null, $this->normalizar($inexistente)[1]['content-type'] ?? null);
        $this->get('/certificados/abc/descargar')->assertNotFound();
        $this->get('/certificados/-1/descargar')->assertNotFound();
        $this->assertSame(0, DB::table('cf_descargas')->where('origen', 'credential_flow')->count());
    }

    public function test_sesion_logout_expiracion_y_replay(): void
    {
        $e = $this->emision();
        $this->reemplazar(19, $e);
        $url = route('portal.descargar', $this->idCert(19));
        $t0 = now();
        $this->panelDe('1000001', self::CORREO);
        $this->get($url)->assertOk();

        // Logout: la misma URL deja de funcionar.
        $this->post(route('portal.salir'));
        $this->get($url)->assertRedirect(route('portal.inicio'));

        // Expiración por inactividad (30 min). (Los OTP respetan el intervalo mínimo entre solicitudes: el reloj avanza entre ingresos.)
        Carbon::setTestNow($t0->copy()->addMinutes(2));
        $this->panelDe('1000001', self::CORREO);
        $this->get($url)->assertOk();
        Carbon::setTestNow($t0->copy()->addMinutes(2 + 31));
        $this->get($url)->assertRedirect(route('portal.inicio'));

        // Otro navegador (sin la cookie del portal) con la URL copiada.
        Carbon::setTestNow($t0->copy()->addMinutes(40));
        $this->panelDe('1000001', self::CORREO);
        $this->flushSession();
        $this->get($url)->assertRedirect(route('portal.inicio'));
        Carbon::setTestNow();
        $this->assertSame(2, DB::table('cf_descargas')->where('emision_id', $e->id)->count(), 'solo las descargas con sesión válida');
    }

    public function test_el_limite_de_descarga_es_propio_y_no_comparte_contador_con_la_solicitud_de_otp(): void
    {
        $this->reemplazar(19, $this->emision());
        $url = route('portal.descargar', $this->idCert(19));
        $this->panelDe('1000001', self::CORREO);

        // Agotar el límite de SOLICITUDES de OTP (5/min) no gasta ni una descarga.
        foreach (range(1, 7) as $_) {
            $this->solicitar('1000001', self::CORREO);
        }
        $this->get($url)->assertOk();

        // Y el límite de descargas (20/min) es independiente.
        foreach (range(2, 20) as $_) {
            $this->get($url)->assertOk();
        }
        $this->get($url)->assertStatus(429);
    }

    // ── Rendimiento ──────────────────────────────────────────────────────────────────────────────────────

    public function test_el_panel_resuelve_las_cadenas_en_lote_sin_una_consulta_por_tarjeta(): void
    {
        $contar = function (array $viejos, int $eslabones) {
            // Un histórico reemplazado por cadena de `eslabones` emisiones, por cada id de `$viejos`.
            foreach ($viejos as $old) {
                $this->reemplazar($old, $this->cadena($eslabones)[0]);
            }
            $this->app['auth']->forgetGuards();

            return count(array_filter($this->sentencias(fn () => (new AccesoPortal)->tarjetas('1000001')), fn ($q) => str_contains($q, 'cf_emisiones')));
        };

        $una = $contar([19], 3);
        $varias = $contar([16, 17, 18], 3);   // tres más, cada una con otra cadena de tres emisiones

        $this->assertSame($una, $varias, 'las consultas a cf_emisiones no crecen con el número de tarjetas reemplazadas');
        $this->assertLessThanOrEqual(1 + 3 + 1, $varias, 'una por nivel de la cadena más larga');
    }

    public function test_emision_vigente_informa_explicitamente_las_anomalias(): void
    {
        $this->assertSame(EmisionVigente::ANOMALIA_FALTANTE, EmisionVigente::desdeEmision(99999999)['anomalia']);

        [$a, $b, $c] = $this->cadena(3);
        $ok = EmisionVigente::desdeEmision($a);
        $this->assertSame([EmisionVigente::ESTADO_VIGENTE, null, $c->id, [$a->id, $b->id, $c->id]], [$ok['estado'], $ok['anomalia'], $ok['vigente']->id, $ok['cadena']]);

        app(EmisorCredencial::class)->revocar($c, 'Revocación de prueba de la emisión.', null);
        $this->assertSame([EmisionVigente::ESTADO_REVOCADA, null, null], [EmisionVigente::desdeEmision($a)['estado'], EmisionVigente::desdeEmision($a)['anomalia'], EmisionVigente::desdeEmision($a)['vigente']]);

        DB::table('cf_emisiones')->where('id', $a->id)->update(['reemplaza_id' => $c->id]);   // A «reemplaza» a C: ciclo
        $this->assertSame([EmisionVigente::ESTADO_ANOMALIA, EmisionVigente::ANOMALIA_CICLO, null], [EmisionVigente::desdeEmision($a)['estado'], EmisionVigente::desdeEmision($a)['anomalia'], EmisionVigente::desdeEmision($a)['vigente']]);

        DB::table('cf_emisiones')->where('id', $a->id)->update(['reemplaza_id' => null]);
        $otra = $this->emision('OTRA PERSONA', 'AB12345');
        DB::table('cf_emisiones')->where('id', $otra->id)->update(['reemplaza_id' => $a->id]);   // un eslabón de OTRO participante
        $this->assertSame(EmisionVigente::ANOMALIA_CORRUPTA, EmisionVigente::desdeEmision($a)['anomalia']);

        $this->assertEqualsCanonicalizing([$a->id, $b->id, 99999999], array_keys(EmisionVigente::resolverLote([$a->id, $b->id, 99999999])));
    }

    // ── Verificación pública ─────────────────────────────────────────────────────────────────────────────

    public function test_el_codigo_historico_de_un_reemplazado_conduce_a_la_emision_vigente_de_la_cadena(): void
    {
        [$a, $b, $c] = $this->cadena(3);
        $this->reemplazar(1, $a);   // el histórico 1 tiene el código 5237

        $r = $this->publica('5237')->assertOk();

        $r->assertSee('Este certificado histórico fue reemplazado por una versión posterior.');
        $this->assertStringContainsString('href="'.UrlVerificacion::para($c->codigo).'"', $r->getContent(), 'llega a C, no se queda en A');
        $this->assertStringNotContainsString($a->codigo, $r->getContent());
        $this->assertStringNotContainsString($b->codigo, $r->getContent());
    }

    public function test_el_codigo_moderno_viejo_mantiene_su_comportamiento_sin_enlazar_a_la_siguiente_y_el_vigente_es_normal(): void
    {
        [$a, , $c] = $this->cadena(3);

        $vieja = $this->publica($a->codigo)->assertOk();
        $vieja->assertSee('Credencial revocada');
        $this->assertStringNotContainsString($c->codigo, $vieja->getContent(), 'no se revela la relación hacia la versión nueva');
        $this->assertStringNotContainsString('version-posterior', $vieja->getContent());
        $this->publica($c->codigo)->assertOk()->assertSee('Credencial válida');
    }

    public function test_con_la_cadena_revocada_o_corrupta_el_codigo_historico_no_inventa_vigencia(): void
    {
        [$a, , $c] = $this->cadena(3);
        $this->reemplazar(1, $a);
        app(EmisorCredencial::class)->revocar($c, 'Revocación de prueba de la emisión.', null);

        $r = $this->publica('5237')->assertOk();
        $this->assertStringContainsString('href="'.UrlVerificacion::para($c->codigo).'"', $r->getContent(), 'sin vigente: la última, que mostrará su revocación');
        $this->publica($c->codigo)->assertSee('Credencial revocada');

        DB::table('cf_emisiones')->where('id', $a->id)->update(['reemplaza_id' => $c->id]);   // ciclo
        $corrupta = $this->publica('5237')->assertOk();
        $corrupta->assertSee('en revisión');
        $this->assertStringNotContainsString('version-posterior', $corrupta->getContent());
        $this->assertStringNotContainsString($c->codigo, $corrupta->getContent());
    }

    public function test_la_verificacion_oculta_las_filas_de_fecha_e_intensidad_vacias(): void
    {
        $plantilla = $this->plantillaLista([$this->elemento(['field' => 'nombre_completo', 'y' => 60]), $this->elemento(['field' => 'documento', 'y' => 120])]);
        $lote = Lote::create(['plantilla_id' => $plantilla->id, 'nombre' => 'Lote sin fecha', 'datos_comunes' => ['evento' => 'CONGRESO HISTORICO', 'fecha' => '', 'intensidad_horaria' => '']]);
        $e = app(EmisorCredencial::class)->emitir($this->participante($lote, 'PERSONA DE PRUEBA', '12345678'), $lote->fresh(), null);

        $valida = $this->publica($e->codigo)->assertOk();
        $valida->assertSee('Credencial válida')->assertSee('CONGRESO HISTORICO')->assertSee('Fecha de emisión');
        foreach (['Fecha del evento', 'Intensidad horaria', 'data-dato="fecha-evento"', 'data-dato="intensidad"'] as $fila) {
            $this->assertStringNotContainsString($fila, $valida->getContent());
        }
        app(EmisorCredencial::class)->revocar($e, 'Revocación de prueba de la emisión.', null);
        $revocada = $this->publica($e->codigo)->assertSee('Credencial revocada');
        $this->assertStringNotContainsString('Fecha del evento', $revocada->getContent());
    }

    public function test_la_verificacion_conserva_las_filas_cuando_hay_valores(): void
    {
        $lote = $this->loteCon(1);
        $e = app(EmisorCredencial::class)->emitir($lote->participantes()->firstOrFail(), $lote, null);

        $this->publica($e->codigo)->assertSee('Fecha del evento')->assertSee('17 de septiembre de 2026')->assertSee('Intensidad horaria')->assertSee('30 horas');
    }

    public function test_un_historico_normal_no_reemplazado_no_cambia(): void
    {
        $importadas = DB::table('cf_descargas')->count();
        $r = $this->panelDe('1000001', self::CORREO);

        $this->assertContains('disponible', $this->tarjetas($r));
        $r->assertSee('Descargar PDF')->assertSee('Código del certificado: 5237')->assertDontSee('Descargar certificado');
        $this->get(route('portal.descargar', $this->idCert(1)))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $nueva = DB::table('cf_descargas')->orderByDesc('id')->first();
        $this->assertSame([$this->idCert(1), null, 'credential_flow'], [(int) $nueva->certificado_legado_id, $nueva->emision_id, $nueva->origen], 'la descarga histórica sigue contra el histórico');
        $this->assertSame($importadas + 1, DB::table('cf_descargas')->count());
    }

    public function test_una_copia_consolidada_cuyo_canonico_fue_reemplazado_muestra_una_tarjeta_y_descarga_la_emision(): void
    {
        // Gina (2000001): dos filas del mismo certificado; la canónica se reemplaza. La copia consolidada no abre otra vía: resuelve al canónico.
        $filas = DB::table('cf_certificados_legado')->where('documento_clave', '2000001')->orderBy('id')->get(['id', 'conciliacion_estado']);
        $this->assertCount(2, $filas);
        $canonico = (int) DB::table('cf_certificados_legado as k')->join('cf_migraciones_map as m', fn ($j) => $j->on('m.destino_id', '=', 'k.id')->where('m.destino_tabla', 'cf_certificados_legado')->where('m.relacion', 'canonico'))->where('k.documento_clave', '2000001')->value('k.id');
        $copia = (int) $filas->pluck('id')->first(fn ($id) => (int) $id !== $canonico);
        $e = $this->emision();
        DB::table('cf_certificados_legado')->where('id', $canonico)->update(['estado' => 'reemplazado', 'reemplazado_por_emision_id' => $e->id]);

        $r = $this->panelDe('2000001', 'gina@example.test');

        $this->assertSame(1, count(array_filter($this->tarjetas($r), fn ($t) => $t === 'actualizado')), 'un certificado lógico, una tarjeta');
        $r->assertSee('Código del certificado: '.$e->codigo);
        $d = $this->get(route('portal.descargar', $copia))->assertOk();
        $this->assertSame($e->pdf_hash, hash_file('sha256', $d->baseResponse->getFile()->getPathname()));
        $this->assertSame(1, DB::table('cf_descargas')->where('emision_id', $e->id)->count());
    }
}
