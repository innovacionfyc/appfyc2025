<?php

namespace Tests\Feature\CredentialFlow\StagingEv;

use App\Support\CredentialFlow\StagingEv\ReporteConciliacion;
use Illuminate\Support\Facades\DB;

/**
 * Regla de whitespace de documentos y correos candidatos múltiples en el staging. Todo sintético: ni un documento ni una
 * dirección de estas pruebas pertenece a una persona real (dominio reservado example.test).
 */
class DocumentosYCorreosTest extends StagingEvTestCase
{
    /** @return array<string,array<int,array<string,mixed>>> */
    private function conExtras(array $filas): array
    {
        $datos = $this->datos();
        foreach ($filas as $id => $f) {
            $datos['participante'][] = array_merge(['id' => $id, 'tipo_documento' => 'CC', 'documento' => '9'.$id, 'nombre' => "PERSONA $id", 'correo' => null, 'id_evento' => 1, 'num_verificacion' => null], $f);
        }

        return $datos;
    }

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

    public function test_documentos_con_blancos_validos_se_marcan_y_se_conservan_intactos(): void
    {
        $this->cargar('s1', $this->conExtras([
            200 => ['documento' => "3000200\n"],
            201 => ['documento' => "\t3000201"],
            202 => ['documento' => "3000202\r\n"],
            203 => ['documento' => ' 3000203 '],
        ]));

        foreach ([200 => "3000200\n", 201 => "\t3000201", 202 => "3000202\r\n", 203 => ' 3000203 '] as $id => $original) {
            $f = $this->fila($id);
            $this->assertSame($original, $f->documento_original, 'El original no se toca');
            $this->assertSame('valido', $f->documento_estado);
            $this->assertTrue((bool) $f->documento_normalizado_ws);
            $this->assertNotNull($f->documento_whitespace);
            $this->assertContains('DOCUMENTO_NORMALIZADO_WHITESPACE', $this->codigos($id));
            $this->assertNotContains('REVISION_DOCUMENTO', $this->codigos($id));
            $this->assertSame('advertencia', $f->validacion, 'La marca es un aviso, no un bloqueo');
            $this->assertSame('3000'.substr((string) $id, 0), $f->documento_clave);
        }
        $this->assertSame('lf@borde', $this->fila(200)->documento_whitespace);
        $this->assertSame('cr@borde,lf@borde', $this->fila(202)->documento_whitespace);
    }

    public function test_documentos_cuyo_blanco_cambiaba_lo_impreso_o_que_siguen_anomalos_van_a_revision(): void
    {
        $this->cargar('s1', $this->conExtras([
            210 => ['documento' => '12 345'],
            211 => ['documento' => "\u{00A0}1234567"],
            212 => ['documento' => 'AB 123'],
            213 => ['documento' => " 73.156.827\n"],
        ]));

        foreach ([210 => 'DOC_WHITESPACE_CAMBIA_IMPRESION', 211 => 'DOC_WHITESPACE_CAMBIA_IMPRESION', 212 => 'DOC_LETRAS', 213 => 'DOC_SEPARADORES'] as $id => $detalle) {
            $this->assertContains('REVISION_DOCUMENTO', $this->codigos($id), "participante $id");
            $this->assertContains($detalle, $this->codigos($id), "participante $id");
            $this->assertNotContains('DOCUMENTO_NORMALIZADO_WHITESPACE', $this->codigos($id));
            $this->assertSame('error', $this->fila($id)->validacion);
            $this->assertFalse((bool) $this->fila($id)->documento_normalizado_ws);
        }
        $this->assertSame('12 345', $this->fila(210)->documento_original);
    }

    public function test_el_blanco_no_crea_ni_oculta_duplicados(): void
    {
        $this->cargar('s1', $this->conExtras([
            220 => ['documento' => '3000220', 'nombre' => 'MISMO NOMBRE'],
            221 => ['documento' => "3000220\n", 'nombre' => 'MISMO NOMBRE'],
        ]));

        $g = DB::table('stg_ev_duplicados')->whereJsonContains('old_ids', 220)->first();
        $this->assertNotNull($g, 'Misma clave de documento: forman un grupo');
        $this->assertSame('identico', $g->clasificacion, 'Imprimían lo mismo y nada más difiere');
    }

