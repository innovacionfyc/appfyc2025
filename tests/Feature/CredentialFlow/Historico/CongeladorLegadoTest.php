<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Support\CredentialFlow\Legado\CongeladoNoPermitido;
use App\Support\CredentialFlow\Legado\CongeladorLegado;
use App\Support\CredentialFlow\Legado\ElegibilidadLegado;
use App\Support\CredentialFlow\Legado\PdfHistoricoInconsistente;
use App\Support\CredentialFlow\Legado\RendererLegado;
use App\Support\CredentialFlow\Legado\RenderNoPermitido;
use App\Support\CredentialFlow\Legado\ResolutorPlantillaDirectorio;
use App\Support\CredentialFlow\Legado\ResolutorPlantillaLegado;
use App\Support\CredentialFlow\Legado\RutasLegado;
use App\Support\CredentialFlow\Migracion\MigradorHistorico;
use App\Support\CredentialFlow\Migracion\RollbackCorrida;
use App\Support\CredentialFlow\Migracion\RollbackNoPermitido;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/** Congelado perezoso de PDF histórico (política canónica, atomicidad, no sobrescritura e integridad). Datos 100 % sintéticos. */
class CongeladorLegadoTest extends HistoricoTestCase
{
    protected function tearDown(): void
    {
        CongeladorLegado::$despuesDeBloquear = null;
        CongeladorLegado::$antesDeConfirmar = null;
        parent::tearDown();
    }

    /** Códigos de 4 y 5 cifras (como los reales) y un participante sin tipo de documento pero elegible. */
    private function datosConCodigos(): array
    {
        $d = $this->datos();
        foreach ([1 => 5237, 3 => 5555, 4 => 5600, 7 => 5300, 8 => 5300, 9 => 5700, 10 => 5700, 11 => 12045, 13 => 5800] as $id => $codigo) {
            $d = $this->conCambio($d, 'participante', $id, ['num_verificacion' => $codigo]);
        }

        return $this->conCambio($d, 'participante', 2, ['tipo_documento' => null, 'num_verificacion' => 5901]);
    }

    private function congelador(?ResolutorPlantillaLegado $r = null): CongeladorLegado
    {
        return new CongeladorLegado(new RendererLegado, $r ?? new ResolutorPlantillaDirectorio($this->dirImagenes));
    }

    private function cert(int $old): CertificadoLegado
    {
        return CertificadoLegado::findOrFail($this->idCert($old));
    }

    private function rutaFisica(int $id): string
    {
        return Storage::disk('local')->path(RutasLegado::certificado($id));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrarSintetico($this->datosConCodigos());
    }

    // ── Generar, reutilizar y política canónica ───────────────────────────────

    public function test_la_primera_llamada_genera_y_congela_y_las_siguientes_reutilizan_el_mismo_pdf(): void
    {
        $c = $this->cert(1);

        $a = $this->congelador()->servir($c);
        $b = $this->congelador()->servir($c->fresh());
        $d = $this->congelador()->servir($c->fresh());

        $this->assertTrue($a->recienGenerado);
        $this->assertFalse($b->recienGenerado);
        $this->assertFalse($d->recienGenerado);
        $this->assertSame([$a->sha256, $a->bytes, $a->ruta], [$b->sha256, $b->bytes, $b->ruta]);
        $this->assertSame([$a->sha256, $a->bytes], [$d->sha256, $d->bytes]);

        // Ruta privada exacta y metadatos en la fila.
        $this->assertSame("credential-flow/legado/certificados/{$c->id}/certificado.pdf", $a->ruta);
        $fila = CertificadoLegado::find($c->id);
        $this->assertSame([$a->ruta, $a->sha256, $a->bytes], [$fila->pdf_archivo, $fila->pdf_hash, $fila->pdf_bytes]);
        $this->assertNotNull($fila->materializado_at);
        $this->assertTrue($fila->materializado());

        // El archivo es un PDF de una página y coincide con lo registrado.
        $bytes = Storage::disk('local')->get($a->ruta);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertSame($a->sha256, hash('sha256', $bytes));
        $this->assertSame($a->bytes, strlen($bytes));
        $this->assertSame(1, preg_match_all('#/Type /Page\b#', $bytes));
        $this->assertStringContainsString('(CC: 1.000.001) Tj', $bytes);
        $this->assertStringContainsString('(5237) Tj', $bytes);
        // Fecha de creación del PDF: la del snapshot (registrada por la corrida), nunca «ahora».
        $this->assertStringContainsString('/CreationDate (D:20261005134800)', $bytes);
    }

