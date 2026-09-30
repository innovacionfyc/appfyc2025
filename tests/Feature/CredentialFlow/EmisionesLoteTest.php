<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Emisiones\AlmacenEmisiones;
use App\Support\CredentialFlow\Emisiones\EmisionException;
use App\Support\CredentialFlow\Emisiones\EmisorLote;
use App\Support\CredentialFlow\Emisiones\EspacioDisco;
use App\Support\CredentialFlow\Emisiones\GeneradorZip;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\CredentialFlow\Support\InspectorPdf;
use ZipArchive;

/** F&C Credential Flow · Fase 7: emisión masiva (todo o nada, fingerprints), ZIP y auditoría. */
class EmisionesLoteTest extends EmisionesTestCase
{
    private function emitirLote(Lote $lote, $usuario = null)
    {
        return $this->actingAs($usuario ?? $this->admin())->postJson(route('credential-flow.lotes.emitir', $lote));
    }

    private function sinResiduos(): void
    {
        foreach ($this->archivosDeEmisiones() as $archivo) {
            $this->assertStringNotContainsString('/.staging/', $archivo, "Residuo de staging: $archivo");
            $this->assertStringNotContainsString('/.tmp/', $archivo, "Residuo temporal: $archivo");
        }
    }

    // ── Resumen y emisión ─────────────────────────────────────────────────────