    public function test_correos_multiples_se_guardan_como_coleccion_sin_elegir_uno(): void
    {
        $this->cargar('s1', $this->conExtras([
            300 => ['correo' => 'a@example.test;b@example.test'],
            301 => ['correo' => 'c@example.test, d@example.test'],
            302 => ['correo' => 'f@example.test;g@@example.test'],
            303 => ['correo' => 'H@Example.test; h@example.TEST'],
            304 => ['correo' => 'unico@example.test'],
            305 => ['correo' => 'x@example.test;'],
            306 => ['correo' => 'Pérez, Juan'],
            307 => ['correo' => ' '],
        ]));

        $m = $this->fila(300);
        $this->assertSame('a@example.test;b@example.test', $m->correo_original, 'El original se conserva');
        $this->assertSame('multiple', $m->correo_estado);
        $this->assertNull($m->correo_normalizado, 'Con varios válidos no se elige ninguno');
        $this->assertSame([2, 2], [(int) $m->correos_candidatos, (int) $m->correos_validos]);
        $this->assertContains('CORREO_MULTIPLE', $this->codigos(300));
        $this->assertSame('multiple', $this->fila(301)->correo_estado);

        $col = fn (int $id) => DB::table('stg_ev_participante_correos')->where('participante_old_id', $id)->orderBy('orden')->get(['orden', 'correo_normalizado', 'estado', 'correo_sha256']);
        $this->assertSame(['a@example.test', 'b@example.test'], $col(300)->pluck('correo_normalizado')->all());
        $this->assertSame([1, 2], $col(300)->pluck('orden')->map(fn ($x) => (int) $x)->all());
        $this->assertSame(hash('sha256', 'a@example.test'), $col(300)->first()->correo_sha256);

        // Válido + inválido: exactamente un válido → ese es el utilizable, y queda marcado el fragmento inválido.
        $mezcla = $this->fila(302);
        $this->assertSame(['valido', 'f@example.test', 2, 1], [$mezcla->correo_estado, $mezcla->correo_normalizado, (int) $mezcla->correos_candidatos, (int) $mezcla->correos_validos]);
        $this->assertContains('CORREO_FRAGMENTOS_INVALIDOS', $this->codigos(302));
        $this->assertSame(['valido', 'invalido'], $col(302)->pluck('estado')->all());

        // Duplicado con distinto casing: un solo candidato.
        $this->assertSame(1, $col(303)->count());
        $this->assertSame('valido', $this->fila(303)->correo_estado);
        $this->assertSame('h@example.test', $this->fila(303)->correo_normalizado);

        $this->assertSame(['valido', 'unico@example.test'], [$this->fila(304)->correo_estado, $this->fila(304)->correo_normalizado]);
        $this->assertSame('valido', $this->fila(305)->correo_estado);
        $this->assertSame('invalido', $this->fila(306)->correo_estado);
        $this->assertNull($this->fila(306)->correo_normalizado);
        $this->assertSame('sin_correo', $this->fila(307)->correo_estado);
        $this->assertSame(0, $col(307)->count());
    }

    public function test_la_coleccion_se_reconstruye_igual_en_cada_carga(): void
    {
        $datos = $this->conExtras([300 => ['correo' => 'a@example.test;b@example.test']]);
        $this->cargar('s1', $datos);
        $antes = DB::table('stg_ev_participante_correos')->orderBy('participante_old_id')->orderBy('orden')->get(['participante_old_id', 'orden', 'correo_sha256', 'estado'])->toArray();

        $this->cargar('s2', $datos);
        $despues = DB::table('stg_ev_participante_correos')->orderBy('participante_old_id')->orderBy('orden')->get(['participante_old_id', 'orden', 'correo_sha256', 'estado'])->toArray();

        $this->assertEquals($antes, $despues);
        $this->assertSame(1, DB::table('stg_ev_participante_correos')->where('participante_old_id', 300)->where('orden', 1)->count(), 'Nada se duplica');

        // Si cambia el correo, la colección cambia con él.
        $this->cargar('s3', $this->conCambio($datos, 'participante', 300, ['correo' => 'solo@example.test']));
        $this->assertSame(['solo@example.test'], DB::table('stg_ev_participante_correos')->where('participante_old_id', 300)->pluck('correo_normalizado')->all());
    }