    public function test_el_pdf_vive_en_el_disco_privado_y_nunca_en_public(): void
    {
        $c = $this->cert(1);
        $this->congelador()->servir($c);

        $this->assertTrue(Storage::disk('local')->exists(RutasLegado::certificado($c->id)));
        $this->assertFalse(Storage::disk('public')->exists(RutasLegado::certificado($c->id)));
        $this->assertSame([], Storage::disk('public')->allFiles(), 'No se escribe nada en el disco público');
        $this->assertStringStartsWith('credential-flow/legado/certificados/', $c->fresh()->pdf_archivo);
    }

    public function test_un_tipo_de_documento_vacio_elegible_se_congela_con_su_texto_historico(): void
    {
        $c = $this->cert(2);
        $this->assertNull($c->tipo_documento);
        $this->assertSame('ok', $c->conciliacion_estado);

        $a = $this->congelador()->servir($c);

        $this->assertStringContainsString('(: 1.000.002) Tj', Storage::disk('local')->get($a->ruta));
    }

    public function test_politica_canonica_el_pdf_vive_solo_en_el_canonico_y_el_duplicado_resuelve_a_el(): void
    {
        $canonico = $this->cert(7);
        $copia = $this->cert(8);
        $this->assertSame('duplicado_consolidado', $copia->conciliacion_estado);

        $deCopia = $this->congelador()->servir($copia);
        $deCanonico = $this->congelador()->servir($canonico);

        $this->assertTrue($deCopia->recienGenerado, 'Pedir la copia congela el canónico');
        $this->assertFalse($deCanonico->recienGenerado);
        $this->assertSame($deCopia->ruta, $deCanonico->ruta);
        $this->assertSame(RutasLegado::certificado($canonico->id), $deCopia->ruta);
        $this->assertNull($copia->fresh()->pdf_archivo, 'La fila duplicada NO guarda PDF');
        $this->assertNotNull($canonico->fresh()->pdf_archivo);
        $this->assertCount(1, Storage::disk('local')->allFiles('credential-flow/legado/certificados'), 'Un solo PDF para los dos registros');
        $this->assertSame($canonico->id, ElegibilidadLegado::canonico($copia)->id);
    }

    // ── Elegibilidad ──────────────────────────────────────────────────────────

    /** @return array<string,array{0:int,1:string}> participante, motivo */
    public static function noElegibles(): array
    {
        return [
            'documento en revisión' => [4, ElegibilidadLegado::REVISION_DOCUMENTO],
            'plantilla pendiente' => [3, ElegibilidadLegado::PENDIENTE_PLANTILLA],
            'conflictivo' => [9, ElegibilidadLegado::PENDIENTE_CONCILIACION],
            'conflictivo con plantilla pendiente' => [13, ElegibilidadLegado::PENDIENTE_CONCILIACION],
            'documento vacío' => [5, ElegibilidadLegado::REVISION_DOCUMENTO],
        ];
    }

    #[DataProvider('noElegibles')]
    public function test_los_certificados_no_habilitables_no_generan_pdf(int $old, string $motivo): void
    {
        $c = $this->cert($old);

        try {
            $this->congelador()->servir($c);
            $this->fail('Debía negarse');
        } catch (CongeladoNoPermitido $e) {
            $this->assertSame($motivo, $e->motivo);
        }
        $this->assertNull($c->fresh()->pdf_archivo);
        $this->assertSame([], Storage::disk('local')->allFiles('credential-flow/legado'));
    }

    public function test_revocado_o_reemplazado_no_se_sirven(): void
    {
        foreach (['revocado' => ElegibilidadLegado::REVOCADO, 'reemplazado' => ElegibilidadLegado::REEMPLAZADO] as $estado => $motivo) {
            $c = $this->cert($estado === 'revocado' ? 1 : 2);
            $c->forceFill(['estado' => $estado])->save();

            try {
                $this->congelador()->servir($c->fresh());
                $this->fail("Debía negarse ($estado)");
            } catch (CongeladoNoPermitido $e) {
                $this->assertSame($motivo, $e->motivo);
            }
        }
        $this->assertSame([], Storage::disk('local')->allFiles('credential-flow/legado'));
    }

