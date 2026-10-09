<?php

namespace Tests\Feature\CredentialFlow\StagingEv;

use App\Support\CredentialFlow\StagingEv\EscanerImagenes;
use App\Support\CredentialFlow\StagingEv\ReporteConciliacion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ReporteConciliacionTest extends StagingEvTestCase
{
    /** @return array<string,mixed> */
    private function reporte(): array
    {
        $this->cargar('s1');

        return (new ReporteConciliacion)->generar();
    }

    public function test_los_conteos_coinciden_con_los_datos_sinteticos(): void
    {
        $r = $this->reporte();

        $this->assertSame(15, $r['entidades']['participante']['total']);
        $this->assertSame(6, $r['entidades']['evento']['total']);
        $this->assertSame(15, $r['participantes']['total']);

        // Documentos: válidos, vacíos (5 y 15) y anómalos (4: letras, 6: separadores).
        $this->assertSame(['vacio' => 2, 'anomalo' => 2, 'REVISION_DOCUMENTO' => 4], array_intersect_key($r['participantes']['documento'], array_flip(['vacio', 'anomalo', 'REVISION_DOCUMENTO'])));
        $this->assertSame(['letras' => 1, 'separadores' => 1], $r['participantes']['documento']['anomalo_detalle']);
        $this->assertSame(1, $r['participantes']['correo']['invalido']);
        $this->assertSame(['CC' => 12, 'CE' => 2, '(sin tipo)' => 1], $r['participantes']['tipo_documento']);

        // Duplicados: 4 grupos con documento (7-8 idéntico; 9-10, 11-12 y 13-14 conflictivos).
        $d = $r['duplicados'];
        $this->assertSame([4, 8, 1, 3], [$d['grupos'], $d['filas_en_grupos'], $d['identicos'], $d['conflictivos']]);
        $this->assertSame(1, $d['filas_sobrantes_si_se_consolidaran_los_identicos']);
        $this->assertSame(['DIF_NOMBRE' => 1, 'DIF_TIPO' => 1, 'DIF_DOCUMENTO' => 0, 'DIF_CORREO' => 0, 'DIF_VERIF' => 1], $d['etiquetas_en_conflictivos']);
        $this->assertSame(['SOLO_VERIF_NULL_VS_VALOR' => 1], $d['subtipos']);

        // Código legado: participantes 1, 7, 8, 11 tienen código (100, 101, 101, 205).
        $c = $r['codigo_legado'];
        $this->assertSame([4, 11, 100, 205, 3], [$c['con_codigo'], $c['sin_codigo'], $c['minimo'], $c['maximo'], $c['distintos']]);
        $this->assertSame(0, $c['codigos_compartidos_entre_distintos_participantes']);
        $this->assertSame(1, $c['codigos_repetidos_en_varias_filas']);
        $this->assertSame(1, $c['con_descarga_y_sin_codigo'], 'El participante 3 descargó y no tiene código');
        $this->assertSame(2, $c['con_codigo_y_sin_descarga'], 'Participantes 8 y 11');
        $this->assertSame(0, $c['con_codigo_y_sin_descarga_fuera_de_duplicados']);
        $this->assertSame('NO_CONFIRMADA', $c['hipotesis_codigo_se_asigna_en_la_primera_descarga']);

        // Descargas y tokens.
        $this->assertSame(5, $r['descargas']['total']);
        $this->assertSame(1, $r['descargas']['sin_participante']);
        $this->assertSame(1, $r['descargas']['evento_no_coincide_con_el_del_participante']);
        $this->assertSame(['1' => 3, '2' => 1, '3_o_mas' => 0], $r['descargas']['participantes_segun_cantidad_de_descargas']);
        $this->assertSame(2, $r['descargas']['maximo_descargas_de_un_participante']);
        $this->assertSame(4, $r['descargas']['primeras_descargas_marcadas']);
        $this->assertSame(3, $r['tokens']['total']);
        $this->assertSame(1, $r['tokens']['huerfanos_sin_participante']);
        $this->assertSame(2, $r['tokens']['codigos_repetidos']);
        $this->assertSame(3, $r['tokens']['destino_futuro_NO_MIGRAR']);

        // Encuestas.
        $this->assertSame(3, $r['encuestas']['total']);
        $this->assertSame(1, $r['encuestas']['sin_participante']);
        $this->assertSame(1, $r['encuestas']['sin_participante_y_evento_inexistente']);
        $this->assertSame(3, $r['encuestas']['preguntas']['pregunta1']['igual_al_texto_de_alguna_opcion']);
        $this->assertSame(0, $r['encuestas']['preguntas']['pregunta1']['igual_al_id_de_alguna_opcion']);
        $this->assertSame(3, $r['encuestas']['preguntas']['pregunta5']['con_texto']);
        $this->assertSame(0, $r['encuestas']['preguntas']['pregunta8']['con_texto']);
        $this->assertSame(0, $r['encuestas']['justificacion_pregunta1']['no_nulos']);
    }

    public function test_la_hipotesis_del_codigo_se_evalua_sobre_los_datos(): void
    {
        // Participante 1: código 100 (primera descarga 01-ene), 7: código 101 (03-ene), 3: código 102 (06-ene) → el código nunca baja.
        $this->cargar('s1', $this->conCambio($this->datos(), 'participante', 3, ['num_verificacion' => 102]));
        $c = (new ReporteConciliacion)->generar()['codigo_legado'];

        $this->assertSame(['comparados' => 3, 'violaciones_de_orden' => 0], $c['orden_por_primera_descarga']);
        $this->assertSame(0, $c['con_descarga_y_sin_codigo']);
        $this->assertSame('CONSISTENTE_CON_LOS_DATOS', $c['hipotesis_codigo_se_asigna_en_la_primera_descarga']);
    }

    public function test_si_los_codigos_no_siguen_el_orden_de_descarga_la_hipotesis_no_se_confirma(): void
    {
        $datos = $this->conCambio($this->datos(), 'participante', 7, ['num_verificacion' => 50]);   // código MENOR que el de una descarga anterior
        $datos = $this->conCambio($datos, 'participante', 8, ['num_verificacion' => 50]);
        $this->cargar('s1', $datos);

        $c = (new ReporteConciliacion)->generar()['codigo_legado'];
        $this->assertSame(1, $c['orden_por_primera_descarga']['violaciones_de_orden']);
    }

    public function test_el_reporte_nunca_contiene_datos_personales(): void
    {
        $this->cargar('s1');
        $r = (new ReporteConciliacion)->generar();
        $texto = ReporteConciliacion::aTexto($r);
        $json = json_encode($r, JSON_UNESCAPED_UNICODE);

        $prohibidos = [
            self::NOMBRE, 'PRUEBA', self::DOCUMENTO, '1000002', '2000001', '73.156.827', 'ABC123', self::CORREO, 'example.test',
            'tokensecreto', self::TEXTO_ENCUESTA, 'Excelente', 'GINA', 'HUGO', 'correo-invalido', 'Curso Alfa',
        ];
        foreach ($prohibidos as $dato) {
            $this->assertStringNotContainsString($dato, $texto, "El reporte en texto no debe contener «{$dato}»");
            $this->assertStringNotContainsString($dato, $json, "El reporte JSON no debe contener «{$dato}»");
        }
        // Tampoco el SHA-256 de ningún token ni de ningún documento.
        $this->assertStringNotContainsString(hash('sha256', self::TOKEN), $json);
    }

    public function test_el_comando_imprime_el_reporte_y_funciona_en_json(): void
    {
        $this->cargar('s1');

        $this->artisan('credential-flow:staging-ev:reporte')->assertExitCode(0)->expectsOutputToContain('REVISION_DOCUMENTO: 4');
        Artisan::call('credential-flow:staging-ev:reporte', ['--json' => true]);
        $json = json_decode(Artisan::output(), true);

        $this->assertSame(15, $json['participantes']['total']);
        $this->assertSame(64, strlen($json['huella_contenido']));
        $this->assertSame(hash('sha256', 's1'), $json['snapshot']['dump_sha256']);
    }

    public function test_la_huella_de_contenido_es_la_misma_al_recargar_y_cambia_si_cambia_un_dato(): void
    {
        $this->cargar('s1');
        $h1 = (new ReporteConciliacion)->generar()['huella_contenido'];
        $this->cargar('s2');
        $this->assertSame($h1, (new ReporteConciliacion)->generar()['huella_contenido']);

        $this->cargar('s3', $this->conCambio($this->datos(), 'participante', 3, ['nombre' => 'CARLA TRES CAMBIADA']));
        $this->assertNotSame($h1, (new ReporteConciliacion)->generar()['huella_contenido']);
    }

    public function test_el_reporte_detecta_los_casos_conocidos_sin_nombrarlos(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'stg_ev_rep_'.bin2hex(random_bytes(6));
        mkdir($dir);
        $im = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        file_put_contents($dir.'/ALFA 2024.png', $png);
        file_put_contents($dir.'/DELTA 2023', $png);
        file_put_contents($dir.'/ORFANA.png', $png.'o');
        file_put_contents($dir.'/GAMMA_.png', $png.'g');
        $this->cargar('s1', null, (new EscanerImagenes)->escanear($dir));
        File::deleteDirectory($dir);

        $r = (new ReporteConciliacion)->generar();
        $e = $r['eventos'];
        // Evento 1 tiene archivo; 2, 3 y 6 apuntan a archivos que no están; 4 existe pero sin extensión; 5 no referencia imagen.
        $this->assertSame([1, 1, 3, 1, 0], [$e['imagen_ok'], $e['sin_imagen'], $e['archivo_faltante'], $e['extension_invalida'], $e['no_renderizable']]);
        $this->assertSame([2, 3, 4, 5, 6], $e['ids_eventos_pendientes']);
        $this->assertSame(5, $e['pendientes_de_plantilla']);
        $this->assertSame([2, 1, 1], [$r['imagenes']['huerfanas'], $r['imagenes']['candidatas_revision'], count($r['imagenes']['candidatas_pares'])]);
        // Identificador estable (SHA-256 del archivo), no el id autoincremental de la fila.
        $this->assertSame(['sha256' => hash('sha256', $png.'g'), 'old_evento_id' => 3], $r['imagenes']['candidatas_pares'][0]);
        $this->assertSame(DB::table('stg_ev_imagenes')->where('nombre_original', 'GAMMA_.png')->value('sha256'), $r['imagenes']['candidatas_pares'][0]['sha256']);
    }

    public function test_el_reporte_es_identico_tras_limpiar_y_recargar_aunque_cambien_los_ids_autoincrementales(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'stg_ev_rep_'.bin2hex(random_bytes(6));
        mkdir($dir);
        $im = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        file_put_contents($dir.'/ALFA 2024.png', $png);
        file_put_contents($dir.'/GAMMA_.png', $png.'g');
        $imagenes = (new EscanerImagenes)->escanear($dir);
        File::deleteDirectory($dir);

        $this->cargar('s1', null, $imagenes);
        $primero = (new ReporteConciliacion)->generar();
        $this->assertNotSame([], $primero['imagenes']['candidatas_pares']);
        $idAntes = (int) DB::table('stg_ev_imagenes')->min('id');

        $this->artisan('credential-flow:staging-ev:limpiar', ['--confirmar' => true])->assertExitCode(0);
        $this->cargar('s1-recarga', null, $imagenes);

        // Los ids sustitutos SÍ cambian (el AUTO_INCREMENT no se reinicia)…
        $this->assertGreaterThan($idAntes, (int) DB::table('stg_ev_imagenes')->min('id'));
        // …pero el reporte entero, huellas incluidas, queda idéntico. Solo se excluye la identidad del snapshot: en este fixture
        // el SHA del dump deriva de la etiqueta, y en la carga real (mismo dump) es el mismo.
        $segundo = (new ReporteConciliacion)->generar();
        unset($primero['snapshot'], $segundo['snapshot']);
        $this->assertSame(json_encode($primero), json_encode($segundo));
    }
}
