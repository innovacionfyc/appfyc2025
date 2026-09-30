<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Emisiones\AlmacenEmisiones;
use App\Support\CredentialFlow\Emisiones\CodigoEmision;
use App\Support\CredentialFlow\Emisiones\EmisionException;
use App\Support\CredentialFlow\Emisiones\EmisorCredencial;
use App\Support\CredentialFlow\Emisiones\EspacioDisco;
use App\Support\CredentialFlow\Emisiones\SnapshotCredencial;
use App\Support\CredentialFlow\FuentesCredential;
use App\Support\CredentialFlow\Generacion\GeneradorCredencialPdf;
use App\Support\CredentialFlow\Participantes\DatosDeParticipante;
use Composer\InstalledVersions;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\Feature\CredentialFlow\Support\InspectorPdf;
use Tests\Feature\CredentialFlow\Support\PdfBase;

/** F&C Credential Flow · Fase 7: emisión individual, snapshot, integridad, revocación, reemisión y bloqueos. */
class EmisionesTest extends EmisionesTestCase
{
    // ── Esquema, modelo e inmutabilidad ───────────────────────────────────────

    public function test_esquema_de_cf_emisiones(): void
    {
        $esperadas = ['id', 'codigo', 'participante_id', 'lote_id', 'plantilla_id', 'version', 'reemplaza_id', 'estado', 'participante_vigente',
            'datos_snapshot', 'diseno_snapshot', 'schema_version', 'plantilla_pdf_hash', 'generador_snapshot', 'pdf_archivo', 'pdf_hash', 'pdf_bytes',
            'emitido_at', 'emitido_por', 'revocado_at', 'revocado_por', 'motivo_revocacion', 'operacion', 'created_at', 'updated_at'];

        $this->assertSame([], array_diff($esperadas, Schema::getColumnListing('cf_emisiones')));
        $this->assertSame([], array_diff(Schema::getColumnListing('cf_emisiones'), $esperadas), 'No debe haber columnas de más (ni deleted_at)');
    }

    private function emisionDirecta(Participante $p, array $cambios = []): Emision
    {
        return Emision::create(array_merge([
            'codigo' => CodigoEmision::generar(), 'participante_id' => $p->id, 'lote_id' => $p->lote_id, 'plantilla_id' => $p->lote->plantilla_id,
            'version' => 1, 'estado' => 'emitida', 'participante_vigente' => $p->id, 'datos_snapshot' => ['x' => 1], 'diseno_snapshot' => ['x' => 1],
            'schema_version' => 1, 'plantilla_pdf_hash' => str_repeat('a', 64), 'generador_snapshot' => ['x' => 1],
            'pdf_archivo' => 'x', 'pdf_hash' => str_repeat('b', 64), 'pdf_bytes' => 1, 'emitido_at' => now(),
        ], $cambios));
    }