    public function test_un_pdf_ya_congelado_de_un_certificado_revocado_no_se_borra_pero_no_se_sirve(): void
    {
        $c = $this->cert(1);
        $a = $this->congelador()->servir($c);
        $c->fresh()->forceFill(['estado' => 'revocado'])->save();

        try {
            $this->congelador()->servir($c->fresh());
            $this->fail('Debía negarse');
        } catch (CongeladoNoPermitido $e) {
            $this->assertSame(ElegibilidadLegado::REVOCADO, $e->motivo);
        }
        $this->assertTrue(Storage::disk('local')->exists($a->ruta), 'El PDF congelado no se borra');
        $this->assertSame($a->sha256, $c->fresh()->pdf_hash);
    }

    public function test_si_falta_la_imagen_se_niega_deja_constancia_y_no_hay_pdf(): void
    {
        $vacio = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sin_imagenes_'.bin2hex(random_bytes(4));
        mkdir($vacio);
        $c = $this->cert(1);

        try {
            $this->congelador(new ResolutorPlantillaDirectorio($vacio))->servir($c);
            $this->fail('Debía negarse');
        } catch (RenderNoPermitido $e) {
            $this->assertSame(RenderNoPermitido::ARCHIVO_AUSENTE, $e->codigo);
        } finally {
            @rmdir($vacio);
        }

        $f = $c->fresh();
        $this->assertNull($f->pdf_archivo);
        $this->assertSame([1, 'ARCHIVO_AUSENTE'], [$f->intentos_generacion, $f->ultimo_error_codigo]);
        // Y después, con la imagen disponible, se genera y se limpia el último error.
        $this->congelador()->servir($c->fresh());
        $this->assertNull($c->fresh()->ultimo_error_codigo);
    }

    // ── Atomicidad ────────────────────────────────────────────────────────────

    public function test_si_falla_antes_de_confirmar_la_metadata_no_queda_y_el_reintento_adopta_el_archivo_identico(): void
    {
        $c = $this->cert(1);
        CongeladorLegado::$antesDeConfirmar = fn () => throw new RuntimeException('caída simulada tras el rename');

        try {
            $this->congelador()->servir($c);
            $this->fail('Debía lanzar');
        } catch (RuntimeException) {
        }
        CongeladorLegado::$antesDeConfirmar = null;

        // La transacción revirtió la metadata; el archivo quedó (sin temporales).
        $this->assertNull($c->fresh()->pdf_archivo);
        $this->assertTrue(Storage::disk('local')->exists(RutasLegado::certificado($c->id)));
        $this->assertSame([], array_filter(Storage::disk('local')->allFiles('credential-flow/legado'), fn ($f) => str_ends_with($f, '.tmp')));
        $antes = hash_file('sha256', $this->rutaFisica($c->id));
        $mtime = filemtime($this->rutaFisica($c->id));

        // El reintento adopta el MISMO archivo (el render es determinista): no se reescribe.
        $a = $this->congelador()->servir($c->fresh());

        $this->assertTrue($a->recienGenerado);
        $this->assertSame($antes, $a->sha256);
        $this->assertSame($mtime, filemtime($this->rutaFisica($c->id)), 'No se tocó el archivo');
        $this->assertSame($a->sha256, $c->fresh()->pdf_hash);
    }

    public function test_un_archivo_distinto_en_la_ruta_sin_metadata_es_inconsistente_y_no_se_sobrescribe(): void
    {
        $c = $this->cert(1);
        Storage::disk('local')->put(RutasLegado::certificado($c->id), 'ARCHIVO AJENO');

        try {
            $this->congelador()->servir($c);
            $this->fail('Debía lanzar');
        } catch (PdfHistoricoInconsistente $e) {
            $this->assertSame('archivo_inesperado', $e->motivo);
        }
        $this->assertSame('ARCHIVO AJENO', Storage::disk('local')->get(RutasLegado::certificado($c->id)), 'NO se sobrescribe');
        $this->assertNull($c->fresh()->pdf_archivo);
    }

