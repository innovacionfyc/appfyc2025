<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Eventos\ServicioEventos;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

/**
 * Verificación pública de las PRUEBAS INTERNAS (QA). Una emisión es de prueba interna por relaciones estructuradas, nunca por textos: su participante está
 * marcado como contacto de control (`es_control_qa`) o se generó con una plantilla de pruebas internas (`es_plantilla_qa`). La revocación prevalece sobre
 * todo. Las oficiales vigentes no cambian. Datos 100 % sintéticos.
 */
class VerificacionPublicaQaTest extends EmisionesTestCase
{
    private const ADVERTENCIA = 'PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA';

    private const EXPLICACION = 'Este documento fue generado exclusivamente para validar el funcionamiento del sistema. No acredita asistencia, participación ni formación académica.';

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
        $this->assertStringNotContainsString($p->nombre_completo, $html, 'Sin el nombre del contacto');
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

    // ── A. Oficial vigente: idéntica a la actual ───────────────────────────────

    public function test_oficial_vigente_sigue_siendo_credencial_valida_con_sus_datos(): void
    {
        [$lote] = $this->familia();
        $p = $this->oficialDe($lote);
        $e = $this->emitir($lote, $p);

        $r = $this->publica($e->codigo)->assertOk();

        $this->assertSame('valida', $this->estado($r));
        $r->assertSee('Credencial válida')->assertSee($p->nombre_completo)->assertSee('CONGRESO DE FINANZAS')->assertSee('30 horas')->assertSee($e->codigo);
        $this->assertStringNotContainsString('PRUEBA INTERNA', $r->getContent());
        $this->assertStringNotContainsString('No acredita', $r->getContent());
    }

    // ── B. Prueba interna vigente ─────────────────────────────────────────────

    public function test_qa_vigente_con_la_variante_de_su_familia_es_prueba_interna(): void
    {
        [$lote, , $variante] = $this->familia();
        $qa = $this->qaDe($lote);
        $e = $this->emitir($lote, $qa);
        $this->assertSame($variante->id, $e->plantilla_id);

        $this->assertPruebaInterna($this->publica($e->codigo), $e, $qa);
    }

    public function test_qa_vigente_de_otra_familia_que_ademas_es_la_plantilla_qa_por_omision_es_prueba_interna(): void
    {
        $this->familia();
        [$lote, , $variante] = $this->familia();   // la variante más reciente: también es la plantilla QA por omisión (como #22)
        $this->assertSame($variante->id, app(ServicioQa::class)->plantilla()?->id);
        $qa = $this->qaDe($lote);
        $e = $this->emitir($lote, $qa);

        $this->assertPruebaInterna($this->publica($e->codigo), $e, $qa);
    }

    public function test_qa_historico_emitido_con_plantilla_no_qa_hoy_archivada_es_prueba_interna_por_su_participante(): void
    {
        // Como las bases 9-13: se emitió con una plantilla normal antes de existir la marca QA; después se marcó al contacto y se archivó la plantilla.
        $lote = $this->loteCon(1);
        $p = $lote->participantes()->firstOrFail();
        $e = $this->emitir($lote, $p);
        app(ServicioEventos::class)->marcarParticipantesQa($lote, [$p->id], true);
        Plantilla::whereKey($lote->plantilla_id)->update(['archivada_at' => now(), 'motivo_archivo' => 'Prueba de archivo']);
        $this->assertFalse((bool) Plantilla::find($e->plantilla_id)->es_plantilla_qa);

        $this->assertPruebaInterna($this->publica($e->codigo), $e, $p->fresh());
    }

    public function test_basta_una_de_las_dos_marcas_estructuradas_para_seguir_siendo_prueba_interna(): void
    {
        [$lote, , $variante] = $this->familia();
        $qa = $this->qaDe($lote);
        $e = $this->emitir($lote, $qa);

        // Se quita la marca del contacto: la plantilla con la que se generó sigue siendo de pruebas.
        app(ServicioEventos::class)->marcarParticipantesQa($lote, [$qa->id], false);
        $this->assertSame('prueba_interna', $this->estado($this->publica($e->codigo)));

        // Y al revés: la plantilla deja de estar designada, el contacto vuelve a estar marcado.
        app(ServicioEventos::class)->marcarParticipantesQa($lote, [$qa->id], true);
        Plantilla::whereKey($variante->id)->update(['es_plantilla_qa' => false]);
        $this->assertSame('prueba_interna', $this->estado($this->publica($e->codigo)));
    }