    public function test_dos_filas_del_mismo_grupo_con_listas_de_correos_distintas_difieren_en_correo(): void
    {
        $this->cargar('s1', $this->conExtras([
            400 => ['documento' => '3000400', 'nombre' => 'IGUAL', 'correo' => 'a@example.test;b@example.test'],
            401 => ['documento' => '3000400', 'nombre' => 'IGUAL', 'correo' => 'a@example.test;c@example.test'],
            402 => ['documento' => '3000402', 'nombre' => 'IGUAL', 'correo' => 'a@example.test;b@example.test'],
            403 => ['documento' => '3000402', 'nombre' => 'IGUAL', 'correo' => 'B@example.test, A@example.test'],
        ]));

        $distintas = DB::table('stg_ev_duplicados')->whereJsonContains('old_ids', 400)->first();
        $this->assertSame('conflictivo', $distintas->clasificacion, 'Ambas son «multiple» (NULL), pero las listas difieren');
        $this->assertSame('DIF_CORREO', $distintas->etiquetas);

        $mismas = DB::table('stg_ev_duplicados')->whereJsonContains('old_ids', 402)->first();
        $this->assertSame('identico', $mismas->clasificacion, 'Mismos candidatos en otro orden y casing: iguales');
    }

    public function test_estadisticas_de_correos_sin_datos_personales(): void
    {
        $this->cargar('s1', $this->conExtras([
            300 => ['correo' => 'a@example.test;b@example.test', 'documento' => '5000001'],
            301 => ['correo' => 'c@example.test;d@@example.test', 'documento' => '5000002'],
            302 => ['correo' => 'uno@@x;dos@@y', 'documento' => '5000003'],
            // Mismo documento en otro evento con otro correo válido → documento con más de un correo válido.
            303 => ['correo' => 'e@example.test', 'documento' => '5000004', 'id_evento' => 1],
            304 => ['correo' => 'f@example.test', 'documento' => '5000004', 'id_evento' => 2],
            305 => ['correo' => 'g@example.test;h@example.test;i@example.test', 'documento' => '5000005'],
        ]));

        $r = (new ReporteConciliacion)->generar();
        $c = $r['correos'];

        $this->assertSame(4, $c['filas_con_multiples_candidatos']);
        $this->assertSame(2, $c['multiples_todos_validos'], 'Filas 300 y 305');
        $this->assertSame(1, $c['multiples_mezcla_valido_e_invalido'], 'Fila 301');
        $this->assertSame(1, $c['multiples_sin_ninguno_valido'], 'Fila 302');
        // Documentos con más de un correo válido: 5000001 (a, b), 5000004 (e, f en dos eventos) y 5000005 (g, h, i).
        $this->assertSame(3, $c['documentos_con_mas_de_un_correo_valido']);
        $this->assertSame(3, $c['maximo_correos_validos_por_documento']);
        $this->assertSame(1, $c['documentos_segun_cantidad_de_correos_validos']['3']);
        $this->assertSame($c['candidatas_total'], $c['candidatas_validas'] + $c['candidatas_invalidas']);

        $texto = ReporteConciliacion::aTexto($r);
        $json = json_encode($r);
        foreach (['@example.test', 'a@', 'uno@@x', 'g@example'] as $dato) {
            $this->assertStringNotContainsString($dato, $texto);
            $this->assertStringNotContainsString($dato, $json);
        }
        $this->assertSame(64, strlen($r['huella_derivada']));
    }

    public function test_la_huella_derivada_cambia_con_las_reglas_y_la_de_contenido_no(): void
    {
        $this->cargar('s1');
        $a = (new ReporteConciliacion)->generar();
        // Mismo origen: solo cambia una columna derivada manualmente (simula un cambio de regla).
        DB::table('stg_ev_participante')->where('old_id', 1)->update(['correo_estado' => 'multiple']);
        $b = (new ReporteConciliacion)->generar();

        $this->assertSame($a['huella_contenido'], $b['huella_contenido'], 'El contenido de origen no cambió');
        $this->assertNotSame($a['huella_derivada'], $b['huella_derivada'], 'Pero lo derivado sí');
    }
}
