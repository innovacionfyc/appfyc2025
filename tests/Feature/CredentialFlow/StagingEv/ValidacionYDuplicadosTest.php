<?php

namespace Tests\Feature\CredentialFlow\StagingEv;

use Illuminate\Support\Facades\DB;

/** Clasificación de participantes (documento, correo, tipo) y de grupos duplicados. Datos sintéticos. */
class ValidacionYDuplicadosTest extends StagingEvTestCase
{
    private function fila(int $id): object
    {
        return DB::table('stg_ev_participante')->where('old_id', $id)->first();
    }

    /** @return array<int,string> */
    private function codigos(int $id): array
    {
        $m = $this->fila($id)->motivo;

        return $m === null ? [] : explode(',', $m);
    }

    public function test_participante_sano_queda_ok_pero_sus_avisos_se_registran(): void
    {
        $this->cargar('s1');

        $p = $this->fila(1);
        $this->assertSame('valido', $p->documento_estado);
        $this->assertSame('valido', $p->correo_estado);
        $this->assertSame('ok', $p->validacion);
        $this->assertNull($p->motivo);
        $this->assertSame('1.000.001', $p->documento_impreso);
    }

    public function test_documentos_anomalos_van_a_revision_documento_y_no_se_corrigen(): void
    {
        $this->cargar('s1');

        $casos = [4 => 'DOC_LETRAS', 5 => 'DOC_VACIO', 6 => 'DOC_SEPARADORES', 15 => 'DOC_VACIO'];
        foreach ($casos as $id => $detalle) {
            $this->assertContains('REVISION_DOCUMENTO', $this->codigos($id), "participante $id");
            $this->assertContains($detalle, $this->codigos($id), "participante $id");
            $this->assertSame('error', $this->fila($id)->validacion);
        }
        // Lo guardado es lo original: nada se «arregla».
        $this->assertSame('ABC123', $this->fila(4)->documento_original);
        $this->assertSame('73.156.827', $this->fila(6)->documento_original);
        $this->assertSame('', $this->fila(5)->documento_original);
        // El documento impreso refleja lo que haría el sistema viejo (se pierde todo tras el primer punto).
        $this->assertSame('73', $this->fila(6)->documento_impreso);
        $this->assertNull($this->fila(4)->documento_impreso);
    }

    public function test_correo_invalido_sin_correo_y_tipo_vacio_son_advertencias(): void
    {
        $this->cargar('s1');

        $this->assertSame('invalido', $this->fila(2)->correo_estado);
        $this->assertContains('CORREO_INVALIDO', $this->codigos(2));
        $this->assertSame('advertencia', $this->fila(2)->validacion);

        $this->assertSame('sin_correo', $this->fila(3)->correo_estado);
        $this->assertContains('SIN_CORREO', $this->codigos(3));
        $this->assertSame('sin_correo', $this->fila(5)->correo_estado, 'Cadena vacía = sin correo');

        $this->assertNull($this->fila(4)->tipo_documento);
        $this->assertContains('TIPO_VACIO', $this->codigos(4));
        $this->assertSame('CC', $this->fila(1)->tipo_documento);
    }

    public function test_duplicado_identico_se_reporta_y_no_se_consolida(): void
    {
        $this->cargar('s1');

        $g = DB::table('stg_ev_duplicados')->where('old_evento_id', 1)->where('clasificacion', 'identico')->first();
        $this->assertNotNull($g);
        $this->assertSame(2, (int) $g->filas);
        $this->assertNull($g->etiquetas);
        $this->assertSame([7, 8], json_decode($g->old_ids, true));
        foreach ([7, 8] as $id) {
            $this->assertSame((int) $g->id, (int) $this->fila($id)->grupo_duplicado_id);
            $this->assertContains('DUPLICADO_IDENTICO', $this->codigos($id));
            $this->assertSame('advertencia', $this->fila($id)->validacion);
        }
        $this->assertSame(15, DB::table('stg_ev_participante')->count(), 'No se consolida ni se borra ninguna fila');
    }

    public function test_duplicado_conflictivo_mantiene_todas_las_filas_y_las_etiquetas(): void
    {
        $this->cargar('s1');

        $porNombre = DB::table('stg_ev_duplicados')->whereJsonContains('old_ids', 9)->first();
        $this->assertSame('conflictivo', $porNombre->clasificacion);
        $this->assertSame('DIF_NOMBRE', $porNombre->etiquetas);
        $this->assertNull($porNombre->subtipo);

        $porTipo = DB::table('stg_ev_duplicados')->whereJsonContains('old_ids', 13)->first();
        $this->assertSame('DIF_TIPO', $porTipo->etiquetas);

        foreach ([9, 10, 13, 14] as $id) {
            $this->assertContains('DUPLICADO_CONFLICTIVO', $this->codigos($id));
            $this->assertSame('error', $this->fila($id)->validacion);
            $this->assertNotNull($this->fila($id)->grupo_duplicado_id);
        }
    }

