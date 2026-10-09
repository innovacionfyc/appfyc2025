<?php

namespace Tests\Feature\CredentialFlow\Conciliaciones;

use App\Http\Requests\CredentialFlow\UpdateDisenoRequest;
use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Conciliaciones\ResolucionNoPermitida;
use App\Support\CredentialFlow\DisenoSchema;
use App\Support\CredentialFlow\Reemplazo\CreadorPlantillaReemplazo;
use App\Support\CredentialFlow\Reemplazo\DisenoReemplazo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Tests\Feature\CredentialFlow\Support\PngSintetico;

/** Clon moderno de una plantilla histórica (Fase 10B-2B-2A): una imagen histórica → un clon reutilizable, sin tocar la imagen histórica. */
class CreadorPlantillaReemplazoTest extends ReemplazoTestCase
{
    private function catalogo(): string
    {
        return md5(json_encode([DB::table('cf_plantillas_legado')->orderBy('id')->get()->all(), DB::table('cf_plantillas_legado_contenidos')->orderBy('id')->get()->all()]));
    }

    public function test_crea_el_clon_con_el_sha_historico_el_pdf_integro_y_el_diseno_inicial(): void
    {
        $cert = CertificadoLegado::findOrFail($this->idCert(120));
        $sha = (string) DB::table('cf_plantillas_legado_contenidos')->where('id', DB::table('cf_plantillas_legado')->where('id', $cert->plantilla_legado_id)->value('contenido_id'))->value('sha256');

        $r = $this->creadorClon()->paraCertificado($cert);
        $p = $r['plantilla'];

        $this->assertTrue($r['creada']);
        $this->assertSame($sha, $p->origen_legado_sha256);
        $this->assertSame($p->rutaPdfEsperada(), $p->archivo_pdf);
        $this->assertSame($p->hash_sha256, hash('sha256', Storage::disk('local')->get($p->archivo_pdf)));
        $this->assertSame(3, (int) $p->schema_version);
        $this->assertStringNotContainsString('PERSONA', $p->nombre.$p->descripcion);

        $d = $p->diseno;
        $campos = collect($d['elements'])->where('type', 'text')->pluck('field')->all();
        $this->assertSame(['nombre_completo', 'documento'], $campos, 'solo lo necesario: sin fecha ni intensidad');
        $this->assertSame(1, DisenoSchema::contarQr($d['elements']));
        $this->assertSame(DisenoReemplazo::PREFIJO_DOCUMENTO, collect($d['elements'])->firstWhere('field', 'documento')['prefix']);
    }

    public function test_el_diseno_inicial_es_valido_para_el_editor_y_queda_dentro_de_la_pagina(): void
    {
        foreach ([[792.0, 612.0], [792.0, 560.0], [792.0, 792.0], [612.0, 792.0]] as [$w, $h]) {
            $d = DisenoReemplazo::inicial($w, $h);

            $v = Validator::make(['diseno' => $d], (new UpdateDisenoRequest)->rules());
            $this->assertTrue($v->passes(), json_encode($v->errors()->all()));
            foreach ($d['elements'] as $e) {
                $this->assertLessThanOrEqual($w + 0.5, $e['x'] + $e['width']);
                $this->assertLessThanOrEqual($h + 0.5, $e['y'] + $e['height']);
                $this->assertGreaterThanOrEqual(0, $e['x']);
                $this->assertGreaterThanOrEqual(0, $e['y']);
            }
            $qr = collect($d['elements'])->firstWhere('type', 'qr');
            $this->assertGreaterThanOrEqual(DisenoSchema::QR_MIN_PT, $qr['width']);
            $this->assertSame($qr['width'], $qr['height']);
            $this->assertSame(3, DisenoSchema::versionPara($d));
        }
    }

    public function test_una_imagen_historica_tiene_un_unico_clon_reutilizable_y_el_catalogo_no_cambia(): void
    {
        $catalogo = $this->catalogo();
        $imagen = md5_file($this->dirImagenes.'/ALFA 2024.png');

        $a = $this->creadorClon()->paraCertificado(CertificadoLegado::findOrFail($this->idCert(120)));
        $b = $this->creadorClon()->paraCertificado(CertificadoLegado::findOrFail($this->idCert(121)));   // otro certificado del mismo evento/imagen
        $c = $this->creadorClon()->paraCertificado(CertificadoLegado::findOrFail($this->idCert(120)));

        $this->assertSame([true, false, false], [$a['creada'], $b['creada'], $c['creada']]);
        $this->assertSame([$a['plantilla']->id, $a['plantilla']->id], [$b['plantilla']->id, $c['plantilla']->id]);
        $this->assertSame(1, Plantilla::count());
        $this->assertSame($catalogo, $this->catalogo(), 'la plantilla histórica no se altera');
        $this->assertSame($imagen, md5_file($this->dirImagenes.'/ALFA 2024.png'), 'la imagen histórica tampoco');
    }

