<?php

namespace Tests\Feature\CredentialFlow\Excel;

use App\Support\CredentialFlow\Participantes\ErrorArchivoException;
use App\Support\CredentialFlow\Participantes\ErrorFila;
use App\Support\CredentialFlow\Participantes\Excel\AnalizadorFormula;
use App\Support\CredentialFlow\Participantes\Excel\CeldaLeida;
use App\Support\CredentialFlow\Participantes\Excel\LectorValoresCalculados;
use App\Support\CredentialFlow\Participantes\Excel\LibroLeido;
use App\Support\CredentialFlow\Participantes\Excel\MarcasXlsx;
use App\Support\CredentialFlow\Participantes\LectorArchivo;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use Tests\Feature\CredentialFlow\Support\XlsxSintetico as X;
use Tests\TestCase;

/**
 * F0 de la importación inteligente: lector auxiliar de valores calculados. Archivos 100 % sintéticos que imitan la estructura del formato F-SC-DC-08 exportado de
 * Google Sheets (fórmulas IFERROR(__xludf.DUMMYFUNCTION(…), valor) con o sin resultado guardado, filas de relleno hasta el final de la hoja…).
 */
class LectorValoresCalculadosTest extends TestCase
{
    private function archivo(string $bytes, string $nombre = 'solicitud.xlsx'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nombre, $bytes);
    }

    /** @param  array<string,mixed>  $celdas */
    private function leer(array $celdas, array $extra = [], ?LectorValoresCalculados $lector = null): LibroLeido
    {
        return ($lector ?? new LectorValoresCalculados)->leer($this->archivo(X::libro([['nombre' => 'Hoja', 'celdas' => $celdas]], $extra)));
    }

    private function celda(LibroLeido $l, string $ref, string $hoja = 'Hoja'): CeldaLeida
    {
        preg_match('/^([A-Z]+)(\d+)$/', $ref, $m);
        $c = $l->hoja($hoja)->celda((int) $m[2], ord($m[1]) - 65);
        $this->assertNotNull($c, "No hay celda $ref");

        return $c;
    }

    private function iferror(string $reserva, string $original = 'IMPORTRANGE(D10,"Hoja!A1")'): string
    {
        return '=IFERROR(__xludf.DUMMYFUNCTION("'.str_replace('"', '""', $original).'"),'.$reserva.')';
    }

    // ── Celdas que no son fórmulas ────────────────────────────────────────────

    public function test_celdas_literales_de_cada_tipo(): void
    {
        $l = $this->leer(['A1' => 'Texto con ñ y tilde', 'B1' => 46311, 'C1' => 3.5, 'D1' => true, 'E1' => ['v' => '46311', 's' => 1]]);

        $this->assertSame(['Texto con ñ y tilde', CeldaLeida::TEXTO, CeldaLeida::LITERAL], [$this->celda($l, 'A1')->valor, $this->celda($l, 'A1')->tipo, $this->celda($l, 'A1')->origen]);
        $this->assertSame([46311, CeldaLeida::NUMERO], [$this->celda($l, 'B1')->valor, $this->celda($l, 'B1')->tipo]);
        $this->assertSame([3.5, CeldaLeida::NUMERO], [$this->celda($l, 'C1')->valor, $this->celda($l, 'C1')->tipo]);
        $this->assertSame([true, CeldaLeida::BOOLEANO], [$this->celda($l, 'D1')->valor, $this->celda($l, 'D1')->tipo]);
        $this->assertSame(['2026-10-16', CeldaLeida::FECHA], [$this->celda($l, 'E1')->valor, $this->celda($l, 'E1')->tipo]);
        $this->assertSame(0, $l->resumen()['formulas']);
    }

    // ── Fórmulas con resultado guardado ───────────────────────────────────────

    public function test_la_formula_con_resultado_guardado_usa_ese_resultado(): void
    {
        $l = $this->leer([
            'A1' => ['f' => substr($this->iferror('"reserva distinta"'), 1), 't' => 'str', 'v' => "Módulo\nLey 2605"],
            'B1' => ['f' => substr($this->iferror('4.63857'), 1), 't' => 'n', 'v' => '1057784083'],
        ]);

        $a = $this->celda($l, 'A1');
        $this->assertSame(["Módulo\nLey 2605", CeldaLeida::FORMULA_GUARDADA, 'IFERROR', true, true], [$a->valor, $a->origen, $a->funcion, $a->importrange, $a->discrepancia], 'Gana el guardado y se avisa de la diferencia con la reserva');
        $this->assertSame([1057784083, CeldaLeida::NUMERO], [$this->celda($l, 'B1')->valor, $this->celda($l, 'B1')->tipo]);
    }

    public function test_un_resultado_guardado_igual_al_de_reserva_no_es_discrepancia_y_las_fechas_se_comparan_con_su_serie(): void
    {
        $l = $this->leer([
            'A1' => ['f' => substr($this->iferror('"Igual"'), 1), 't' => 'str', 'v' => 'Igual'],
            'B1' => ['f' => substr($this->iferror('46311'), 1), 's' => 1, 'v' => '46311'],   // guardado como fecha, reserva como número de serie
        ]);

        $this->assertFalse($this->celda($l, 'A1')->discrepancia);
        $this->assertSame(['2026-10-16', CeldaLeida::FECHA, false], [$this->celda($l, 'B1')->valor, $this->celda($l, 'B1')->tipo, $this->celda($l, 'B1')->discrepancia]);
        $this->assertSame(0, $l->resumen()['discrepancias']);
    }

    // ── Fórmulas sin resultado guardado: valor de reserva ─────────────────────

    public function test_sin_resultado_guardado_se_recupera_el_valor_de_reserva_de_la_envoltura(): void
    {
        $l = $this->leer([
            'A1' => ['f' => substr($this->iferror('"Texto con ""comillas"" y salto'."\n".'de línea"'), 1)],
            'A2' => ['f' => substr($this->iferror('46311.5'), 1)],
            'A3' => ['f' => substr($this->iferror('TRUE'), 1)],
            'A4' => ['f' => substr($this->iferror('-12'), 1)],
            'A5' => ['f' => 'IFERROR(__xludf.DUMMYFUNCTION("""COMPUTED_VALUE"""),"Del programa de origen")'],
        ]);

        $this->assertSame(['Texto con "comillas" y salto'."\n".'de línea', CeldaLeida::FORMULA_RESERVA, CeldaLeida::TEXTO], [$this->celda($l, 'A1')->valor, $this->celda($l, 'A1')->origen, $this->celda($l, 'A1')->tipo]);
        $this->assertSame([46311.5, CeldaLeida::NUMERO], [$this->celda($l, 'A2')->valor, $this->celda($l, 'A2')->tipo]);
        $this->assertSame([true, CeldaLeida::BOOLEANO], [$this->celda($l, 'A3')->valor, $this->celda($l, 'A3')->tipo]);
        $this->assertSame(-12, $this->celda($l, 'A4')->valor);
        $this->assertSame(['Del programa de origen', CeldaLeida::FORMULA_RESERVA], [$this->celda($l, 'A5')->valor, $this->celda($l, 'A5')->origen]);
        $this->assertSame(5, $l->resumen()['recuperadas_reserva']);
    }

    // ── Vacías comprobadas, sin valor y errores ───────────────────────────────

    public function test_resultado_vacio_guardado_es_vacia_comprobada_y_la_ausencia_de_resultado_no(): void
    {
        $l = $this->leer([
            'A1' => 'Hay datos en esta fila',
            'B1' => ['f' => 'IF(ISBLANK(A1),"",1)', 't' => 'str', 'v' => ''],   // resultado vacío GUARDADO
            'C1' => ['f' => 'IF(ISBLANK(A1),"",1)', 't' => 'str'],                // sin <v>: NO hay resultado
        ]);

        $this->assertSame(CeldaLeida::FORMULA_VACIA, $this->celda($l, 'B1')->origen);
        $this->assertSame(CeldaLeida::FORMULA_SIN_VALOR, $this->celda($l, 'C1')->origen, 'Una fila con datos y una fórmula sin resultado: dato NO recuperable');
        $r = $l->resumen();
        $this->assertSame([1, 1, 0], [$r['vacias_resultado_vacio'], $r['sin_valor'], $r['vacias_por_contexto']]);
    }

    public function test_formula_sin_resultado_en_una_fila_sin_datos_es_relleno_vacio_por_contexto(): void
    {
        $l = $this->leer([
            'A1' => 'Encabezado',
            'B5' => ['f' => 'IF(ISBLANK(A5),"",1)', 't' => 'str'],                   // fila 5 sin ningún dato
            'C5' => ['f' => substr($this->iferror('""'), 1), 't' => 'str', 'v' => ''],
            'D5' => ['f' => 'A1+1', 't' => 'str', 'v' => ''],
        ]);

        $this->assertSame(CeldaLeida::FORMULA_VACIA_CONTEXTO, $this->celda($l, 'B5')->origen);
        $this->assertSame(CeldaLeida::FORMULA_VACIA, $this->celda($l, 'C5')->origen);
        $this->assertSame(CeldaLeida::FORMULA_VACIA, $this->celda($l, 'D5')->origen);
        $this->assertSame(0, $l->resumen()['sin_valor']);
    }

    public function test_el_resultado_guardado_que_es_un_error_se_informa_como_error(): void
    {
        $l = $this->leer([
            'A1' => ['f' => 'IMPORTRANGE("x","y")', 't' => 'e', 'v' => '#REF!'],
            'B1' => ['t' => 'e', 'v' => '#N/A'],                                       // error escrito, no fórmula
            'C1' => 'dato',
        ]);

        $a = $this->celda($l, 'A1');
        $this->assertSame([CeldaLeida::FORMULA_ERROR, '#REF!', null], [$a->origen, $a->error, $a->valor]);
        $this->assertSame([CeldaLeida::LITERAL, CeldaLeida::ERROR, '#N/A'], [$this->celda($l, 'B1')->origen, $this->celda($l, 'B1')->tipo, $this->celda($l, 'B1')->error]);
        $r = $l->resumen();
        $this->assertSame([1, 1], [$r['errores'], $r['errores_literales']]);
    }

    // ── Fórmulas hostiles y envolturas mal formadas ───────────────────────────

    public function test_las_formulas_peligrosas_nunca_se_ejecutan_ni_se_resuelven(): void
    {
        $l = $this->leer([
            'A1' => 'fila con datos',
            'B1' => ['f' => 'HYPERLINK("http://ejemplo.invalido/x","clic")'],
            'C1' => ['f' => 'IMPORTXML("http://ejemplo.invalido/a","//b")'],
            'D1' => ['f' => "cmd|' /C calc'!A0"],
            'E1' => ['f' => "'[externo.xlsx]Hoja1'!A1"],
            'F1' => ['f' => 'WEBSERVICE("http://ejemplo.invalido")'],
        ]);

        foreach (['B1', 'C1', 'D1', 'E1', 'F1'] as $ref) {
            $c = $this->celda($l, $ref);
            $this->assertSame([null, CeldaLeida::FORMULA_SIN_VALOR], [$c->valor, $c->origen], "$ref no debe resolverse");
        }
        $this->assertSame('HYPERLINK', $this->celda($l, 'B1')->funcion);
        $this->assertSame(5, $l->resumen()['sin_valor']);
    }

    public function test_las_envolturas_mal_formadas_no_producen_valor_de_reserva(): void
    {
        $casos = [
            'comilla sin cerrar' => '=IFERROR(__xludf.DUMMYFUNCTION("IMPORTRANGE(a,b)),"x")',
            'texto sobrante' => '=IFERROR(__xludf.DUMMYFUNCTION("a"),"x")&"y"',
            'otra función' => '=SI(__xludf.DUMMYFUNCTION("a"),"x")',
            'reserva es una referencia' => '=IFERROR(__xludf.DUMMYFUNCTION("a"),B2)',
            'reserva es una función' => '=IFERROR(__xludf.DUMMYFUNCTION("a"),SUM(A1:A2))',
            'sin paréntesis final' => '=IFERROR(__xludf.DUMMYFUNCTION("a"),"x"',
            'número malformado' => '=IFERROR(__xludf.DUMMYFUNCTION("a"),12abc)',
            'vacía' => '=',
        ];
        foreach ($casos as $nombre => $formula) {
            $this->assertNull(AnalizadorFormula::reserva($formula), $nombre);
        }
        $this->assertNull(AnalizadorFormula::reserva('='.str_repeat('(', 40000)), 'una fórmula enorme no se analiza');
        $this->assertNull(AnalizadorFormula::reserva('=IFERROR(__xludf.DUMMYFUNCTION("a"),"'.str_repeat('x', AnalizadorFormula::LITERAL_MAX + 1).'")'), 'reserva demasiado larga');
    }

    public function test_el_analizador_acepta_las_variantes_validas_de_la_envoltura(): void
    {
        $this->assertSame(['valor' => '', 'tipo' => 'vacio'], AnalizadorFormula::reserva('=IFERROR(__xludf.DUMMYFUNCTION("a"),"")'));
        $this->assertSame(['valor' => '', 'tipo' => 'vacio'], AnalizadorFormula::reserva('=IFERROR(__xludf.DUMMYFUNCTION("a"),)'));
        $this->assertSame(['valor' => 12, 'tipo' => 'numero'], AnalizadorFormula::reserva("=iferror( __xludf.dummyfunction( \"a\" ) ,\n12 )"));
        $this->assertSame(['valor' => 1.5e3, 'tipo' => 'numero'], AnalizadorFormula::reserva('=IFERROR(__xludf.DUMMYFUNCTION("a"),1.5E3)'));
        $this->assertSame(['valor' => false, 'tipo' => 'booleano'], AnalizadorFormula::reserva('=IFERROR(__xludf.DUMMYFUNCTION("a"),false)'));
        $this->assertSame(['valor' => 'a, "b" (c)', 'tipo' => 'texto'], AnalizadorFormula::reserva('=IFERROR(__xludf.DUMMYFUNCTION("IFERROR(x,""y"")"),"a, ""b"" (c)")'));
    }

    // ── Libro: hojas, límites y entradas malformadas ──────────────────────────

    public function test_lee_varias_hojas_e_identifica_las_ocultas(): void
    {
        $bytes = X::libro([
            ['nombre' => 'Solicitud', 'celdas' => ['A1' => 'visible']],
            ['nombre' => 'Auxiliar', 'estado' => 'hidden', 'celdas' => ['A1' => 'oculta', 'B1' => ['f' => substr($this->iferror('"x"'), 1)]]],
        ]);

        $l = (new LectorValoresCalculados)->leer($this->archivo($bytes));

        $this->assertSame(['Solicitud', 'Auxiliar'], array_map(fn ($h) => $h->nombre, $l->hojas));
        $this->assertSame([true, false], array_map(fn ($h) => $h->visible, $l->hojas));
        $this->assertSame('x', $l->hoja('Auxiliar')->celda(1, 1)->valor);
        $this->assertSame(2, $l->resumen()['hojas']);
    }

    public function test_los_topes_de_filas_y_celdas_truncan_sin_fallar(): void
    {
        $celdas = [];
        for ($i = 1; $i <= 30; $i++) {
            $celdas["A$i"] = "dato $i";
            $celdas["B$i"] = $i;
        }

        $porFilas = $this->leer($celdas, [], new LectorValoresCalculados(filasMax: 10));
        $porCeldas = $this->leer($celdas, [], new LectorValoresCalculados(celdasMax: 15));

        $this->assertSame(['filas', true], [$porFilas->hojas[0]->motivoTruncada, $porFilas->truncado()]);
        $this->assertSame(20, $porFilas->hojas[0]->cantidad());
        $this->assertSame(['celdas', 15], [$porCeldas->hojas[0]->motivoTruncada, $porCeldas->hojas[0]->cantidad()]);
        $this->assertTrue($porCeldas->resumen()['truncado']);
    }

    public function test_entradas_malformadas_se_rechazan_con_error_controlado(): void
    {
        $lector = new LectorValoresCalculados;
        $valido = X::libro([['nombre' => 'Hoja', 'celdas' => ['A1' => 'x']]]);

        $casos = [
            'no es un zip' => [$this->archivo('esto no es un libro'), ErrorFila::ARCHIVO_INVALIDO],
            'extensión xls' => [$this->archivo($valido, 'libro.xls'), ErrorFila::EXTENSION_NO_PERMITIDA],
            'extensión csv' => [$this->archivo("a,b\n1,2\n", 'libro.csv'), ErrorFila::EXTENSION_NO_PERMITIDA],
            'con macros' => [$this->archivo(X::libro([['nombre' => 'Hoja', 'celdas' => ['A1' => 'x']]], ['xl/vbaProject.bin' => 'macro'])), ErrorFila::ARCHIVO_CON_MACROS],
            'hoja con XML roto' => [$this->archivo(X::libro([['nombre' => 'Hoja', 'celdas' => ['A1' => 'x']]], ['xl/worksheets/sheet1.xml' => '<worksheet><sheetData><row><c r="A1"><v>'])), ErrorFila::ARCHIVO_CORRUPTO],
        ];
        foreach ($casos as $nombre => [$archivo, $codigo]) {
            try {
                $lector->leer($archivo);
                $this->fail("«{$nombre}» debía rechazarse.");
            } catch (ErrorArchivoException $e) {
                $this->assertSame($codigo, $e->codigo, $nombre);
            }
        }
    }

    public function test_marcas_devuelve_null_cuando_no_puede_verificar(): void
    {
        $bytes = X::libro([['nombre' => 'Hoja', 'celdas' => ['A1' => 'dato', 'B1' => ['f' => 'IF(1,"","")', 't' => 'str', 'v' => '']]]]);
        $this->assertNotNull(MarcasXlsx::localizar($this->guardar($bytes)));
        $this->assertNull(MarcasXlsx::localizar($this->guardar('no es un zip')));
    }

    public function test_marcas_ignora_entidades_externas_y_no_filtra_contenido(): void
    {
        $xxe = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/hostname">]><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="&e;" sheetId="1" r:id="rId1"/></sheets></workbook>';
        $bytes = X::libro([['nombre' => 'Hoja', 'celdas' => ['A1' => 'x']]], ['xl/workbook.xml' => $xxe]);

        $r = MarcasXlsx::localizar($this->guardar($bytes));

        $this->assertTrue($r === null || is_array($r));
        $this->assertStringNotContainsString(trim((string) @file_get_contents('/etc/hostname')) ?: 'imposible-coincidir', json_encode($r));
    }

    // ── Garantías transversales ───────────────────────────────────────────────

    public function test_el_resumen_no_contiene_datos_de_las_celdas(): void
    {
        $l = $this->leer(['A1' => 'SENTINELA-PERSONAL-123', 'B1' => ['f' => substr($this->iferror('"SENTINELA-RESERVA"'), 1)]]);

        $json = json_encode($l->resumen());

        $this->assertStringNotContainsString('SENTINELA', $json);
        $this->assertStringNotContainsString('IMPORTRANGE', $json);
    }

    public function test_el_importador_vigente_sigue_tratando_las_formulas_como_hasta_ahora(): void
    {
        $bytes = X::libro([['nombre' => 'Participantes', 'celdas' => ['A1' => 'nombre_completo', 'B1' => 'documento', 'A2' => 'Ana', 'B2' => ['f' => 'IF(1,"1","2")', 't' => 'str', 'v' => '1']]]]);

        $lectura = (new LectorArchivo)->leer($this->archivo($bytes));

        $this->assertTrue($lectura->filas[1]['celdas'][1]->formula, 'El lector del importador marca la fórmula y no usa su valor');
        $this->assertNull($lectura->filas[1]['celdas'][1]->valor);
    }

    public function test_diagnostico_de_un_libro_con_la_estructura_del_formato_real(): void
    {
        // Bloque de datos del evento arriba (todas fórmulas con valor guardado), tabla de participantes con fórmulas y relleno de filas vacías hasta el final.
        $celdas = [
            'B5' => 'Nombre del Evento',
            'D5' => ['f' => substr($this->iferror('"Evento ficticio"'), 1), 't' => 'str', 'v' => 'Evento ficticio'],
            'B7' => 'Fecha del Evento',
            'D7' => ['f' => substr($this->iferror('46311'), 1), 's' => 1, 'v' => '46311'],
            'B8' => 'Intensidad',
            'D8' => ['f' => substr($this->iferror('"Módulo 5 Horas"'), 1), 't' => 'str', 'v' => 'Módulo 5 Horas'],
            'B10' => 'Item', 'C10' => 'Identificación', 'D10' => 'Nombres y Apellidos',
        ];
        for ($i = 11; $i <= 40; $i++) {
            $conDatos = $i <= 13;
            $celdas["B$i"] = ['f' => 'IF(ISBLANK(C'.$i.'),"",B'.($i - 1).'+1)', 't' => $conDatos ? 'n' : 'str', 'v' => $conDatos ? (string) ($i - 10) : ''];
            $celdas["C$i"] = ['f' => substr($this->iferror($conDatos ? (string) (1000 + $i) : '""'), 1), 't' => $conDatos ? 'n' : 'str', 'v' => $conDatos ? (string) (1000 + $i) : ''];
            $celdas["D$i"] = ['f' => substr($this->iferror($conDatos ? '"Persona '.$i.'"' : '""'), 1), 't' => 'str', 'v' => $conDatos ? 'Persona '.$i : ''];
            $celdas["E$i"] = ['f' => substr($this->iferror('""'), 1), 't' => 'str'];   // sin resultado guardado, en filas con y sin datos
        }

        $r = $this->leer($celdas)->resumen();

        // 3 del bloque del evento + 30 filas × 4 fórmulas. Con datos (3 filas): B, C y D guardadas; E sin resultado pero con reserva vacía.
        // Relleno (27 filas): B, C y D con resultado vacío guardado; E con reserva vacía.
        $this->assertSame(123, $r['formulas']);
        $this->assertSame(3 + 3 * 3, $r['recuperadas_guardadas']);
        $this->assertSame(27 * 3 + 30, $r['vacias_resultado_vacio']);
        $this->assertSame([0, 0, 0, 0], [$r['vacias_por_contexto'], $r['sin_valor'], $r['errores'], $r['discrepancias']]);
        $this->assertSame(123, $r['recuperadas'] + $r['vacias_comprobadas'], 'Cada fórmula queda clasificada: ninguna sin resolver');
    }

    public function test_si_no_se_pudo_verificar_el_xml_un_resultado_vacio_no_cuenta_como_comprobado(): void
    {
        $lector = new LectorValoresCalculados;
        $resolver = new \ReflectionMethod($lector, 'resolver');

        // Sin marcas (inspección fallida): OpenSpout dice «vacío» pero no se sabe si hay un resultado guardado vacío o ninguno.
        $sinVerificar = $resolver->invoke($lector, new FormulaCell('=A1+1', ''), 3, 2, null, false);
        $this->assertSame(CeldaLeida::FORMULA_SIN_VALOR, $sinVerificar->origen);

        // Con marcas que confirman un <v></v> de tipo texto: vacía comprobada.
        $verificada = $resolver->invoke($lector, new FormulaCell('=A1+1', ''), 3, 2, ['codigo' => null, 'formula' => true, 'v' => true, 'vacio' => true], true);
        $this->assertSame(CeldaLeida::FORMULA_VACIA, $verificada->origen);

        // Con marcas que confirman que NO hay <v>: sin valor.
        $ausente = $resolver->invoke($lector, new FormulaCell('=A1+1', ''), 3, 2, ['codigo' => null, 'formula' => true, 'v' => false, 'vacio' => false], true);
        $this->assertSame(CeldaLeida::FORMULA_SIN_VALOR, $ausente->origen);
    }

    public function test_celdas_y_filas_sin_atributo_de_posicion_se_alinean_con_su_resultado_guardado(): void
    {
        // El atributo r es opcional en el formato: cada celda ocupa la columna siguiente y cada fila la siguiente.
        $hoja = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row><c t="inlineStr"><is><t>dato</t></is></c><c t="str"><f>IF(1,"","")</f><v></v></c><c t="str"><f>IF(1,"x","x")</f></c></row>'
            .'<row><c t="inlineStr"><is><t>otro</t></is></c><c t="n"><f>1+1</f><v>2</v></c></row></sheetData></worksheet>';

        $l = $this->leer(['A1' => 'sustituida'], ['xl/worksheets/sheet1.xml' => $hoja]);

        $this->assertSame(CeldaLeida::FORMULA_VACIA, $this->celda($l, 'B1')->origen, 'resultado vacío guardado, en la posición correcta');
        $this->assertSame(CeldaLeida::FORMULA_SIN_VALOR, $this->celda($l, 'C1')->origen, 'sin <v>: sin valor');
        $this->assertSame([2, CeldaLeida::FORMULA_GUARDADA], [$this->celda($l, 'B2')->valor, $this->celda($l, 'B2')->origen]);
    }

    public function test_una_hoja_de_grafico_ocupa_su_posicion_sin_desalinear_las_demas(): void
    {
        $bytes = X::libro([
            ['nombre' => 'Datos', 'celdas' => ['A1' => 'dato', 'B1' => ['f' => 'IF(1,"","")', 't' => 'str', 'v' => ''], 'C1' => ['f' => 'IF(1,"","")', 't' => 'str']]],
        ]);
        // Se añade al libro una hoja de gráfico ANTES de la hoja de datos (relación de otro tipo).
        $zip = new \ZipArchive;
        $ruta = $this->guardar($bytes);
        $zip->open($ruta);
        $libro = str_replace('<sheets>', '<sheets><sheet name="Grafico" sheetId="9" r:id="rIdGrafico"/>', $zip->getFromName('xl/workbook.xml'));
        $rels = str_replace('</Relationships>', '<Relationship Id="rIdGrafico" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chartsheet" Target="chartsheets/sheet1.xml"/></Relationships>', $zip->getFromName('xl/_rels/workbook.xml.rels'));
        $zip->addFromString('xl/workbook.xml', $libro);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
        $zip->close();

        $marcas = MarcasXlsx::localizar($ruta);

        $this->assertIsArray($marcas);
        $this->assertSame([], $marcas[0]['banderas'], 'la hoja de gráfico no tiene celdas');
        $this->assertNotEmpty($marcas[1]['banderas'], 'la hoja de datos conserva sus marcas en su posición');
    }

    private function guardar(string $bytes): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'f0t_');
        file_put_contents($ruta, $bytes);
        $this->beforeApplicationDestroyed(fn () => @unlink($ruta));

        return $ruta;
    }
}