    public function test_conflicto_solo_por_codigo_null_contra_valor_sigue_siendo_conflictivo_con_subtipo(): void
    {
        $this->cargar('s1');

        $g = DB::table('stg_ev_duplicados')->whereJsonContains('old_ids', 11)->first();
        $this->assertSame('conflictivo', $g->clasificacion, 'Sigue siendo conflictivo hasta decidir otra cosa');
        $this->assertSame('DIF_VERIF', $g->etiquetas);
        $this->assertSame('SOLO_VERIF_NULL_VS_VALOR', $g->subtipo);
        $this->assertSame('error', $this->fila(11)->validacion);
        $this->assertSame('error', $this->fila(12)->validacion);
    }

    public function test_dos_codigos_distintos_no_son_el_subtipo_null_contra_valor(): void
    {
        $datos = $this->conCambio($this->datos(), 'participante', 12, ['num_verificacion' => 206]);
        $this->cargar('s1', $datos);

        $g = DB::table('stg_ev_duplicados')->whereJsonContains('old_ids', 11)->first();
        $this->assertSame('DIF_VERIF', $g->etiquetas);
        $this->assertNull($g->subtipo);
    }

    public function test_documentos_vacios_no_forman_grupos_de_duplicados(): void
    {
        $this->cargar('s1');

        $this->assertNull($this->fila(5)->grupo_duplicado_id);
        $this->assertNull($this->fila(15)->grupo_duplicado_id);
        $this->assertFalse(DB::table('stg_ev_duplicados')->get()->contains(fn ($g) => in_array(5, json_decode($g->old_ids, true), true)));
    }

    public function test_el_mismo_documento_en_otro_evento_no_es_duplicado(): void
    {
        $datos = $this->datos();
        $datos['participante'][] = ['id' => 50, 'tipo_documento' => 'CC', 'documento' => '1000001', 'nombre' => 'ANA UNO PRUEBA', 'correo' => 'ana.uno@example.test', 'id_evento' => 2, 'num_verificacion' => null];
        $this->cargar('s1', $datos);

        $this->assertNull($this->fila(50)->grupo_duplicado_id);
        $this->assertNull($this->fila(1)->grupo_duplicado_id);
    }

    public function test_el_formato_del_documento_cuenta_solo_si_cambia_lo_que_se_imprime(): void
    {
        $datos = $this->datos();
        // «2000001» y « 2000001 » se imprimen igual: el grupo sigue siendo idéntico.
        $datos = $this->conCambio($datos, 'participante', 8, ['documento' => ' 2000001 ']);
        $this->cargar('s1', $datos);
        $this->assertSame('identico', DB::table('stg_ev_duplicados')->whereJsonContains('old_ids', 7)->value('clasificacion'));

        // «2.000.001» tiene la misma clave de documento pero el sistema viejo lo imprimía «2»: cambia lo impreso.
        $datos = $this->conCambio($datos, 'participante', 8, ['documento' => '2.000.001']);
        $this->artisan('credential-flow:staging-ev:limpiar', ['--confirmar' => true])->assertExitCode(0);
        $this->cargar('s2', $datos);
        $g = DB::table('stg_ev_duplicados')->whereJsonContains('old_ids', 7)->first();
        $this->assertSame('conflictivo', $g->clasificacion);
        $this->assertSame('DIF_DOCUMENTO', $g->etiquetas, '«2.000.001» se imprimía «2», no «2.000.001»');
    }

    public function test_una_fila_ausente_sale_de_los_grupos_de_duplicados(): void
    {
        $this->cargar('s1');
        $this->assertNotNull($this->fila(8)->grupo_duplicado_id);

        $this->cargar('s2', $this->sinFila($this->datos(), 'participante', 8));

        $this->assertNull(DB::table('stg_ev_duplicados')->whereJsonContains('old_ids', 7)->first(), 'Ya no hay dos filas vigentes');
        $this->assertNull($this->fila(7)->grupo_duplicado_id);
        $this->assertSame('ausente_en_origen', $this->fila(8)->estado_fila);
    }

    public function test_banderas_de_descargas_tokens_y_encuestas(): void
    {
        $this->cargar('s1');

        $d = fn (int $id) => DB::table('stg_ev_descargas')->where('old_id', $id)->first();
        $this->assertTrue((bool) $d(1)->es_primera);
        $this->assertFalse((bool) $d(2)->es_primera, 'La segunda descarga del mismo participante no es la primera');
        $this->assertFalse((bool) $d(4)->participante_existe);
        $this->assertContains('SIN_PARTICIPANTE', explode(',', (string) $d(4)->motivo));
        $this->assertFalse((bool) $d(5)->evento_coincide, 'Descarga con otro evento que el del participante');
        $this->assertSame(2, (int) $this->fila(1)->descargas_count);
        $this->assertSame('2026-01-01 10:00:00', $this->fila(1)->primera_descarga_at);

        $t = fn (int $id) => DB::table('stg_ev_token')->where('old_id', $id)->first();
        $this->assertTrue((bool) $t(1)->codigo_repetido);
        $this->assertTrue((bool) $t(2)->codigo_repetido);
        $this->assertFalse((bool) $t(3)->codigo_repetido);
        $this->assertFalse((bool) $t(3)->participante_existe);

        $e = fn (int $id) => DB::table('stg_ev_encuesta')->where('old_id', $id)->first();
        $this->assertTrue((bool) $e(1)->participante_existe);
        $this->assertTrue((bool) $e(3)->participante_existe);
        $this->assertFalse((bool) $e(2)->evento_existe);
        $this->assertFalse((bool) $e(2)->participante_existe);
    }
}