    public function test_no_se_deja_ningun_temporal_tras_generar(): void
    {
        $this->congelador()->servir($this->cert(1));
        $this->congelador()->servir($this->cert(3 + 0 === 3 ? 7 : 7));

        $this->assertSame([], array_filter(Storage::disk('local')->allFiles('credential-flow/legado'), fn ($f) => str_contains($f, '.tmp')));
    }

    public function test_una_segunda_solicitud_mientras_otra_congela_reutiliza_en_lugar_de_regenerar(): void
    {
        $c = $this->cert(1);
        // Simula al «segundo proceso»: cuando el primero ya tiene el bloqueo, el otro congela la fila antes de que el primero renderice.
        $generado = null;
        CongeladorLegado::$despuesDeBloquear = function () use ($c, &$generado) {
            CongeladorLegado::$despuesDeBloquear = null;
            // Dentro de la transacción exterior, otro intento ya la dejó congelada (la fila bloqueada se relee tras esperar).
            $generado = $this->congelador()->servir(CertificadoLegado::find($c->id));
        };

        $a = $this->congelador()->servir($c);

        $this->assertNotNull($generado);
        $this->assertSame($a->sha256, $generado->sha256);
        $this->assertCount(1, Storage::disk('local')->allFiles('credential-flow/legado/certificados'), 'Un solo PDF final');
        $this->assertSame($a->sha256, $c->fresh()->pdf_hash);
    }

    // ── Integridad ────────────────────────────────────────────────────────────

    /** @return array<string,array{0:callable,1:string}> */
    public static function alteraciones(): array
    {
        return [
            'mismos bytes, otro contenido' => [fn (string $ruta, string $contenido) => Storage::disk('local')->put($ruta, str_repeat('X', strlen($contenido))), 'sha256'],
            'más bytes' => [fn (string $ruta, string $contenido) => Storage::disk('local')->put($ruta, $contenido.'extra'), 'bytes'],
            'archivo borrado' => [fn (string $ruta) => Storage::disk('local')->delete($ruta), 'ausente'],
        ];
    }

    #[DataProvider('alteraciones')]
    public function test_un_pdf_alterado_no_se_regenera_ni_se_sobrescribe_y_lanza_el_error_controlado(callable $alterar, string $motivo): void
    {
        $c = $this->cert(1);
        $a = $this->congelador()->servir($c);
        $original = Storage::disk('local')->get($a->ruta);
        $alterar($a->ruta, $original);
        $alterado = Storage::disk('local')->exists($a->ruta) ? Storage::disk('local')->get($a->ruta) : null;
        Log::spy();

        try {
            $this->congelador()->servir($c->fresh());
            $this->fail('Debía lanzar PDF_HISTORICO_INCONSISTENTE');
        } catch (PdfHistoricoInconsistente $e) {
            $this->assertSame($motivo, $e->motivo);
            $this->assertStringStartsWith('PDF_HISTORICO_INCONSISTENTE', $e->getMessage());
        }

        // NO regenera: el archivo sigue exactamente como quedó y la metadata no cambió.
        $this->assertSame($alterado, Storage::disk('local')->exists($a->ruta) ? Storage::disk('local')->get($a->ruta) : null);
        $this->assertSame($a->sha256, $c->fresh()->pdf_hash);
        Log::shouldHaveReceived('error')->withArgs(fn ($m, $ctx) => str_contains($m, 'inconsistente') && array_keys($ctx) === ['certificado_id', 'motivo'] && $ctx['motivo'] === $motivo)->once();
    }

    public function test_una_ruta_distinta_a_la_esperada_en_la_base_de_datos_es_inconsistente(): void
    {
        $c = $this->cert(1);
        $this->congelador()->servir($c);
        DB::table('cf_certificados_legado')->where('id', $c->id)->update(['pdf_archivo' => '../../.env']);

        $this->expectException(PdfHistoricoInconsistente::class);
        $this->congelador()->servir($c->fresh());
    }

    // ── Rollback de la corrida ────────────────────────────────────────────────