    public function test_resumen_de_pendientes(): void
    {
        $lote = $this->loteCon(3);
        [$a, $b] = $lote->participantes()->orderBy('id')->get()->all();
        $this->emitirPor($lote, $a)->assertCreated();
        $eb = Emision::findOrFail($this->emitirPor($lote, $b)->assertCreated()->json('emision.id'));
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $eb), ['motivo' => 'Revocada de prueba'])->assertOk();

        $r = $this->actingAs($this->admin())->getJson(route('credential-flow.lotes.emision.resumen', $lote))->assertOk();

        $this->assertSame(['participantes' => 3, 'vigentes' => 1, 'revocados' => 1, 'pendientes' => 1, 'a_emitir' => 1, 'limite' => 200], $r->json('resumen'));
    }

    public function test_emitir_pendientes_de_un_participante_y_de_varios(): void
    {
        $uno = $this->loteCon(1);
        $r = $this->emitirLote($uno)->assertCreated();
        $this->assertSame(1, $r->json('resultado.total'));
        $this->assertSame(0, $r->json('resultado.restantes'));

        $lote = $this->loteCon(5);
        $antes = Movimiento::count();
        $r = $this->emitirLote($lote)->assertCreated();

        $this->assertSame(5, $r->json('resultado.total'));
        $operacion = $r->json('resultado.operacion');
        $emisiones = Emision::where('lote_id', $lote->id)->orderBy('id')->get();
        $this->assertCount(5, $emisiones);
        $this->assertSame([$operacion], $emisiones->pluck('operacion')->unique()->values()->all(), 'Todas comparten la misma operación');
        $this->assertSame([1], $emisiones->pluck('version')->unique()->values()->all());
        $this->assertSame($emisiones->pluck('participante_id')->all(), $emisiones->pluck('participante_vigente')->all());
        $this->assertCount(5, array_unique($emisiones->pluck('codigo')->all()));
        $this->assertCount(5, array_unique($emisiones->pluck('pdf_archivo')->all()));

        foreach ($emisiones as $e) {
            $bytes = AlmacenEmisiones::leerVerificado($e);
            $this->assertSame($e->datos_snapshot['nombre_completo'], (new InspectorPdf($bytes))->textos()[0]['texto']);
        }
        $this->sinResiduos();

        // UN solo Movimiento por la acción masiva, sin datos personales
        $nuevos = Movimiento::where('id', '>', 0)->latest('id')->take(Movimiento::count() - $antes)->get();
        $this->assertCount(1, $nuevos);
        $this->assertSame(['lote_id' => $lote->id, 'operacion' => $operacion, 'total' => 5], array_intersect_key($nuevos->first()->metadata, array_flip(['lote_id', 'operacion', 'total'])));
        $this->assertStringNotContainsString('PERSONA', json_encode($nuevos->first()->toArray()));
    }

    public function test_emite_como_maximo_200_por_operacion_y_repetir_solo_toma_los_pendientes(): void
    {
        $lote = $this->loteCon(205);
        $this->assertSame(200, EmisorLote::LIMITE);

        $r = $this->emitirLote($lote)->assertCreated();
        $this->assertSame(200, $r->json('resultado.total'));
        $this->assertSame(5, $r->json('resultado.restantes'));
        $this->assertSame(200, Emision::count());

        $r = $this->emitirLote($lote)->assertCreated();
        $this->assertSame(5, $r->json('resultado.total'));
        $this->assertSame(0, $r->json('resultado.restantes'));
        $this->assertSame(205, Emision::count());

        // repetir sin pendientes: no crea nada
        $archivos = $this->archivosDeEmisiones();
        $this->emitirLote($lote)->assertStatus(409)->assertJsonPath('error.code', 'SIN_PENDIENTES');
        $this->assertSame(205, Emision::count());
        $this->assertSame($archivos, $this->archivosDeEmisiones());
        $this->assertCount(2, Emision::distinct()->pluck('operacion'), 'Dos operaciones, una por cada pulsación con pendientes');
    }

    public function test_los_participantes_con_historial_no_son_pendientes_ni_se_reemiten_en_masa(): void
    {
        $lote = $this->loteCon(2);
        [$a, $b] = $lote->participantes()->orderBy('id')->get()->all();
        $ea = Emision::findOrFail($this->emitirPor($lote, $a)->assertCreated()->json('emision.id'));
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $ea), ['motivo' => 'Revocada de prueba'])->assertOk();

        $r = $this->emitirLote($lote)->assertCreated();

        $this->assertSame(1, $r->json('resultado.total'));
        $this->assertSame(1, Emision::where('participante_id', $a->id)->count(), 'El revocado no se reemite en masa');
        $this->assertSame(1, Emision::where('participante_id', $b->id)->count());
    }

    // ── Todo o nada ───────────────────────────────────────────────────────────

    public function test_un_participante_invalido_impide_emitir_a_todos(): void
    {
        $lote = $this->loteCon(3);
        $this->participante($lote, 'Ana 日本', 'C.C. 99.888.777'); // carácter que Outfit no puede imprimir (insertado sin pasar por el validador)

        $r = $this->emitirLote($lote);

        $r->assertStatus(422)->assertJsonPath('error.code', 'PREVALIDACION_FALLIDA');
        $detalles = $r->json('error.detalles');
        $this->assertCount(1, $detalles);
        $this->assertSame('CARACTER_NO_SOPORTADO', $detalles[0]['codigo']);
        $this->assertSame(0, Emision::count());
        $this->assertSame([], $this->archivosDeEmisiones(), 'Ni PDFs ni staging');
        $this->assertStringNotContainsString(base_path(), $r->getContent());
    }

    public function test_no_cabe_en_un_participante_impide_emitir_a_todos(): void
    {
        $plantilla = $this->plantillaLista([$this->elemento(['field' => 'nombre_completo', 'width' => 260, 'fontWeight' => 400])]);
        $lote = $this->loteCon(2, $plantilla);
        $this->participante($lote, 'Maria Fernanda de los Angeles Rodriguez Pena de la Cruz', 'C.C. 1.234.567');

        $r = $this->emitirLote($lote)->assertStatus(422)->assertJsonPath('error.code', 'PREVALIDACION_FALLIDA');

        $this->assertSame(['NO_CABE'], array_column($r->json('error.detalles'), 'codigo'));
        $this->assertSame(0, Emision::count());
        $this->assertSame([], $this->archivosDeEmisiones());
    }

    public function test_fuente_heredada_o_plantilla_sin_diseno_impiden_la_emision_masiva(): void
    {
        $lote = $this->loteCon(2, $this->plantillaLista([$this->elemento(['field' => 'nombre_completo', 'fontFamily' => 'Figtree'])]));

        $r = $this->emitirLote($lote)->assertStatus(422)->assertJsonPath('error.code', 'PREVALIDACION_FALLIDA');

        $this->assertCount(2, $r->json('error.detalles'));
        $this->assertSame(['FUENTE_NO_REPRODUCIBLE'], array_unique(array_column($r->json('error.detalles'), 'codigo')));
        $this->assertSame(0, Emision::count());
    }

    // ── Cambios concurrentes durante la emisión masiva (fingerprints) ─────────

    private function assertNadaEmitido(): void
    {
        $this->assertSame(0, Emision::count());
        $this->assertSame([], $this->archivosDeEmisiones(), 'No quedan PDFs ni staging');
    }

    private function intentarMasiva(Lote $lote): EmisionException
    {
        try {
            app(EmisorLote::class)->emitirPendientes($lote, $this->admin()->id);
            $this->fail('Debía fallar por el cambio concurrente');
        } catch (EmisionException $e) {
            return $e;
        }
    }

    public function test_un_participante_editado_durante_la_masiva_cancela_todo(): void
    {
        $lote = $this->loteCon(3);
        $lote->refresh();
        $recuperados = 0;
        // 3 al tomar los pendientes; a partir del cuarto (ya dentro de la transacción) otro usuario edita a uno
        Participante::retrieved(function (Participante $m) use (&$recuperados) {
            $recuperados++;
            if ($recuperados === 4) {
                DB::table('cf_participantes')->where('id', $m->id)->update(['documento' => 'C.C. 1.111.111']);
                $m->documento = 'C.C. 1.111.111';
            }
        });

        $e = $this->intentarMasiva($lote);

        $this->assertSame('DATOS_CAMBIARON_DURANTE_EMISION', $e->codigo);
        $this->assertSame(409, $e->estadoHttp);
        $this->assertNadaEmitido();
    }

    public function test_un_participante_eliminado_durante_la_masiva_cancela_todo(): void
    {
        $lote = $this->loteCon(3);
        $recuperados = 0;
        Participante::retrieved(function (Participante $m) use (&$recuperados) {
            $recuperados++;
            if ($recuperados === 4) {
                DB::table('cf_participantes')->where('id', $m->id)->update(['deleted_at' => now()]);
            }
        });
        // El bloqueo (whereIn + lockForUpdate) ya no lo encuentra: faltan participantes → cambió el conjunto.
        // (la eliminación se ve en la siguiente consulta; se fuerza aquí eliminando antes del bloqueo)
        Lote::retrieved(function (Lote $m) {
            static $hecho = false;
            if (! $hecho) {
                $hecho = true;
                DB::table('cf_participantes')->where('lote_id', $m->id)->orderBy('id')->limit(1)->update(['deleted_at' => now()]);
            }
        });

        $e = $this->intentarMasiva($lote);

        $this->assertSame('DATOS_CAMBIARON_DURANTE_EMISION', $e->codigo);
        $this->assertNadaEmitido();
    }

    public function test_datos_comunes_del_lote_editados_durante_la_masiva_cancelan_todo(): void
    {
        $lote = $this->loteCon(3);
        Lote::retrieved(function (Lote $m) {
            static $hecho = false;
            if (! $hecho) {
                $hecho = true;
                $nuevos = ['evento' => 'EVENTO EDITADO', 'fecha' => 'otra fecha', 'intensidad_horaria' => '1 hora'];
                DB::table('cf_lotes')->where('id', $m->id)->update(['datos_comunes' => json_encode($nuevos)]);
                $m->datos_comunes = $nuevos;
            }
        });

        $this->assertSame('DATOS_CAMBIARON_DURANTE_EMISION', $this->intentarMasiva($lote)->codigo);
        $this->assertNadaEmitido();
    }

    public function test_diseno_editado_durante_la_masiva_cancela_todo(): void
    {
        $lote = $this->loteCon(3);
        $recuperadas = 0;
        // 1.ª: $lote->plantilla al preparar; 2.ª: Plantilla::find dentro de la transacción → el diseño cambia justo ahí
        Plantilla::retrieved(function (Plantilla $m) use (&$recuperadas) {
            $recuperadas++;
            if ($recuperadas === 2) {
                $d = $m->diseno;
                $d['elements'][0]['x'] = 150;
                DB::table('cf_plantillas')->where('id', $m->id)->update(['diseno' => json_encode($d)]);
                $m->diseno = $d;
            }
        });

        $this->assertSame('DATOS_CAMBIARON_DURANTE_EMISION', $this->intentarMasiva($lote)->codigo);
        $this->assertNadaEmitido();
    }

    public function test_un_participante_emitido_por_otra_operacion_durante_la_masiva_cancela_todo(): void
    {
        $lote = $this->loteCon(3);
        $rival = $lote->participantes()->orderBy('id')->first();
        Lote::retrieved(function (Lote $m) use ($rival) {
            static $hecho = false;
            if (! $hecho) {
                $hecho = true;
                DB::table('cf_emisiones')->insert([
                    'codigo' => 'RIVAL'.str_repeat('0', 15), 'participante_id' => $rival->id, 'lote_id' => $m->id, 'plantilla_id' => $m->plantilla_id,
                    'version' => 1, 'estado' => 'emitida', 'participante_vigente' => $rival->id, 'datos_snapshot' => '{}', 'diseno_snapshot' => '{}',
                    'schema_version' => 1, 'plantilla_pdf_hash' => str_repeat('a', 64), 'generador_snapshot' => '{}', 'pdf_archivo' => 'x',
                    'pdf_hash' => str_repeat('b', 64), 'pdf_bytes' => 1, 'emitido_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $this->assertSame('EMISION_YA_VIGENTE', $this->intentarMasiva($lote)->codigo);
        $this->assertSame([], $this->archivosDeEmisiones());
    }

    // ── Espacio y fallos de escritura ─────────────────────────────────────────

    public function test_espacio_insuficiente_simulado_impide_la_masiva_sin_empezar(): void
    {
        $lote = $this->loteCon(3);
        EspacioDisco::simular(10 * 1024 * 1024);

        $this->emitirLote($lote)->assertStatus(409)->assertJsonPath('error.code', 'ESPACIO_INSUFICIENTE');

        $this->assertNadaEmitido();

        EspacioDisco::simular(80 * 1024 * 1024 * 1024);
        $this->emitirLote($lote)->assertCreated();
    }

    public function test_fallo_de_escritura_a_mitad_de_la_masiva_limpia_el_staging(): void
    {
        $lote = $this->loteCon(4);
        $llamadas = 0;
        AlmacenEmisiones::$antesDeEscribir = function () use (&$llamadas) {
            if (++$llamadas === 3) {
                throw new \RuntimeException('disco lleno simulado');
            }
        };

        $r = $this->emitirLote($lote);

        $r->assertStatus(500)->assertJsonPath('error.code', 'ERROR_ESCRITURA');
        $this->assertNadaEmitido();
    }

    public function test_fallo_de_la_base_de_datos_en_la_fase_critica_devuelve_los_archivos_movidos(): void
    {
        $lote = $this->loteCon(3);
        $creadas = 0;
        Emision::creating(function () use (&$creadas) {
            if (++$creadas === 3) {
                throw new \RuntimeException('fallo simulado en la inserción');
            }
        });

        $this->emitirLote($lote)->assertStatus(500);

        $this->assertNadaEmitido();
    }

    // ── ZIP ───────────────────────────────────────────────────────────────────

    private function lotesConEmisiones(int $n = 3): array
    {
        $lote = $this->loteCon($n);
        $this->emitirLote($lote)->assertCreated();

        return [$lote, Emision::where('lote_id', $lote->id)->orderBy('id')->get()];
    }

    private function contenidoZip(string $ruta): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta));
        $entradas = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            $entradas[$st['name']] = ['bytes' => $zip->getFromIndex($i), 'metodo' => $st['comp_method']];
        }
        $zip->close();

        return $entradas;
    }

    public function test_zip_con_las_emisiones_vigentes_y_nombres_saneados(): void
    {
        [$lote, $emisiones] = $this->lotesConEmisiones(3);
        $this->participante($lote, 'Ñandú Ángel "Pérez" / ../../etc/passwd', 'C.C. 555.666');
        $this->emitirLote($lote)->assertCreated();
        $todas = Emision::where('lote_id', $lote->id)->orderBy('id')->get();
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $todas[1]), ['motivo' => 'Revocada de prueba'])->assertOk();

        $r = $this->actingAs($this->admin())->get(route('credential-flow.lotes.zip', $lote));

        $r->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContainsString('credenciales-lote-'.$lote->id.'.zip', $r->headers->get('Content-Disposition'));
        $ruta = $r->baseResponse->getFile()->getPathname();
        $entradas = $this->contenidoZip($ruta);

        $this->assertCount(3, $entradas, 'Solo las vigentes (la revocada queda fuera)');
        $this->assertSame(['001-PERSONA-NUMERO-1.pdf', '002-PERSONA-NUMERO-3.pdf', '003-NANDU-ANGEL-PEREZ-ETC-PASSWD.pdf'], array_keys($entradas));
        foreach ($entradas as $nombre => $e) {
            $this->assertMatchesRegularExpression('/^\d{3}-[A-Z0-9-]{1,60}\.pdf$/', $nombre);
            $this->assertStringNotContainsString('/', $nombre);
            $this->assertStringNotContainsString('..', $nombre);
            $this->assertSame(0, $e['metodo'], 'CM_STORE: los PDFs ya están comprimidos');
            $this->assertStringStartsWith('%PDF', $e['bytes']);
        }
        // cada entrada es EXACTAMENTE el PDF almacenado
        $vigentes = $todas->reject(fn ($e) => $e->id === $todas[1]->id)->values();
        foreach (array_values($entradas) as $i => $e) {
            $this->assertSame($vigentes[$i]->pdf_hash, hash('sha256', $e['bytes']));
        }
        $this->assertStringNotContainsString('20000001', implode(',', array_keys($entradas)), 'El documento no va en los nombres');

        // el temporal se borra al terminar el envío y no queda ningún ZIP persistido
        $r->streamedContent();
        $this->assertFileDoesNotExist($ruta);
        $this->assertSame([], array_filter($this->archivosDeEmisiones(), fn ($f) => str_ends_with($f, '.zip')));
    }

    public function test_nombre_de_entrada_del_zip(): void
    {
        $this->assertSame('001-JUAN-PEREZ.pdf', GeneradorZip::nombreEntrada(1, 'Juan Pérez'));
        $this->assertSame('012-ANGELA-NANDU.pdf', GeneradorZip::nombreEntrada(12, 'ÁNGELA ÑANDÚ'));
        $this->assertSame('001-PARTICIPANTE.pdf', GeneradorZip::nombreEntrada(1, '日本'));
        $this->assertSame('001-PARTICIPANTE.pdf', GeneradorZip::nombreEntrada(1, '../../..'));
        $largo = GeneradorZip::nombreEntrada(7, str_repeat('A', 200));
        $this->assertSame('007-'.str_repeat('A', 60).'.pdf', $largo);
        $this->assertSame('1000-A.pdf', GeneradorZip::nombreEntrada(1000, 'a'));
    }

    public function test_zip_falla_si_no_hay_vigentes_o_si_supera_el_limite_o_no_hay_espacio(): void
    {
        $vacio = $this->loteCon(1);
        $this->actingAs($this->admin())->getJson(route('credential-flow.lotes.zip', $vacio))->assertStatus(409)->assertJsonPath('error.code', 'SIN_EMISIONES_VIGENTES');

        [$lote, $emisiones] = $this->lotesConEmisiones(2);
        $temporales = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'cfz_*') ?: [];

        DB::table('cf_emisiones')->where('id', $emisiones[0]->id)->update(['pdf_bytes' => 300 * 1024 * 1024]); // solo para la prueba del tope
        $this->actingAs($this->admin())->getJson(route('credential-flow.lotes.zip', $lote))->assertStatus(409)->assertJsonPath('error.code', 'ZIP_MUY_GRANDE');
        DB::table('cf_emisiones')->where('id', $emisiones[0]->id)->update(['pdf_bytes' => $emisiones[0]->pdf_bytes]);

        EspacioDisco::simular(1024);
        $this->actingAs($this->admin())->getJson(route('credential-flow.lotes.zip', $lote))->assertStatus(409)->assertJsonPath('error.code', 'ESPACIO_INSUFICIENTE');
        EspacioDisco::simular(null);

        $this->assertSame($temporales, glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'cfz_*') ?: [], 'No quedan ZIP temporales tras los errores');
    }

    public function test_zip_verifica_el_hash_de_cada_pdf_y_limpia_el_temporal(): void
    {
        [$lote, $emisiones] = $this->lotesConEmisiones(3);
        $temporales = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'cfz_*') ?: [];
        Storage::disk('local')->put($emisiones[1]->pdf_archivo, strrev(Storage::disk('local')->get($emisiones[1]->pdf_archivo)));

        $r = $this->actingAs($this->admin())->getJson(route('credential-flow.lotes.zip', $lote));

        $r->assertStatus(409)->assertJsonPath('error.code', 'INTEGRIDAD_EMISION_INVALIDA');
        $this->assertSame($temporales, glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'cfz_*') ?: [], 'El temporal se borra aunque falle');
    }

    // ── Auditoría (comando) ───────────────────────────────────────────────────

    public function test_el_comando_de_auditoria_pasa_con_todo_correcto(): void
    {
        $this->lotesConEmisiones(3);

        $this->artisan('credential-flow:verificar-emisiones')->expectsOutputToContain('Emisiones revisadas: 3')->assertSuccessful();
    }

    public function test_el_comando_de_auditoria_detecta_archivo_ausente_hash_y_bytes(): void
    {
        [, $e] = $this->lotesConEmisiones(3);
        Storage::disk('local')->delete($e[0]->pdf_archivo);
        $original = Storage::disk('local')->get($e[1]->pdf_archivo);
        Storage::disk('local')->put($e[1]->pdf_archivo, strrev($original));
        Storage::disk('local')->put($e[2]->pdf_archivo, $original.'x');
        $antes = $this->archivosDeEmisiones();

        $this->artisan('credential-flow:verificar-emisiones')
            ->expectsOutputToContain('falta el archivo PDF')
            ->expectsOutputToContain('SHA-256')
            ->expectsOutputToContain('pdf_bytes')
            ->assertFailed();

        $this->assertSame($antes, $this->archivosDeEmisiones(), 'La auditoría no repara ni borra nada');
    }

    public function test_el_comando_detecta_huerfanos_residuos_y_vigencias_incoherentes(): void
    {
        [, $e] = $this->lotesConEmisiones(3);
        Storage::disk('local')->put(AlmacenEmisiones::RAIZ.'/ab/00000000-0000-4000-8000-000000000000.pdf', '%PDF-huerfano');
        Storage::disk('local')->put(AlmacenEmisiones::RAIZ.'/.staging/operacion/0.pdf', '%PDF-residuo');
        DB::table('cf_emisiones')->where('id', $e[0]->id)->update(['participante_vigente' => null]);
        DB::table('cf_emisiones')->where('id', $e[1]->id)->update(['datos_snapshot' => '{}']);
        $filas = DB::table('cf_emisiones')->count();

        $this->artisan('credential-flow:verificar-emisiones')
            ->expectsOutputToContain('PDF huérfano')
            ->expectsOutputToContain('Residuo de escritura/staging')
            ->expectsOutputToContain('participante_vigente no coincide')
            ->expectsOutputToContain('datos_snapshot no tiene la forma esperada')
            ->assertFailed();

        $this->assertSame($filas, DB::table('cf_emisiones')->count());
        $this->assertTrue(Storage::disk('local')->exists(AlmacenEmisiones::RAIZ.'/ab/00000000-0000-4000-8000-000000000000.pdf'), 'No se borra ningún huérfano');
    }

    public function test_el_comando_detecta_mas_de_una_vigente_por_participante(): void
    {
        [, $e] = $this->lotesConEmisiones(2);
        // Se fuerza (solo en la prueba) una segunda vigente para el mismo participante quitando el índice único
        Schema::table('cf_emisiones', fn ($t) => $t->dropUnique(['participante_vigente']));
        DB::table('cf_emisiones')->insert(array_merge((array) DB::table('cf_emisiones')->where('id', $e[0]->id)->first(), ['id' => null, 'codigo' => 'DUPLICADA'.str_repeat('0', 11), 'version' => 2]));

        $this->artisan('credential-flow:verificar-emisiones')->expectsOutputToContain('más de una emisión vigente')->assertFailed();
    }
}
