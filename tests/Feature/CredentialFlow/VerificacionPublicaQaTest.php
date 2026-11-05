<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

/**
 * Verificación pública de las PRUEBAS INTERNAS (QA). La naturaleza de una emisión se decide por el DOCUMENTO EMITIDO: su diseño congelado
 * (cf_emisiones.diseno_snapshot) lleva o no el marcador institucional, un elemento de texto fijo cuyo texto es exactamente «PRUEBA INTERNA — SIN VALIDEZ
 * ACADÉMICA». Las marcas vivas del participante o de la plantilla no cambian retroactivamente la clasificación. La revocación prevalece. Datos sintéticos.
 */
class VerificacionPublicaQaTest extends EmisionesTestCase
{
    private const ADVERTENCIA = 'PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA';

    private const EXPLICACION = 'Este documento fue generado exclusivamente para validar el funcionamiento del sistema. No acredita asistencia, participación ni formación académica.';

    private const CAMPOS = ['nombre_completo', 'documento', 'evento', 'fecha', 'intensidad_horaria'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => [
            'database/migrations/2026_10_06_100000_cf_eventos_table.php',
            'database/migrations/2026_10_06_100100_add_evento_id_to_cf_lotes_table.php',
            'database/migrations/2026_10_06_100200_add_correo_columns_to_cf_participantes_table.php',
            'database/migrations/2026_10_06_100300_cf_plantillas_legado_table.php',
            'database/migrations/2026_10_06_100400_cf_certificados_legado_table.php',
            'database/migrations/2026_10_06_100500_cf_descargas_table.php',
            'database/migrations/2026_10_06_100600_cf_correos_table.php',
            'database/migrations/2026_10_06_100700_add_plantilla_legado_to_cf_eventos_table.php',
            'database/migrations/2026_10_08_100000_cf_migraciones_corridas_table.php',
            'database/migrations/2026_10_08_100100_add_corrida_id_to_tablas_historicas.php',
            'database/migrations/2026_10_21_100000_cf_contactos_qa_y_plantilla_qa.php',
        ], '--force' => true])->assertExitCode(0);
        RateLimiter::clear('cf-verificacion-fallos:127.0.0.1');
        for ($i = 1; $i <= 2; $i++) {
            $doc = (string) (70000000 + $i);
            DB::table('cf_contactos_qa')->insert(['nombre_completo' => "QA SINTÉTICO {$i}", 'documento' => $doc, 'documento_clave' => Texto::claveDocumento($doc),
                'correo' => "qa{$i}@example.test", 'correo_normalizado' => "qa{$i}@example.test", 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Familia institucional + su variante de pruebas (como #19/#20 o #21/#22): base con un participante real y los contactos QA incorporados. */
    private function familia(): array
    {
        $institucional = $this->plantillaLista();
        $this->artisan('credential-flow:plantilla-variante-qa', ['origen' => $institucional->id, '--ejecutar' => true])->assertExitCode(0);
        $variante = Plantilla::query()->findOrFail($institucional->fresh()->variante_qa_id);
        $lote = $this->loteCon(1, $institucional);
        app(ServicioQa::class)->incorporar($lote);

        return [$lote->fresh(), $institucional, $variante];
    }

    /** Plantilla con los cinco campos y, además, los elementos indicados (para probar qué cuenta como marcador y qué no). */
    private function plantillaCon(array ...$extra): Plantilla
    {
        $els = array_map(fn (string $c, int $i) => $this->elemento(['field' => $c, 'y' => 60 + $i * 60]), self::CAMPOS, array_keys(self::CAMPOS));
        foreach ($extra as $i => $e) {
            $els[] = $this->elemento($e + ['y' => 400 + $i * 30, 'fontSize' => 14]);
        }

        return $this->plantillaLista($els);
    }

    private function oficialDe(Lote $lote): Participante
    {
        return Participante::where('lote_id', $lote->id)->where('es_control_qa', false)->orderBy('id')->firstOrFail();
    }

    private function qaDe(Lote $lote): Participante
    {
        return Participante::where('lote_id', $lote->id)->where('es_control_qa', true)->orderBy('id')->firstOrFail();
    }

    private function emitir(Lote $lote, Participante $p): Emision
    {
        $this->emitirPor($lote, $p)->assertCreated();

        return Emision::where('participante_id', $p->id)->where('estado', Emision::EMITIDA)->firstOrFail();
    }

    private function oficialCon(Plantilla $plantilla): array
    {
        $lote = $this->loteCon(1, $plantilla);
        $p = $lote->participantes()->firstOrFail();

        return [$this->emitir($lote, $p), $p];
    }

    private function reemitir(Emision $e): Emision
    {
        return Emision::findOrFail($this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.reemitir', $e), ['motivo' => 'Corrección de prueba'])->assertCreated()->json('emision.id'));
    }

    private function revocar(Emision $e): void
    {
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $e), ['motivo' => 'Motivo reservado QWERTY'])->assertOk();
    }

    private function publica(string $codigo): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->get('/verificar/'.$codigo);
    }

    private function estado(TestResponse $r): string
    {
        preg_match('/<section class="tarjeta" data-estado="([a-z_]+)"/', $r->getContent(), $m);

        return $m[1] ?? '?';
    }

    /** Lo que una prueba interna vigente debe mostrar, y lo que nunca debe mostrar (ni datos personales ni detalles internos). */
    private function assertPruebaInterna(TestResponse $r, Emision $e, Participante $p): void
    {
        $r->assertOk()->assertSee(self::ADVERTENCIA)->assertSee(self::EXPLICACION)->assertSee('CONGRESO DE FINANZAS')->assertSee($e->codigo);
        $this->assertSame('prueba_interna', $this->estado($r));
        $html = $r->getContent();
        $this->assertStringNotContainsString('Credencial válida', $html);
        $this->assertStringNotContainsString($p->nombre_completo, $html, 'Sin el nombre');
        $this->assertStringNotContainsString('QA SINTÉTICO', $html);
        $this->assertStringNotContainsString((string) $p->documento, $html);
        $this->assertStringNotContainsString('7000000', $html);
        $this->assertStringNotContainsString('@example.test', $html);
        $this->assertStringNotContainsString('30 horas', $html, 'Sin intensidad: no acredita formación');
        $this->assertStringNotContainsString('Lote ', $html);
        $this->assertStringNotContainsString('Plantilla ', $html);
        $this->assertStringNotContainsString('participante', strtolower($html));
        $this->assertStringNotContainsString('href=', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    private function assertValida(TestResponse $r, Participante $p): void
    {
        $this->assertSame('valida', $this->estado($r->assertOk()->assertSee('Credencial válida')->assertSee($p->nombre_completo)));
        $this->assertStringNotContainsString('No acredita asistencia', $r->getContent());
    }

    // ── Oficial vigente: idéntica a la actual ─────────────────────────────────

    public function test_oficial_vigente_sigue_siendo_credencial_valida_con_sus_datos(): void
    {
        [$lote] = $this->familia();
        $p = $this->oficialDe($lote);
        $e = $this->emitir($lote, $p);

        $r = $this->publica($e->codigo);

        $this->assertValida($r, $p);
        $r->assertSee('CONGRESO DE FINANZAS')->assertSee('30 horas')->assertSee($e->codigo);
        $this->assertStringNotContainsString('PRUEBA INTERNA', $r->getContent());
    }

    // ── E. Variantes QA modernas (#20 y #22) ──────────────────────────────────

    public function test_e_qa_vigente_con_la_variante_de_su_familia_es_prueba_interna(): void
    {
        [$lote, , $variante] = $this->familia();
        $qa = $this->qaDe($lote);
        $e = $this->emitir($lote, $qa);
        $this->assertSame($variante->id, $e->plantilla_id);

        $this->assertPruebaInterna($this->publica($e->codigo), $e, $qa);
    }

    public function test_e_qa_vigente_de_otra_familia_que_ademas_es_la_plantilla_qa_por_omision_es_prueba_interna(): void
    {
        $this->familia();
        [$lote, , $variante] = $this->familia();   // la variante más reciente: también es la plantilla QA por omisión (como #22)
        $this->assertSame($variante->id, app(ServicioQa::class)->plantilla()?->id);
        $qa = $this->qaDe($lote);
        $e = $this->emitir($lote, $qa);

        $this->assertPruebaInterna($this->publica($e->codigo), $e, $qa);
    }

    // ── A, B, C. Las marcas vivas no cambian la naturaleza de lo ya emitido ────

    public function test_a_un_oficial_emitido_sigue_oficial_aunque_despues_se_marque_qa_a_su_participante(): void
    {
        [$lote] = $this->familia();
        $p = $this->oficialDe($lote);
        $e = $this->emitir($lote, $p);

        // Con el comando real (marcar contactos de control después de emitir sigue siendo una operación válida).
        DB::table('cf_participantes')->where('id', $p->id)->update(['correo' => 'real1@example.test', 'correo_normalizado' => 'real1@example.test']);
        $this->artisan('credential-flow:qa-participantes', ['lote' => $lote->id, '--correo' => ['real1@example.test'], '--ejecutar' => true])->assertExitCode(0);
        $this->assertTrue((bool) $p->fresh()->es_control_qa);

        $this->assertValida($this->publica($e->codigo), $p);
    }

    public function test_b_un_qa_emitido_sigue_qa_aunque_despues_se_quite_la_marca_de_su_participante(): void
    {
        [$lote] = $this->familia();
        $qa = $this->qaDe($lote);
        $e = $this->emitir($lote, $qa);

        $this->artisan('credential-flow:qa-participantes', ['lote' => $lote->id, '--correo' => ['qa1@example.test'], '--quitar' => true, '--ejecutar' => true])->assertExitCode(0);
        $this->assertFalse((bool) $qa->fresh()->es_control_qa);

        $this->assertPruebaInterna($this->publica($e->codigo), $e, $qa);
    }

    public function test_c_un_qa_emitido_sigue_qa_aunque_cambie_la_designacion_de_su_plantilla(): void
    {
        [$lote, , $variante] = $this->familia();
        $qa = $this->qaDe($lote);
        $e = $this->emitir($lote, $qa);

        // La pantalla ya no lo permite (ProteccionMarcasQaTest); aquí se simula un cambio directo, y además sin la marca del participante.
        DB::table('cf_plantillas')->where('id', $variante->id)->update(['es_plantilla_qa' => false]);
        DB::table('cf_participantes')->where('id', $qa->id)->update(['es_control_qa' => false]);

        $this->assertPruebaInterna($this->publica($e->codigo), $e, $qa);
    }

    // ── D. Históricas con plantilla normal (como #9–#12) ──────────────────────

    public function test_d_una_qa_historica_de_plantilla_normal_con_el_marcador_impreso_es_prueba_interna_aunque_se_archive(): void
    {
        // Como las bases 9–13: plantilla NO designada QA, con el aviso como texto fijo; participante sin marca al emitir; después se archiva la plantilla.
        $plantilla = $this->plantillaCon(['text' => self::ADVERTENCIA], ['text' => 'Este documento no acredita asistencia ni participación.']);
        [$e, $p] = $this->oficialCon($plantilla);
        Plantilla::whereKey($plantilla->id)->update(['archivada_at' => now(), 'motivo_archivo' => 'Prueba de archivo']);
        $this->assertFalse((bool) Plantilla::find($e->plantilla_id)->es_plantilla_qa);
        $this->assertFalse((bool) $p->fresh()->es_control_qa);

        $this->assertPruebaInterna($this->publica($e->codigo), $e, $p);
    }

    public function test_d_el_marcador_se_reconoce_sin_importar_mayusculas_tildes_ni_puntuacion(): void
    {
        foreach (['prueba interna - sin validez academica', '  PRUEBA INTERNA – SIN VALIDEZ ACADÉMICA.  ', 'Prueba Interna — Sin Validez Académica'] as $texto) {
            [$e, $p] = $this->oficialCon($this->plantillaCon(['text' => $texto]));
            $this->assertSame('prueba_interna', $this->estado($this->publica($e->codigo)), $texto);
        }
    }

    // ── F, G. Ningún texto libre ni dato variable convierte una oficial en prueba ─

    public function test_f_el_nombre_del_evento_de_la_base_o_de_la_plantilla_no_convierte_en_prueba_a_una_oficial(): void
    {
        $plantilla = $this->plantillaLista();
        $plantilla->update(['nombre' => self::ADVERTENCIA]);
        $lote = $this->loteCon(1, $plantilla);
        $lote->update(['nombre' => 'QA '.self::ADVERTENCIA, 'datos_comunes' => ['evento' => self::ADVERTENCIA, 'fecha' => '17 de septiembre de 2026', 'intensidad_horaria' => '30 horas']]);
        $p = $lote->participantes()->firstOrFail();
        $p->update(['nombre_completo' => 'PRUEBA INTERNA SIN VALIDEZ ACADEMICA']);
        $e = $this->emitir($lote, $p);

        $this->assertValida($this->publica($e->codigo), $p->fresh());
    }

    public function test_g_textos_libres_que_no_son_el_marcador_institucional_no_convierten_en_prueba_a_una_oficial(): void
    {
        $casos = [
            'solo «PRUEBA INTERNA»' => ['text' => 'PRUEBA INTERNA'],
            'frase dentro de un texto más largo' => ['text' => 'Taller de PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA 2026'],
            'frase partida' => ['text' => 'PRUEBA INTERNA — SIN VALIDEZ'],
            'en un campo dinámico (no es texto fijo)' => ['field' => 'evento', 'text' => self::ADVERTENCIA, 'y' => 450],
            'como «Texto antes» de un campo' => ['field' => 'intensidad_horaria', 'prefix' => self::ADVERTENCIA.' ', 'y' => 480],
        ];
        foreach ($casos as $caso => $el) {
            [$e, $p] = $this->oficialCon($this->plantillaCon($el));
            $this->assertSame('valida', $this->estado($this->publica($e->codigo)), $caso);
        }
    }

    // ── H. Revocadas: la revocación prevalece ─────────────────────────────────

    public function test_h_qa_revocado_muestra_solo_la_revocacion_sin_datos_personales(): void
    {
        [$lote] = $this->familia();
        $qa = $this->qaDe($lote);
        $e = $this->emitir($lote, $qa);
        $this->revocar($e);
        DB::table('cf_participantes')->where('id', $qa->id)->update(['es_control_qa' => false]);

        $r = $this->publica($e->codigo)->assertOk();

        $this->assertSame('revocada', $this->estado($r));
        $r->assertSee('Credencial revocada')->assertSee('CONGRESO DE FINANZAS')->assertSee($e->codigo);
        foreach (['Credencial válida', 'PRUEBA INTERNA', $qa->nombre_completo, (string) $qa->documento, 'QWERTY', '30 horas', '@example.test'] as $nunca) {
            $this->assertStringNotContainsString($nunca, $r->getContent());
        }
    }

    public function test_h_oficial_revocado_conserva_la_respuesta_de_revocacion_aunque_cambie_su_marca(): void
    {
        [$lote] = $this->familia();
        $p = $this->oficialDe($lote);
        $e = $this->emitir($lote, $p);
        $this->revocar($e);
        DB::table('cf_participantes')->where('id', $p->id)->update(['es_control_qa' => true]);

        $r = $this->publica($e->codigo)->assertOk()->assertSee('Credencial revocada')->assertSee('Esta credencial ya no es válida. Si recibió una versión más reciente, verifique esa.');

        $this->assertSame('revocada', $this->estado($r));
        $this->assertStringNotContainsString('PRUEBA INTERNA', $r->getContent());
        $this->assertStringNotContainsString($p->nombre_completo, $r->getContent());
    }

    // ── I. Reemisiones y reemplazos ───────────────────────────────────────────

    public function test_i_en_una_cadena_qa_la_anterior_queda_revocada_y_la_nueva_es_prueba_por_su_propio_documento(): void
    {
        [$lote] = $this->familia();
        $qa = $this->qaDe($lote);
        $v1 = $this->emitir($lote, $qa);
        $v2 = $this->reemitir($v1);
        $this->assertSame($v1->id, $v2->reemplaza_id);

        $this->assertSame('revocada', $this->estado($this->publica($v1->codigo)));
        $this->assertPruebaInterna($this->publica($v2->codigo), $v2, $qa);
    }

    public function test_i_en_una_cadena_oficial_la_nueva_version_sigue_siendo_valida(): void
    {
        [$lote] = $this->familia();
        $p = $this->oficialDe($lote);
        $v1 = $this->emitir($lote, $p);
        $v2 = $this->reemitir($v1);

        $this->assertSame('revocada', $this->estado($this->publica($v1->codigo)));
        $this->assertValida($this->publica($v2->codigo), $p);
    }

    // ── J. Snapshot ausente o dañado: nunca «válida», nunca error 500 ─────────

    public function test_j_un_diseno_emitido_ausente_o_danado_no_afirma_validez_ni_rompe_la_pagina(): void
    {
        [$lote] = $this->familia();
        $p = $this->oficialDe($lote);
        $e = $this->emitir($lote, $p);

        foreach (['{', 'null', '[]', '{}', '{"elements":"x"}', '{"elements":[]}', '{"elements":{"a":{"type":"text"}}}', '{"elements":[1,2]}'] as $danado) {
            DB::table('cf_emisiones')->where('id', $e->id)->update(['diseno_snapshot' => $danado]);

            $r = $this->publica($e->codigo)->assertOk();

            $this->assertSame('no_verificable', $this->estado($r), $danado);
            $r->assertSee('Verificación no disponible')->assertSee($e->codigo);
            foreach (['Credencial válida', 'PRUEBA INTERNA', $p->nombre_completo, '30 horas'] as $nunca) {
                $this->assertStringNotContainsString($nunca, $r->getContent(), $danado);
            }
        }

        // Revocada con el diseño dañado: la revocación no depende del diseño.
        $this->revocar($e);
        $this->assertSame('revocada', $this->estado($this->publica($e->codigo)));
    }

    // ── Códigos inexistentes e inválidos ──────────────────────────────────────

    public function test_codigo_inexistente_e_invalido_siguen_siendo_404_sin_eco(): void
    {
        [$lote] = $this->familia();
        $this->emitir($lote, $this->qaDe($lote));

        foreach (['CF-ZZZZ-ZZZZ-ZZZZ', 'NO-ES-UN-CODIGO'] as $codigo) {
            $r = $this->publica($codigo)->assertNotFound()->assertSee('Credencial no encontrada');
            $this->assertSame('no_encontrada', $this->estado($r));
            $this->assertStringNotContainsString($codigo, $r->getContent());
            $this->assertStringNotContainsString('PRUEBA INTERNA', $r->getContent());
        }
    }

    // ── Solo lo emitido: ninguna consulta a participante, base ni plantilla ───

    public function test_para_clasificar_solo_lee_la_emision(): void
    {
        [$lote] = $this->familia();
        $e = $this->emitir($lote, $this->qaDe($lote));
        $ajenas = [];
        DB::listen(function ($q) use (&$ajenas) {
            foreach (['cf_participantes', 'cf_lotes', 'cf_plantillas', 'usuarios'] as $t) {
                if (str_contains($q->sql, $t)) {
                    $ajenas[] = $t;
                }
            }
        });

        $this->publica($e->codigo)->assertOk();

        $this->assertSame([], $ajenas);
    }

    public function test_la_prueba_interna_lleva_las_mismas_cabeceras_de_seguridad_que_la_oficial(): void
    {
        [$lote] = $this->familia();
        $oficial = $this->publica($this->emitir($lote, $this->oficialDe($lote))->codigo);
        $prueba = $this->publica($this->emitir($lote, $this->qaDe($lote))->codigo);

        foreach (['cache-control', 'x-robots-tag', 'referrer-policy', 'x-content-type-options', 'x-frame-options'] as $h) {
            $this->assertSame($oficial->headers->get($h), $prueba->headers->get($h), $h);
        }
        $this->assertSame(
            preg_replace("/'nonce-[^']+'/", 'N', (string) $oficial->headers->get('content-security-policy')),
            preg_replace("/'nonce-[^']+'/", 'N', (string) $prueba->headers->get('content-security-policy')),
        );
        $this->assertEmpty($prueba->headers->getCookies(), 'Sin cookies');
    }
}