    public function test_el_nombre_de_la_plantilla_o_del_evento_no_convierte_en_prueba_a_una_oficial(): void
    {
        $plantilla = $this->plantillaLista();
        $plantilla->update(['nombre' => 'PRUEBA INTERNA QA — '.self::ADVERTENCIA]);
        $lote = $this->loteCon(1, $plantilla);
        $lote->update(['nombre' => 'QA PRUEBA', 'datos_comunes' => ['evento' => 'PRUEBA QA DEL EVENTO', 'fecha' => '17 de septiembre de 2026', 'intensidad_horaria' => '30 horas']]);
        $e = $this->emitir($lote, $lote->participantes()->firstOrFail());

        $this->assertSame('valida', $this->estado($this->publica($e->codigo)->assertOk()->assertSee('Credencial válida')));
    }

    // ── C. Revocadas: la revocación prevalece ─────────────────────────────────

    public function test_qa_revocado_muestra_solo_la_revocacion_sin_datos_personales(): void
    {
        [$lote] = $this->familia();
        $qa = $this->qaDe($lote);
        $e = $this->emitir($lote, $qa);
        $this->revocar($e);

        $r = $this->publica($e->codigo)->assertOk();

        $this->assertSame('revocada', $this->estado($r));
        $r->assertSee('Credencial revocada')->assertSee('CONGRESO DE FINANZAS')->assertSee($e->codigo);
        $html = $r->getContent();
        foreach (['Credencial válida', 'PRUEBA INTERNA', $qa->nombre_completo, (string) $qa->documento, 'QWERTY', '30 horas', '@example.test'] as $nunca) {
            $this->assertStringNotContainsString($nunca, $html);
        }
    }

    public function test_oficial_revocado_conserva_la_respuesta_de_revocacion(): void
    {
        [$lote] = $this->familia();
        $p = $this->oficialDe($lote);
        $e = $this->emitir($lote, $p);
        $this->revocar($e);

        $r = $this->publica($e->codigo)->assertOk()->assertSee('Credencial revocada')->assertSee('Esta credencial ya no es válida. Si recibió una versión más reciente, verifique esa.');

        $this->assertSame('revocada', $this->estado($r));
        $this->assertStringNotContainsString('PRUEBA INTERNA', $r->getContent());
        $this->assertStringNotContainsString($p->nombre_completo, $r->getContent());
    }

    // ── Códigos inexistentes e inválidos ──────────────────────────────────────

    public function test_codigo_inexistente_e_invalido_siguen_siendo_404_sin_eco_aunque_existan_pruebas(): void
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

    // ── Cadenas de reemplazo ──────────────────────────────────────────────────

    public function test_en_una_cadena_qa_la_version_anterior_es_revocada_y_la_vigente_es_prueba_interna(): void
    {
        [$lote] = $this->familia();
        $qa = $this->qaDe($lote);
        $v1 = $this->emitir($lote, $qa);
        $v2 = Emision::findOrFail($this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.reemitir', $v1), ['motivo' => 'Corrección de prueba'])->assertCreated()->json('emision.id'));
        $this->assertSame($v1->id, $v2->reemplaza_id);

        $this->assertSame('revocada', $this->estado($this->publica($v1->codigo)));
        $this->assertPruebaInterna($this->publica($v2->codigo), $v2, $qa);
    }

    public function test_en_una_cadena_oficial_la_version_vigente_sigue_siendo_valida(): void
    {
        [$lote] = $this->familia();
        $p = $this->oficialDe($lote);
        $v1 = $this->emitir($lote, $p);
        $v2 = Emision::findOrFail($this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.reemitir', $v1), ['motivo' => 'Corrección de datos'])->assertCreated()->json('emision.id'));

        $this->assertSame('revocada', $this->estado($this->publica($v1->codigo)));
        $this->assertSame('valida', $this->estado($this->publica($v2->codigo)->assertSee('Credencial válida')->assertSee($p->nombre_completo)));
    }

    public function test_para_clasificar_solo_lee_las_dos_marcas_qa_y_nunca_datos_vivos(): void
    {
        [$lote] = $this->familia();
        $e = $this->emitir($lote, $this->qaDe($lote));
        $consultas = [];
        DB::listen(function ($q) use (&$consultas) {
            $sql = strtolower($q->sql);
            if (! str_contains($sql, 'pragma') && ! str_contains($sql, 'sqlite_master') && ! str_contains($sql, 'information_schema') && (str_contains($sql, 'cf_participantes') || str_contains($sql, 'cf_plantillas') || str_contains($sql, 'cf_lotes') || str_contains($sql, 'usuarios'))) {
                $consultas[] = $sql;
            }
        });

        $this->publica($e->codigo)->assertOk();

        // La marca del participante basta: no hace falta consultar la plantilla.
        $this->assertSame(['select "es_control_qa" from "cf_participantes" where "id" = ? limit 1'], $consultas);
    }

    // ── Cabeceras: la prueba interna lleva las mismas protecciones que la oficial ─

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