    public function test_el_rollback_de_la_corrida_se_bloquea_si_el_congelador_real_dejo_un_pdf(): void
    {
        $this->congelador()->servir($this->cert(1));
        $corrida = DB::table('cf_migraciones_corridas')->value('id');
        $conteos = fn () => [DB::table('cf_certificados_legado')->count(), DB::table('cf_eventos')->count()];
        $antes = $conteos();

        try {
            (new RollbackCorrida)->revertir($corrida);
            $this->fail('Debía negarse');
        } catch (RollbackNoPermitido $e) {
            $this->assertSame(RollbackNoPermitido::PDF_CONGELADO, $e->codigo);
        }
        $this->assertSame($antes, $conteos());
        $this->assertSame('completada', DB::table('cf_migraciones_corridas')->where('id', $corrida)->value('estado'));
    }

    // ── Ruta administrativa de prueba ─────────────────────────────────────────

    private function usarResolutorLocal(): void
    {
        $dir = $this->dirImagenes;
        $this->app->bind(ResolutorPlantillaLegado::class, fn () => new ResolutorPlantillaDirectorio($dir));
    }

    public function test_la_ruta_admin_sirve_el_pdf_privado_y_lo_congela_una_sola_vez(): void
    {
        $this->usarResolutorLocal();
        $url = route('credential-flow.historico.certificados.pdf', $this->idCert(1));

        $r1 = $this->actingAs($this->admin())->get($url);
        $r2 = $this->actingAs($this->admin())->get($url);

        $r1->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('no-store', (string) $r1->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $r1->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $r1->headers->get('X-Content-Type-Options'));
        $this->assertSame($r1->headers->get('X-PDF-SHA256'), $r2->headers->get('X-PDF-SHA256'));
        $this->assertStringStartsWith('%PDF-', file_get_contents($r1->baseResponse->getFile()->getPathname()));
        $this->assertCount(1, Storage::disk('local')->allFiles('credential-flow/legado/certificados'));
    }

    public function test_la_ruta_admin_exige_sesion_y_rol_y_explica_los_no_elegibles_sin_datos_personales(): void
    {
        $this->usarResolutorLocal();
        $url = route('credential-flow.historico.certificados.pdf', $this->idCert(4));

        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($this->comercial())->get($url)->assertForbidden();
        $r = $this->actingAs($this->admin())->get($url);

        $r->assertStatus(409);
        $this->assertSame('NO_ELEGIBLE: REVISION_DOCUMENTO', $r->getContent());
        $this->assertStringNotContainsString('ABC123', $r->getContent());
        $this->assertSame([], Storage::disk('local')->allFiles('credential-flow/legado'));
        $this->actingAs($this->admin())->get(route('credential-flow.historico.certificados.pdf', 999999))->assertNotFound();
    }

    public function test_la_ruta_admin_responde_500_controlado_ante_un_pdf_inconsistente(): void
    {
        $this->usarResolutorLocal();
        $id = $this->idCert(1);
        $url = route('credential-flow.historico.certificados.pdf', $id);
        $this->actingAs($this->admin())->get($url)->assertOk();
        Storage::disk('local')->put(RutasLegado::certificado($id), 'alterado');

        $r = $this->actingAs($this->admin())->get($url);

        $r->assertStatus(500);
        $this->assertStringStartsWith('PDF_HISTORICO_INCONSISTENTE', $r->getContent());
    }

    public function test_el_historico_de_lectura_sigue_sin_botones_de_pdf_ni_enlaces_a_la_ruta(): void
    {
        foreach (['Pages/CredentialFlow/Historico/Certificado.vue', 'Pages/CredentialFlow/Historico/Evento.vue', 'Components/CredentialFlow/Historico/TablaCertificados.vue'] as $vue) {
            $fuente = file_get_contents(resource_path('js/'.$vue));
            $this->assertStringNotContainsString('certificados.pdf', $fuente, "$vue no debe enlazar al PDF");
            $this->assertDoesNotMatchRegularExpression('/<(button|a)[^>]*>[^<]*(Descargar|Generar PDF)/i', $fuente);
        }
    }

    public function test_la_migracion_no_se_ve_afectada_y_los_certificados_sin_pdf_siguen_sin_pdf(): void
    {
        $this->congelador()->servir($this->cert(1));

        $this->assertSame(1, DB::table('cf_certificados_legado')->whereNotNull('pdf_archivo')->count());
        $this->assertSame(15, DB::table('cf_certificados_legado')->count());
        $this->assertSame('completada', (new MigradorHistorico)->ejecutar()->noOp ? 'completada' : 'otra');
    }
}