    public function test_relaciones_y_unicidad_de_codigo_y_de_vigente(): void
    {
        $lote = $this->loteCon(2);
        [$a, $b] = $lote->participantes()->orderBy('id')->get()->all();
        $e = $this->emisionDirecta($a);

        $this->assertTrue($e->participante->is($a));
        $this->assertTrue($e->lote->is($lote));
        $this->assertTrue($e->plantilla->is($lote->plantilla));
        $this->assertTrue($a->emisionVigente->is($e));
        $this->assertSame(1, $lote->emisiones()->count());

        // participante_vigente UNIQUE: no puede haber dos vigentes del mismo participante
        try {
            $this->emisionDirecta($a, ['version' => 2]);
            $this->fail('Debe fallar el índice único participante_vigente');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
        // varias con NULL sí (revocadas)
        $this->emisionDirecta($b, ['participante_vigente' => null, 'estado' => 'revocada', 'revocado_at' => now()]);
        $this->emisionDirecta($b, ['participante_vigente' => null, 'estado' => 'revocada', 'revocado_at' => now(), 'version' => 2]);
        $this->assertSame(2, $b->emisiones()->count());

        // codigo UNIQUE
        $this->expectException(QueryException::class);
        $this->emisionDirecta($b, ['codigo' => $e->codigo, 'participante_vigente' => null, 'version' => 3]);
    }

    public function test_reemplaza_y_reemisiones_como_relaciones(): void
    {
        $lote = $this->loteCon(1);
        $p = $lote->participantes()->first();
        $v1 = $this->emisionDirecta($p, ['participante_vigente' => null, 'estado' => 'revocada']);
        $v2 = $this->emisionDirecta($p, ['version' => 2, 'reemplaza_id' => $v1->id]);

        $this->assertTrue($v2->reemplaza->is($v1));
        $this->assertTrue($v1->reemisiones->first()->is($v2));
    }

    public function test_una_emision_es_inmutable_salvo_lo_necesario_para_revocar(): void
    {
        $p = $this->loteCon(1)->participantes()->first();
        $e = $this->emisionDirecta($p);

        foreach (['datos_snapshot' => ['a' => 1], 'diseno_snapshot' => ['a' => 1], 'pdf_hash' => str_repeat('c', 64), 'pdf_archivo' => 'otro', 'version' => 9, 'codigo' => 'AAAAAAAAAAAAAAAAAAAA', 'lote_id' => 999, 'schema_version' => 5] as $campo => $valor) {
            try {
                $e->fresh()->update([$campo => $valor]);
                $this->fail("No debería poder cambiarse $campo");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        // lo necesario para revocar sí
        $e->fresh()->update(['estado' => 'revocada', 'participante_vigente' => null, 'revocado_at' => now(), 'revocado_por' => 1, 'motivo_revocacion' => 'motivo de prueba']);
        $this->assertSame('revocada', $e->fresh()->estado);

        // y no se puede borrar (ni con soft delete: no existe)
        $this->expectException(LogicException::class);
        $e->fresh()->delete();
    }

    public function test_no_hay_rutas_genericas_de_update_o_delete_de_emisiones(): void
    {
        foreach (app('router')->getRoutes()->getRoutes() as $ruta) {
            if (str_contains($ruta->uri(), 'emisiones/{emision}')) {
                $this->assertNotContains('PUT', $ruta->methods());
                $this->assertNotContains('PATCH', $ruta->methods());
                $this->assertNotContains('DELETE', $ruta->methods());
            }
        }
        $this->addToAssertionCount(1);
    }

    // ── Código público ────────────────────────────────────────────────────────

    public function test_codigo_de_emision_formato_y_aleatoriedad(): void
    {
        $vistos = [];
        for ($i = 0; $i < 2000; $i++) {
            $c = CodigoEmision::generar();
            $this->assertSame(20, strlen($c));
            $this->assertMatchesRegularExpression('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{20}$/', $c);
            $this->assertTrue(CodigoEmision::valido($c));
            $vistos[$c] = true;
        }
        $this->assertCount(2000, $vistos, 'Sin colisiones en 2000 códigos');
        $this->assertFalse(CodigoEmision::valido('I'.substr($c, 1)), 'I, L, O y U no existen en Crockford Base32');
        $this->assertFalse(CodigoEmision::valido(substr($c, 0, 19)));

        // Los caracteres se reparten por todo el alfabeto (no un patrón fijo)
        $this->assertGreaterThan(28, count(array_unique(str_split(implode('', array_keys($vistos))))));
    }

    public function test_el_codigo_se_reintenta_ante_una_colision(): void
    {
        $p = $this->loteCon(1)->participantes()->first();
        $existente = $this->emisionDirecta($p, ['participante_vigente' => null, 'estado' => 'revocada']);
        $secuencia = [$existente->codigo, $existente->codigo, 'ZZZZZZZZZZZZZZZZZZZZ'];
        CodigoEmision::$fuente = function () use (&$secuencia) {
            return array_shift($secuencia);
        };

        $this->assertSame('ZZZZZZZZZZZZZZZZZZZZ', CodigoEmision::unico());
    }

    public function test_el_codigo_no_se_deriva_del_id_ni_se_imprime_en_el_pdf(): void
    {
        $lote = $this->loteCon(1);
        $p = $lote->participantes()->first();

        $r = $this->emitirPor($lote, $p)->assertCreated();
        $e = Emision::findOrFail($r->json('emision.id'));

        $this->assertNotSame(strtoupper((string) $e->id), $e->codigo);
        $textos = array_column((new InspectorPdf(AlmacenEmisiones::leerVerificado($e)))->textos(), 'texto');
        foreach ($textos as $t) {
            $this->assertStringNotContainsString($e->codigo, $t);
        }
    }

    // ── Snapshot ──────────────────────────────────────────────────────────────

    public function test_snapshot_congela_datos_diseno_schema_hash_y_generador(): void
    {
        $lote = $this->loteCon(1);
        $p = $lote->participantes()->first();
        $plantilla = $lote->plantilla;
        [, $hash] = SnapshotCredencial::cargarPlantilla($plantilla);

        $s = SnapshotCredencial::capturar($p, $lote, $plantilla, $hash);

        $this->assertSame('C.C. 20.000.001', $s->datos['documento']);
        $this->assertSame('CONGRESO DE FINANZAS', $s->datos['evento']);
        $this->assertSame('17 de septiembre de 2026', $s->datos['fecha']);
        $this->assertSame('30 horas', $s->datos['intensidad_horaria']);
        $this->assertSame('PERSONA NÚMERO 1', $s->datos['nombre_completo']);
        $this->assertSame($lote->nombre, $s->datos['lote_nombre']);
        $this->assertSame($plantilla->nombre, $s->datos['plantilla_nombre']);
        $this->assertSame($plantilla->diseno, $s->diseno);
        $this->assertSame(1, $s->schemaVersion);
        $this->assertSame($plantilla->hash_sha256, $s->plantillaPdfHash);
        $this->assertTrue(CodigoEmision::valido($s->codigo));
        $this->assertSame(64, strlen($s->huella));

        $g = $s->generador;
        $this->assertSame(GeneradorCredencialPdf::GENERADOR_VERSION, $g['generador_version']);
        $this->assertSame(2, GeneradorCredencialPdf::GENERADOR_VERSION);
        $this->assertArrayNotHasKey('qr', $g, 'Sin QR en el diseño no hay bloque qr');
        $this->assertSame(InstalledVersions::getPrettyVersion('tecnickcom/tcpdf'), $g['tcpdf']);
        $this->assertSame(InstalledVersions::getPrettyVersion('setasign/fpdi'), $g['fpdi']);
        $this->assertSame(hash_file('sha256', FuentesCredential::rutaMetricas()), $g['metricas_sha256']);
        // hashes de los TTF realmente usados (Outfit 700), tomados de metricas.json y coincidentes con el archivo real
        $this->assertSame(['outfit' => ['700' => FuentesCredential::metricas()['fuentes']['outfit']['700']['sha256']]], $g['fuentes']);
        $this->assertSame(hash_file('sha256', FuentesCredential::rutaArchivo('outfit', 700)), $g['fuentes']['outfit']['700']);
    }

    public function test_la_huella_cambia_con_cualquier_entrada_relevante(): void
    {
        $lote = $this->loteCon(1);
        $p = $lote->participantes()->first();
        $pl = $lote->plantilla;
        $base = SnapshotCredencial::huella($p, $lote, $pl);

        $this->assertSame($base, SnapshotCredencial::huella($p->fresh(), $lote->fresh(), $pl->fresh()));

        $p2 = $p->replicate();
        $p2->id = $p->id;
        $p2->nombre_completo = 'OTRO NOMBRE';
        $this->assertNotSame($base, SnapshotCredencial::huella($p2, $lote, $pl));

        $l2 = $lote->replicate();
        $l2->id = $lote->id;
        $l2->datos_comunes = ['evento' => 'OTRO', 'fecha' => 'x', 'intensidad_horaria' => 'y'];
        $this->assertNotSame($base, SnapshotCredencial::huella($p, $l2, $pl));

        $pl2 = $pl->replicate();
        $pl2->id = $pl->id;
        $d = $pl->diseno;
        $d['elements'][0]['x'] = 97;
        $pl2->diseno = $d;
        $this->assertNotSame($base, SnapshotCredencial::huella($p, $lote, $pl2));

        $pl3 = $pl->replicate();
        $pl3->id = $pl->id;
        $pl3->hash_sha256 = str_repeat('f', 64);
        $this->assertNotSame($base, SnapshotCredencial::huella($p, $lote, $pl3));
    }

    public function test_el_pdf_base_alterado_impide_emitir(): void
    {
        $lote = $this->loteCon(1);
        Storage::disk('local')->put($lote->plantilla->rutaPdfEsperada(), PdfBase::crear(792, 612, 90));

        $this->emitirPor($lote, $lote->participantes()->first())->assertStatus(422)->assertJsonPath('error.code', 'PLANTILLA_ALTERADA');
        $this->assertSame(0, Emision::count());
    }

    public function test_regresion_del_refactor_generar_y_generar_desde_producen_la_misma_geometria(): void
    {
        $lote = $this->loteCon(1);
        $p = $lote->participantes()->first();
        $plantilla = $lote->plantilla;
        $datos = DatosDeParticipante::para($p, $lote);

        $vivo = new InspectorPdf(GeneradorCredencialPdf::generar($plantilla, $datos));
        $desdeSnapshot = new InspectorPdf(GeneradorCredencialPdf::generarDesde($plantilla->diseno, Storage::disk('local')->get($plantilla->rutaPdfEsperada()), $datos, 1));

        $this->assertSame($vivo->textos(), $desdeSnapshot->textos());
        $this->assertSame($vivo->mediaBox(), $desdeSnapshot->mediaBox());
        $this->assertCount(5, $vivo->textos());
    }

    // ── Emisión individual ────────────────────────────────────────────────────

    public function test_emision_individual_valida(): void
    {
        $lote = $this->loteCon(1);
        $p = $lote->participantes()->first();
        $antes = Movimiento::count();

        $r = $this->emitirPor($lote, $p);

        $r->assertCreated()->assertJsonPath('emision.version', 1)->assertJsonPath('emision.estado', 'emitida');
        $e = Emision::findOrFail($r->json('emision.id'));

        $this->assertSame($p->id, $e->participante_vigente);
        $this->assertSame($lote->id, $e->lote_id);
        $this->assertSame($lote->plantilla_id, $e->plantilla_id);
        $this->assertSame($this->admin()->id, $e->emitido_por);
        $this->assertNotNull($e->emitido_at);
        $this->assertNull($e->reemplaza_id);
        $this->assertNull($e->operacion);
        $this->assertSame(1, $e->schema_version);

        // archivo: ruta privada aleatoria, bytes y SHA-256 coinciden
        $this->assertMatchesRegularExpression('#^credential-flow/emisiones/[0-9a-f]{2}/[0-9a-f-]{36}\.pdf$#', $e->pdf_archivo);
        $this->assertStringNotContainsStringIgnoringCase('persona', $e->pdf_archivo);
        $this->assertStringNotContainsString('20000001', str_replace('.', '', $e->pdf_archivo));
        $bytes = Storage::disk('local')->get($e->pdf_archivo);
        $this->assertSame(strlen($bytes), $e->pdf_bytes);
        $this->assertSame(hash('sha256', $bytes), $e->pdf_hash);
        $this->assertSame([], Storage::disk('public')->allFiles());

        // contenido: los cinco valores del snapshot
        $this->assertSame(
            ['PERSONA NÚMERO 1', 'C.C. 20.000.001', 'CONGRESO DE FINANZAS', '17 de septiembre de 2026', '30 horas'],
            array_column((new InspectorPdf($bytes))->textos(), 'texto')
        );

        // Movimiento sin datos personales
        $this->assertSame($antes + 1, Movimiento::count());
        $m = Movimiento::latest('id')->first();
        $this->assertSame('credential-flow', $m->modulo);
        $this->assertSame($e->id, $m->metadata['emision_id']);
        $this->assertSame($lote->id, $m->metadata['lote_id']);
        $this->assertSame($p->id, $m->metadata['participante_id']);
        $this->assertSame(1, $m->metadata['version']);
        $this->assertStringNotContainsString('PERSONA', json_encode($m->toArray()));
        $this->assertStringNotContainsString('20.000.001', json_encode($m->toArray()));
    }

    public function test_doble_emision_es_un_409_y_no_deja_archivos_extra(): void
    {
        $lote = $this->loteCon(1);
        $p = $lote->participantes()->first();

        $this->emitirPor($lote, $p)->assertCreated();
        $archivos = $this->archivosDeEmisiones();

        $this->emitirPor($lote, $p)->assertStatus(409)->assertJsonPath('error.code', 'EMISION_YA_VIGENTE');

        $this->assertSame(1, Emision::count());
        $this->assertSame($archivos, $this->archivosDeEmisiones());
    }

    public function test_el_indice_unico_es_la_barrera_final_ante_dos_emisiones_simultaneas(): void
    {
        $lote = $this->loteCon(1);
        $p = $lote->participantes()->first();
        $existente = null;

        // Simula la carrera: otra petición inserta su emisión vigente justo después de la comprobación del servicio.
        Emision::creating(function (Emision $nueva) use ($p, &$existente) {
            if ($existente === null) {
                $existente = true;
                DB::table('cf_emisiones')->insert([
                    'codigo' => CodigoEmision::generar(), 'participante_id' => $p->id, 'lote_id' => $p->lote_id, 'plantilla_id' => $p->lote->plantilla_id,
                    'version' => 1, 'estado' => 'emitida', 'participante_vigente' => $p->id, 'datos_snapshot' => '{}', 'diseno_snapshot' => '{}',
                    'schema_version' => 1, 'plantilla_pdf_hash' => str_repeat('a', 64), 'generador_snapshot' => '{}', 'pdf_archivo' => 'x',
                    'pdf_hash' => str_repeat('b', 64), 'pdf_bytes' => 1, 'emitido_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $this->emitirPor($lote, $p)->assertStatus(409)->assertJsonPath('error.code', 'EMISION_YA_VIGENTE');

        // La fila «rival» se insertó dentro de la misma transacción de prueba, así que el rollback la deshace también:
        // lo que importa es que el índice único cortó la inserción y que el PDF de la perdedora se limpió.
        $this->assertSame(0, Emision::count());
        $this->assertSame([], $this->archivosDeEmisiones(), 'El PDF de la emisión perdedora se limpió');
    }

    public function test_datos_modificados_durante_la_emision_se_detectan(): void
    {
        $lote = $this->loteCon(1);
        $p = $lote->participantes()->first();
        $emisor = app(EmisorCredencial::class);

        // El primer Participante recuperado es el del bloqueo dentro de la transacción: otro usuario lo edita justo entonces.
        Participante::retrieved(function (Participante $m) {
            static $hecho = false;
            if (! $hecho) {
                $hecho = true;
                DB::table('cf_participantes')->where('id', $m->id)->update(['nombre_completo' => 'NOMBRE CORREGIDO']);
                $m->nombre_completo = 'NOMBRE CORREGIDO';
            }
        });

        try {
            $emisor->emitir($p, $lote, $this->admin()->id);
            $this->fail('Debe detectar el cambio');
        } catch (EmisionException $e) {
            $this->assertSame('DATOS_CAMBIARON_DURANTE_EMISION', $e->codigo);
            $this->assertSame(409, $e->estadoHttp);
        }

        $this->assertSame(0, Emision::count());
        $this->assertSame([], $this->archivosDeEmisiones());
    }

    public function test_diseno_o_lote_modificados_durante_la_emision_se_detectan(): void
    {
        $lote = $this->loteCon(1);
        $p = $lote->participantes()->first();
        $emisor = app(EmisorCredencial::class);

        Lote::retrieved(function (Lote $m) {
            static $hecho = false;
            if (! $hecho) {
                $hecho = true;
                $comunes = ['evento' => 'EVENTO CAMBIADO', 'fecha' => 'x', 'intensidad_horaria' => 'y'];
                DB::table('cf_lotes')->where('id', $m->id)->update(['datos_comunes' => json_encode($comunes)]);
                $m->datos_comunes = $comunes;
            }
        });

        try {
            $emisor->emitir($p, $lote, null);
            $this->fail('Debe detectar el cambio del lote');
        } catch (EmisionException $e) {
            $this->assertSame('DATOS_CAMBIARON_DURANTE_EMISION', $e->codigo);
        }
        $this->assertSame(0, Emision::count());
        $this->assertSame([], $this->archivosDeEmisiones());
    }

    public function test_fallo_de_escritura_no_deja_filas_ni_archivos(): void
    {
        $lote = $this->loteCon(1);
        $p = $lote->participantes()->first();
        AlmacenEmisiones::$antesDeEscribir = function () {
            throw new \RuntimeException('disco lleno simulado');
        };

        $r = $this->emitirPor($lote, $p);

        $r->assertStatus(500)->assertJsonPath('error.code', 'ERROR_ESCRITURA');
        $this->assertStringNotContainsString('simulado', $r->getContent());
        $this->assertSame(0, Emision::count());
        $this->assertSame([], $this->archivosDeEmisiones());

        // y se puede emitir después, sin secuelas
        AlmacenEmisiones::$antesDeEscribir = null;
        $this->emitirPor($lote, $p)->assertCreated();
    }

    public function test_si_falla_la_base_de_datos_tras_escribir_el_archivo_se_limpia(): void
    {
        $lote = $this->loteCon(1);
        $p = $lote->participantes()->first();
        Movimiento::creating(function () {
            throw new \RuntimeException('fallo de auditoría simulado');
        });

        $this->emitirPor($lote, $p)->assertStatus(500);

        $this->assertSame(0, Emision::count());
        $this->assertSame([], $this->archivosDeEmisiones(), 'El archivo definitivo se borró tras el rollback');
    }

    public function test_emitir_a_un_participante_de_otro_lote_es_404(): void
    {
        $lote = $this->loteCon(1);
        $otro = $this->loteCon(1);

        $this->emitirPor($lote, $otro->participantes()->first())->assertNotFound();
        $this->assertSame(0, Emision::count());
    }

    public function test_errores_del_generador_se_informan_sin_emitir(): void
    {
        $heredada = $this->loteCon(1, $this->plantillaLista([$this->elemento(['field' => 'nombre_completo', 'fontFamily' => 'Figtree'])]));
        $this->emitirPor($heredada, $heredada->participantes()->first())->assertStatus(422)->assertJsonPath('error.code', 'FUENTE_NO_REPRODUCIBLE');

        $angosta = $this->loteCon(1, $this->plantillaLista([$this->elemento(['field' => 'nombre_completo', 'width' => 40])]));
        $r = $this->emitirPor($angosta, $angosta->participantes()->first())->assertStatus(422)->assertJsonPath('error.code', 'NO_CABE');
        $this->assertStringNotContainsString(base_path(), $r->getContent());

        $this->assertSame(0, Emision::count());
        $this->assertSame([], $this->archivosDeEmisiones());
    }

    // ── Descarga e integridad ─────────────────────────────────────────────────

    private function emitida(?Lote $lote = null): Emision
    {
        $lote ??= $this->loteCon(1);

        return Emision::findOrFail($this->emitirPor($lote, $lote->participantes()->first())->assertCreated()->json('emision.id'));
    }

    public function test_descarga_sirve_el_pdf_almacenado_verificado(): void
    {
        $e = $this->emitida();

        $r = $this->actingAs($this->admin())->get(route('credential-flow.emisiones.descargar', $e));

        $r->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $r->assertHeader('Content-Disposition', 'attachment; filename="credencial-'.$e->id.'-v1.pdf"');
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $r->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame(Storage::disk('local')->get($e->pdf_archivo), $r->getContent());
        $this->assertSame($e->pdf_hash, hash('sha256', $r->getContent()));
        $this->assertStringNotContainsString('PERSONA', $r->headers->get('Content-Disposition'));
    }

    public function test_descarga_falla_si_falta_el_archivo(): void
    {
        $e = $this->emitida();
        Storage::disk('local')->delete($e->pdf_archivo);

        $r = $this->actingAs($this->admin())->getJson(route('credential-flow.emisiones.descargar', $e));

        $r->assertStatus(409)->assertJsonPath('error.code', 'ARCHIVO_EMISION_NO_EXISTE');
        $this->assertStringNotContainsString($e->pdf_archivo, $r->getContent());
    }

    public function test_descarga_falla_si_el_hash_o_el_tamano_no_coinciden(): void
    {
        $e = $this->emitida();
        $original = Storage::disk('local')->get($e->pdf_archivo);

        // mismos bytes de longitud, contenido distinto → hash inválido
        Storage::disk('local')->put($e->pdf_archivo, strrev($original));
        $this->actingAs($this->admin())->getJson(route('credential-flow.emisiones.descargar', $e))->assertStatus(409)->assertJsonPath('error.code', 'INTEGRIDAD_EMISION_INVALIDA');

        // tamaño distinto
        Storage::disk('local')->put($e->pdf_archivo, $original.'x');
        $r = $this->actingAs($this->admin())->getJson(route('credential-flow.emisiones.descargar', $e));
        $r->assertStatus(409)->assertJsonPath('error.code', 'INTEGRIDAD_EMISION_INVALIDA');
        $this->assertStringNotContainsString(base_path(), $r->getContent());
    }

    public function test_una_ruta_registrada_fuera_del_almacen_nunca_se_sirve(): void
    {
        $e = $this->emitida();
        DB::table('cf_emisiones')->where('id', $e->id)->update(['pdf_archivo' => '../../.env']);

        $this->actingAs($this->admin())->getJson(route('credential-flow.emisiones.descargar', $e->id))->assertStatus(409)->assertJsonPath('error.code', 'INTEGRIDAD_EMISION_INVALIDA');
    }

    // ── Revocación ────────────────────────────────────────────────────────────

    public function test_revocacion_valida_libera_la_vigencia_y_no_toca_el_pdf(): void
    {
        $lote = $this->loteCon(1);
        $e = $this->emitida($lote);
        $bytes = Storage::disk('local')->get($e->pdf_archivo);
        $antes = Movimiento::count();

        $r = $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $e), ['motivo' => 'Error en el nombre del participante']);

        $r->assertOk()->assertJsonPath('emision.estado', 'revocada');
        $e->refresh();
        $this->assertSame('revocada', $e->estado);
        $this->assertNull($e->participante_vigente);
        $this->assertNotNull($e->revocado_at);
        $this->assertSame($this->admin()->id, $e->revocado_por);
        $this->assertSame('Error en el nombre del participante', $e->motivo_revocacion);
        $this->assertSame($bytes, Storage::disk('local')->get($e->pdf_archivo), 'El PDF no se modifica ni se borra');
        $this->assertNotNull($e->datos_snapshot);

        $m = Movimiento::latest('id')->first();
        $this->assertSame($antes + 1, Movimiento::count());
        $this->assertSame($e->id, $m->metadata['emision_id']);
        $this->assertSame($lote->id, $m->metadata['lote_id']);
        $this->assertStringNotContainsString('Error en el nombre', json_encode($m->toArray()), 'El motivo vive en la emisión, no en el Movimiento');

        // una revocada se sigue pudiendo descargar
        $this->actingAs($this->admin())->get(route('credential-flow.emisiones.descargar', $e))->assertOk();
    }

    public function test_el_motivo_de_revocacion_es_obligatorio_de_5_a_500(): void
    {
        $e = $this->emitida();

        foreach ([null, '', '    ', 'abc', str_repeat('x', 501)] as $motivo) {
            $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $e), ['motivo' => $motivo])->assertStatus(422)->assertJsonValidationErrors('motivo');
        }
        $this->assertSame('emitida', $e->fresh()->estado);

        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $e), ['motivo' => str_repeat('x', 500)])->assertOk();
    }

    public function test_doble_revocacion_falla_con_409(): void
    {
        $e = $this->emitida();
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $e), ['motivo' => 'Primera revocación'])->assertOk();

        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $e), ['motivo' => 'Segunda revocación'])->assertStatus(409)->assertJsonPath('error.code', 'EMISION_YA_REVOCADA');
        $this->assertSame('Primera revocación', $e->fresh()->motivo_revocacion);
    }

    public function test_tras_revocar_se_puede_volver_a_emitir_con_version_2(): void
    {
        $lote = $this->loteCon(1);
        $p = $lote->participantes()->first();
        $v1 = $this->emitida($lote);
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $v1), ['motivo' => 'Emitida por equivocación'])->assertOk();

        $r = $this->emitirPor($lote, $p)->assertCreated();

        $this->assertSame(2, $r->json('emision.version'));
        $this->assertSame(2, Emision::where('participante_id', $p->id)->count());
        $this->assertSame(1, Emision::where('participante_id', $p->id)->where('estado', 'emitida')->count());
    }

    // ── Reemisión ─────────────────────────────────────────────────────────────

    public function test_reemision_crea_la_version_siguiente_revoca_la_anterior_y_conserva_ambos_pdfs(): void
    {
        $lote = $this->loteCon(1);
        $p = $lote->participantes()->first();
        $v1 = $this->emitida($lote);
        $pdf1 = Storage::disk('local')->get($v1->pdf_archivo);

        // Se corrige el nombre DESPUÉS de emitir
        $p->update(['nombre_completo' => 'PERSONA NÚMERO UNO CORREGIDA']);

        $antes = Movimiento::count();
        $r = $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.reemitir', $v1), ['motivo' => 'Corrección del nombre']);

        $r->assertCreated()->assertJsonPath('emision.version', 2)->assertJsonPath('emision.reemplaza_id', $v1->id);
        $v2 = Emision::findOrFail($r->json('emision.id'));
        $v1->refresh();

        $this->assertSame('revocada', $v1->estado);
        $this->assertNull($v1->participante_vigente);
        $this->assertStringContainsString('Reemplazada por la versión 2', $v1->motivo_revocacion);
        $this->assertStringContainsString('Corrección del nombre', $v1->motivo_revocacion);
        $this->assertSame('emitida', $v2->estado);
        $this->assertSame($p->id, $v2->participante_vigente);
        $this->assertSame($v1->id, $v2->reemplaza_id);

        // snapshots distintos
        $this->assertSame('PERSONA NÚMERO 1', $v1->datos_snapshot['nombre_completo']);
        $this->assertSame('PERSONA NÚMERO UNO CORREGIDA', $v2->datos_snapshot['nombre_completo']);

        // dos PDFs históricos, distintos, y el anterior intacto
        $this->assertNotSame($v1->pdf_archivo, $v2->pdf_archivo);
        $this->assertSame($pdf1, Storage::disk('local')->get($v1->pdf_archivo));
        $this->assertNotSame($v1->pdf_hash, $v2->pdf_hash);
        $this->assertContains('PERSONA NÚMERO UNO CORREGIDA', array_column((new InspectorPdf(AlmacenEmisiones::leerVerificado($v2)))->textos(), 'texto'));
        $this->assertContains('PERSONA NÚMERO 1', array_column((new InspectorPdf(AlmacenEmisiones::leerVerificado($v1)))->textos(), 'texto'));

        // Movimiento de reemisión sin datos personales
        $m = Movimiento::latest('id')->first();
        $this->assertSame($antes + 1, Movimiento::count());
        $this->assertSame($v2->id, $m->metadata['nueva_emision_id']);
        $this->assertSame($v1->id, $m->metadata['reemplaza_emision_id']);
        $this->assertSame($lote->id, $m->metadata['lote_id']);
        $this->assertStringNotContainsString('CORREGIDA', json_encode($m->toArray()));
    }

    public function test_una_emision_antigua_no_cambia_aunque_cambien_los_datos_o_el_diseno(): void
    {
        $lote = $this->loteCon(1);
        $p = $lote->participantes()->first();
        $e = $this->emitida($lote);
        $hash = $e->pdf_hash;
        $descarga = fn () => $this->actingAs($this->admin())->get(route('credential-flow.emisiones.descargar', $e))->getContent();

        $this->assertSame($hash, hash('sha256', $descarga()));

        // cambian participante, datos comunes del lote y diseño (se mueve el nombre)
        $p->update(['nombre_completo' => 'NOMBRE COMPLETAMENTE DISTINTO']);
        $lote->update(['datos_comunes' => ['evento' => 'OTRO EVENTO', 'fecha' => 'otra', 'intensidad_horaria' => '1 hora']]);
        $d = $lote->plantilla->diseno;
        $d['elements'][0]['y'] = 300;
        $lote->plantilla->update(['diseno' => $d]);

        $this->assertSame($hash, hash('sha256', $descarga()), 'Se sirve exactamente el PDF original');
        $this->assertSame('PERSONA NÚMERO 1', $e->fresh()->datos_snapshot['nombre_completo']);
        $this->assertSame(60, (int) $e->fresh()->diseno_snapshot['elements'][0]['y'], 'El diseño congelado no cambia');

        // una reemisión sí usa lo nuevo
        $r = $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.reemitir', $e), ['motivo' => 'Se actualiza el diseño'])->assertCreated();
        $nueva = Emision::findOrFail($r->json('emision.id'));
        $this->assertSame('NOMBRE COMPLETAMENTE DISTINTO', $nueva->datos_snapshot['nombre_completo']);
        $this->assertSame('OTRO EVENTO', $nueva->datos_snapshot['evento']);
        $this->assertSame(300, (int) $nueva->diseno_snapshot['elements'][0]['y']);
    }

    public function test_reemitir_una_emision_ya_revocada_es_409_y_el_motivo_es_obligatorio(): void
    {
        $e = $this->emitida();

        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.reemitir', $e), ['motivo' => 'ab'])->assertStatus(422);
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $e), ['motivo' => 'Revocada a mano'])->assertOk();

        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.reemitir', $e), ['motivo' => 'Intento tardío'])->assertStatus(409)->assertJsonPath('error.code', 'EMISION_YA_REVOCADA');
        $this->assertSame(1, Emision::count());
    }

    public function test_las_versiones_nunca_se_reutilizan(): void
    {
        $lote = $this->loteCon(1);
        $e = $this->emitida($lote);
        for ($i = 2; $i <= 4; $i++) {
            $e = Emision::findOrFail($this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.reemitir', $e), ['motivo' => "Reemisión número $i"])->assertCreated()->json('emision.id'));
            $this->assertSame($i, $e->version);
        }
        $this->assertSame([1, 2, 3, 4], Emision::orderBy('version')->pluck('version')->all());
        $this->assertSame(1, Emision::where('estado', 'emitida')->count());
    }

    // ── Bloqueos de eliminación ───────────────────────────────────────────────

    public function test_participante_con_emision_vigente_no_se_puede_eliminar_pero_con_solo_revocadas_si(): void
    {
        $lote = $this->loteCon(1);
        $p = $lote->participantes()->first();
        $e = $this->emitida($lote);

        $this->actingAs($this->admin())->delete(route('credential-flow.participantes.destroy', [$lote, $p]))->assertSessionHas('error');
        $this->assertNotNull(Participante::find($p->id));

        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $e), ['motivo' => 'Se revoca para eliminar'])->assertOk();
        $this->actingAs($this->admin())->delete(route('credential-flow.participantes.destroy', [$lote, $p]))->assertSessionHas('success');

        $this->assertNull(Participante::find($p->id));
        $this->assertNotNull($e->fresh()->participante, 'La emisión resuelve a su participante con withTrashed');
        $this->assertSame($p->id, $e->fresh()->participante->id);
    }

    public function test_lote_con_emision_vigente_no_se_puede_eliminar_pero_con_solo_revocadas_si(): void
    {
        $lote = $this->loteCon(1);
        $e = $this->emitida($lote);

        $this->actingAs($this->admin())->delete(route('credential-flow.lotes.destroy', $lote))->assertSessionHas('error');
        $this->assertNotNull(Lote::find($lote->id));

        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $e), ['motivo' => 'Se revoca para eliminar'])->assertOk();
        $this->actingAs($this->admin())->delete(route('credential-flow.lotes.destroy', $lote))->assertRedirect(route('credential-flow.lotes.index'));

        $this->assertNull(Lote::find($lote->id));
        $this->assertSame($lote->id, $e->fresh()->lote->id, 'La emisión conserva su lote (withTrashed)');
        $this->actingAs($this->admin())->get(route('credential-flow.emisiones.descargar', $e))->assertOk();
    }

    public function test_plantilla_con_cualquier_emision_no_se_puede_eliminar(): void
    {
        $lote = $this->loteCon(1);
        $plantilla = $lote->plantilla;
        $e = $this->emitida($lote);

        // vigente
        $this->actingAs($this->admin())->delete(route('credential-flow.plantillas.destroy', $plantilla))->assertSessionHas('error');
        $this->assertNotNull(Plantilla::find($plantilla->id));

        // revocada + lote eliminado: sigue bloqueada
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $e), ['motivo' => 'Revocada para la prueba'])->assertOk();
        $this->actingAs($this->admin())->delete(route('credential-flow.lotes.destroy', $lote))->assertSessionHas('success');
        $this->actingAs($this->admin())->delete(route('credential-flow.plantillas.destroy', $plantilla))->assertSessionHas('error');

        $this->assertNotNull(Plantilla::find($plantilla->id));
        $this->assertTrue(Storage::disk('local')->exists($plantilla->rutaPdfEsperada()), 'El PDF base se conserva');
        $this->assertSame($plantilla->hash_sha256, $e->fresh()->plantilla_pdf_hash);
    }

    // ── Espacio en disco ──────────────────────────────────────────────────────

    public function test_espacio_insuficiente_simulado_impide_emitir(): void
    {
        $lote = $this->loteCon(1);
        EspacioDisco::simular(1024); // 1 KB libres

        $this->emitirPor($lote, $lote->participantes()->first())->assertStatus(409)->assertJsonPath('error.code', 'ESPACIO_INSUFICIENTE');

        $this->assertSame(0, Emision::count());
        $this->assertSame([], $this->archivosDeEmisiones());

        EspacioDisco::simular(50 * 1024 * 1024 * 1024);
        $this->emitirPor($lote, $lote->participantes()->first())->assertCreated();
    }

    public function test_el_calculo_de_espacio_mide_y_deja_margen(): void
    {
        $necesario = EspacioDisco::estimarEmisiones(200, 700_000);
        $this->assertSame(200 * (700_000 + EspacioDisco::SOBRECOSTO_PDF_BYTES), $necesario);

        EspacioDisco::simular((int) ($necesario * EspacioDisco::FACTOR_MARGEN) + EspacioDisco::RESERVA_BYTES + 1);
        EspacioDisco::exigir($necesario, sys_get_temp_dir());
        $this->addToAssertionCount(1);

        EspacioDisco::simular((int) ($necesario * EspacioDisco::FACTOR_MARGEN) + EspacioDisco::RESERVA_BYTES - 1);
        $this->expectException(EmisionException::class);
        EspacioDisco::exigir($necesario, sys_get_temp_dir());
    }

    // ── Permisos ──────────────────────────────────────────────────────────────

    public function test_permisos_de_las_rutas_de_emision(): void
    {
        $lote = $this->loteCon(1);
        $p = $lote->participantes()->first();
        $e = $this->emitida($lote);
        $rutas = [
            ['POST', route('credential-flow.participantes.emitir', [$lote, $p])],
            ['GET', route('credential-flow.lotes.emision.resumen', $lote)],
            ['POST', route('credential-flow.lotes.emitir', $lote)],
            ['GET', route('credential-flow.lotes.zip', $lote)],
            ['GET', route('credential-flow.lotes.emisiones', $lote)],
            ['GET', route('credential-flow.emisiones.descargar', $e)],
            ['POST', route('credential-flow.emisiones.revocar', $e)],
            ['POST', route('credential-flow.emisiones.reemitir', $e)],
        ];

        foreach ($rutas as [$metodo, $url]) {
            $this->actingAs($this->comercial())->call($metodo, $url)->assertForbidden();
        }
        $this->assertSame('emitida', $e->fresh()->estado);

        $this->app['auth']->forgetGuards();
        foreach ($rutas as [$metodo, $url]) {
            $this->call($metodo, $url)->assertRedirect();
        }

        foreach ([$this->admin(), $this->superAdmin()] as $usuario) {
            $this->actingAs($usuario)->get(route('credential-flow.lotes.emision.resumen', $lote))->assertOk();
        }
    }

    public function test_todas_las_rutas_de_emision_son_administrativas_con_throttle(): void
    {
        $nombres = ['participantes.emitir', 'lotes.emision.resumen', 'lotes.emitir', 'lotes.zip', 'lotes.emisiones', 'emisiones.descargar', 'emisiones.revocar', 'emisiones.reemitir'];
        foreach ($nombres as $nombre) {
            $ruta = app('router')->getRoutes()->getByName('credential-flow.'.$nombre);
            $this->assertNotNull($ruta, $nombre);
            $this->assertStringStartsWith('admin/credential-flow/', $ruta->uri());
            $this->assertContains('auth', $ruta->gatherMiddleware());
            $this->assertContains('rol:super-admin,admin', $ruta->gatherMiddleware());
        }
        foreach (['participantes.emitir', 'lotes.emitir', 'lotes.zip', 'emisiones.descargar', 'emisiones.revocar', 'emisiones.reemitir'] as $nombre) {
            $this->assertNotEmpty(array_filter(app('router')->getRoutes()->getByName('credential-flow.'.$nombre)->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')), $nombre);
        }
    }

    // ── Estado derivado e historial ───────────────────────────────────────────

    public function test_estado_derivado_del_participante_y_conteos_sin_columna_de_estado(): void
    {
        $lote = $this->loteCon(3);
        [$a, $b, $c] = $lote->participantes()->orderBy('id')->get()->all();
        $this->assertFalse(Schema::hasColumn('cf_participantes', 'estado'));

        $this->emitirPor($lote, $a)->assertCreated();
        $eb = Emision::findOrFail($this->emitirPor($lote, $b)->assertCreated()->json('emision.id'));
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $eb), ['motivo' => 'Revocada de prueba'])->assertOk();

        $consultas = 0;
        DB::listen(function () use (&$consultas) {
            $consultas++;
        });
        $r = $this->actingAs($this->admin())->get(route('credential-flow.lotes.show', $lote));

        $r->assertInertia(fn ($page) => $page
            ->where('participantes.data.0.estado_emision', 'emitida')
            ->where('participantes.data.0.emision.version', 1)
            ->where('participantes.data.1.estado_emision', 'revocada')
            ->where('participantes.data.1.emisiones_count', 1)
            ->where('participantes.data.2.estado_emision', 'sin_emitir')
            ->where('lote.emision.pendientes', 1)
            ->where('lote.emision.vigentes', 1)
            ->where('lote.emision.revocados', 1)
            ->where('lote.emision.limite', 200));
        $this->assertLessThan(30, $consultas, 'Sin N+1: las emisiones se cargan en bloque');
    }

    public function test_historial_del_lote(): void
    {
        $lote = $this->loteCon(2);
        $e1 = $this->emitida($lote);
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.reemitir', $e1), ['motivo' => 'Reemisión de prueba'])->assertCreated();

        $r = $this->actingAs($this->admin())->getJson(route('credential-flow.lotes.emisiones', $lote))->assertOk();

        $this->assertSame(2, $r->json('total'));
        $this->assertSame([2, 1], array_column($r->json('emisiones'), 'version'));
        $this->assertSame(['emitida', 'revocada'], array_column($r->json('emisiones'), 'estado'));
        $this->assertSame($this->admin()->correo_principal, $r->json('emisiones.0.emitido_por'));
        $this->assertSame('PERSONA NÚMERO 1', $r->json('emisiones.0.participante'));
    }
}