    public function test_rechaza_imagenes_no_renderizables_alteradas_o_clones_eliminados_sin_crear_nada(): void
    {
        // Una plantilla histórica que no está «ok» (imagen faltante, extensión inválida…).
        $plantillaHistorica = (int) DB::table('cf_certificados_legado')->where('id', $this->idCert(120))->value('plantilla_legado_id');
        DB::table('cf_plantillas_legado')->where('id', $plantillaHistorica)->update(['estado' => 'faltante']);
        $this->expectsNegado(fn () => $this->creadorClon()->paraCertificado(CertificadoLegado::findOrFail($this->idCert(120))));
        DB::table('cf_plantillas_legado')->where('id', $plantillaHistorica)->update(['estado' => 'ok']);

        // Sin plantilla histórica asociada.
        DB::table('cf_certificados_legado')->where('id', $this->idCert(121))->update(['plantilla_legado_id' => null]);
        $this->expectsNegado(fn () => $this->creadorClon()->paraCertificado(CertificadoLegado::findOrFail($this->idCert(121))));

        // Imagen alterada: ya no coincide con el SHA del catálogo.
        file_put_contents($this->dirImagenes.'/ALFA 2024.png', 'x', FILE_APPEND);
        $this->expectsNegado(fn () => $this->creadorClon()->paraCertificado(CertificadoLegado::findOrFail($this->idCert(120))));
        $this->assertSame(0, Plantilla::withTrashed()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    /** Sustituye la imagen histórica del evento 1 por `$bytes` y deja el catálogo coherente (SHA, bytes y dimensiones). */
    private function reemplazarImagenHistorica(string $bytes, int $ancho, int $alto): void
    {
        file_put_contents($this->dirImagenes.'/ALFA 2024.png', $bytes);
        $contenido = DB::table('cf_plantillas_legado')->where('id', DB::table('cf_certificados_legado')->where('id', $this->idCert(120))->value('plantilla_legado_id'))->value('contenido_id');
        DB::table('cf_plantillas_legado_contenidos')->where('id', $contenido)->update(['sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes), 'ancho_px' => $ancho, 'alto_px' => $alto]);
    }

    public function test_una_imagen_historica_de_337_megapixeles_se_clona_sin_decodificar_y_con_el_derivado_trazado(): void
    {
        $png = PngSintetico::crear(6600, 5100);
        $this->reemplazarImagenHistorica($png, 6600, 5100);
        memory_reset_peak_usage();
        $antes = memory_get_usage();

        $r = $this->creadorClon()->paraCertificado(CertificadoLegado::findOrFail($this->idCert(120)));

        $this->assertLessThan(96 * 1048576, memory_get_peak_usage() - $antes);
        $p = $r['plantilla'];
        $pdf = Storage::disk('local')->get($p->archivo_pdf);
        $this->assertTrue($r['creada']);
        $this->assertSame(hash('sha256', $png), $p->origen_legado_sha256);
        $this->assertSame(PngSintetico::datosIdat($png), PngSintetico::flujoDeImagenPdf($pdf), 'la imagen original va dentro del PDF sin pérdida');
        $this->assertSame($p->hash_sha256, hash('sha256', $pdf));
        // El derivado queda trazado respecto de la evidencia original (que no se toca).
        $m = $p->fresh()->origen_legado_meta;
        $this->assertSame([CreadorPlantillaReemplazo::VERSION_PROCESO, 'incrustacion_directa', 'image/png', hash('sha256', $png), hash('sha256', $pdf)], [$m['version_proceso'], $m['algoritmo'], $m['mime'], $m['sha256_origen'], $m['sha256_derivado']]);
        $this->assertSame([6600, 5100, 6600, 5100, strlen($png), strlen($pdf), []], [$m['ancho_px_origen'], $m['alto_px_origen'], $m['ancho_px_derivado'], $m['alto_px_derivado'], $m['bytes_origen'], $m['bytes_derivado'], $m['advertencias']]);
        $this->assertNotNull($m['generado_at']);
        $this->assertSame(hash('sha256', $png), hash_file('sha256', $this->dirImagenes.'/ALFA 2024.png'), 'la imagen histórica original no se modifica');
    }

    public function test_el_reemplazo_completo_con_una_plantilla_grande_cabe_en_poca_memoria_y_advierte_si_es_enorme(): void
    {
        $this->reemplazarImagenHistorica(PngSintetico::crear(6600, 5100), 6600, 5100);
        $clon = $this->clon(120);
        memory_reset_peak_usage();
        $antes = memory_get_usage();

        $r = $this->reemplazar(120, $clon->id);

        $this->assertLessThan(96 * 1048576, memory_get_peak_usage() - $antes, 'preview + render + emisión de un clon de 33,7 MP');
        $this->assertSame(1, DB::table('cf_emisiones')->count());
        $this->assertSame([], $this->servicio()->evaluar($this->casoDe(121), $this->solicitud(121, $clon->id))['plantilla']['advertencias']);
        $this->artisan('credential-flow:verificar-emisiones')->assertExitCode(0);
        $this->assertNotNull($r['emision_id']);

        // Una imagen de 134 MP también se clona, con la advertencia visible para el administrador.
        $gigante = PngSintetico::crear(13200, 10200);
        $this->reemplazarImagenHistorica($gigante, 13200, 10200);
        Plantilla::query()->update(['origen_legado_sha256' => null]);   // para poder clonar la «nueva» imagen
        $g = $this->clon(121);
        $this->assertSame(['IMAGEN_MUY_GRANDE'], $g->fresh()->origen_legado_meta['advertencias']);
        $this->assertSame(['IMAGEN_MUY_GRANDE'], $this->servicio()->evaluar($this->casoDe(122), $this->solicitud(122, $g->id))['plantilla']['advertencias']);
    }

    public function test_si_la_imagen_no_se_puede_incrustar_ni_cabe_en_memoria_falla_de_forma_controlada_sin_dejar_nada(): void
    {
        $alfa = PngSintetico::crear(6600, 5100, 6);   // RGBA enorme: no incrustable, ~350 MB para rasterizar
        $this->reemplazarImagenHistorica($alfa, 6600, 5100);
        $limite = ini_get('memory_limit');
        ini_set('memory_limit', (string) (memory_get_usage(true) + 96 * 1048576));
        $archivos = collect(Storage::disk('local')->allFiles())->sort()->values()->all();

        try {
            $this->creadorClon()->paraCertificado(CertificadoLegado::findOrFail($this->idCert(120)));
            $this->fail('Debió negarse');
        } catch (ResolucionNoPermitida $e) {
            $this->assertSame(ResolucionNoPermitida::PLANTILLA_HISTORICA_EXCEDE_CAPACIDAD, $e->codigo);
            $this->assertStringContainsString('más memoria', $e->getMessage());
        } finally {
            ini_set('memory_limit', (string) $limite);
        }

        $this->assertSame(0, Plantilla::withTrashed()->count());
        $this->assertSame($archivos, collect(Storage::disk('local')->allFiles())->sort()->values()->all(), 'ni PDF parcial ni carpeta de plantilla');
        $this->assertSame([], glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'cfimg*') ?: [], 'ni temporales de imagen');
    }

    public function test_una_imagen_historica_corrupta_pero_con_el_sha_del_catalogo_se_rechaza_sin_crear_nada(): void
    {
        $png = PngSintetico::crear(3300, 2550);
        $roto = substr($png, 0, -200);   // truncada: el catálogo registra SU sha, así que la comprobación de SHA pasa y debe cazarla el validador
        $this->reemplazarImagenHistorica($roto, 3300, 2550);

        try {
            $this->creadorClon()->paraCertificado(CertificadoLegado::findOrFail($this->idCert(120)));
            $this->fail('Debió negarse');
        } catch (ResolucionNoPermitida $e) {
            $this->assertSame(ResolucionNoPermitida::PLANTILLA_INVALIDA, $e->codigo);
        }
        $this->assertSame(0, Plantilla::withTrashed()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_un_clon_eliminado_no_se_resucita_ni_se_duplica(): void
    {
        $p = $this->clon(120);
        $p->delete();

        $this->expectsNegado(fn () => $this->creadorClon()->paraCertificado(CertificadoLegado::findOrFail($this->idCert(121))));
        $this->assertSame(1, Plantilla::withTrashed()->count());
    }

    public function test_el_clon_sirve_de_extremo_a_extremo_para_reemplazar_dos_historicos_en_un_solo_lote(): void
    {
        $clon = $this->clon(120);

        $r1 = $this->reemplazar(120, $clon->id);
        $r2 = $this->reemplazar(121, $this->clon(121)->id);

        $this->assertSame($r1['lote_id'], $r2['lote_id']);
        $this->assertSame($clon->id, $r1['plantilla_id']);
        $evi = json_decode(DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $this->casoDe(120))->orderByDesc('id')->value('evidencia'), true);
        $this->assertSame('clon_historica', $evi['plantilla_origen']);
        $this->assertSame(1, Plantilla::count());
        $this->artisan('credential-flow:verificar-emisiones')->assertExitCode(0);
    }

    public function test_un_prefijo_de_tipo_de_documento_distinto_bloquea_el_uso_del_clon(): void
    {
        $clon = $this->clon(120);
        DB::table('cf_certificados_legado')->where('id', $this->idCert(120))->update(['tipo_documento' => 'CE']);

        $this->expectsNegado(fn () => $this->reemplazar(120, $clon->id));
    }

    private function expectsNegado(callable $f): void
    {
        try {
            $f();
        } catch (ResolucionNoPermitida $e) {
            $this->assertSame(ResolucionNoPermitida::PLANTILLA_INVALIDA, $e->codigo, $e->getMessage());

            return;
        }
        $this->fail('Debió negarse con PLANTILLA_INVALIDA');
    }
}
