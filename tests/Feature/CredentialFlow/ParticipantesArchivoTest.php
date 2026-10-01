<?php

namespace Tests\Feature\CredentialFlow;

use App\Support\CredentialFlow\Participantes\ErrorFila as E;
use App\Support\CredentialFlow\Participantes\ImportadorParticipantes;
use App\Support\CredentialFlow\Participantes\LectorArchivo;
use App\Support\CredentialFlow\Participantes\ResultadoImportacion;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\CredentialFlow\Support\ArchivosParticipantes as A;
use Tests\TestCase;

/** F&C Credential Flow · Fase 6: lectura de archivos XLSX/CSV, encabezados y validación de filas (sin base de datos). */
class ParticipantesArchivoTest extends TestCase
{
    private function validar($archivo): ResultadoImportacion
    {
        return app(ImportadorParticipantes::class)->validar($archivo);
    }

    /** @return array<int,string> códigos de error */
    private function codigos(ResultadoImportacion $r): array
    {
        return array_map(fn (E $e) => $e->codigo, $r->errores);
    }

    private function cabecera(): array
    {
        return ['nombre_completo', 'documento'];
    }

    // ── Archivos válidos ──────────────────────────────────────────────────────

    public function test_xlsx_valido(): void
    {
        $r = $this->validar(A::xlsx([$this->cabecera(), ['Juan Pérez', 'C.C. 1.023.456.789'], ['María Ñandú', 'NIT 900.123.456-7']]));

        $this->assertTrue($r->valido());
        $this->assertSame(2, $r->filasValidas);
        $this->assertSame('JUAN PÉREZ', $r->participantes[0]['nombre_completo']);
        $this->assertSame('C.C. 1.023.456.789', $r->participantes[0]['documento']);
        $this->assertSame('CC1023456789', $r->participantes[0]['documento_clave']);
        $this->assertSame('MARÍA ÑANDÚ', $r->participantes[1]['nombre_completo']);
        $this->assertSame(2, $r->participantes[0]['fila']);
    }

    public function test_csv_utf8_con_y_sin_bom(): void
    {
        foreach ([false, true] as $bom) {
            $r = $this->validar(A::csv([$this->cabecera(), ['Ángela Núñez', 'CC 1023456789']], ',', 'UTF-8', $bom));
            $this->assertTrue($r->valido(), 'bom='.var_export($bom, true));
            $this->assertSame('ÁNGELA NÚÑEZ', $r->participantes[0]['nombre_completo']);
            $this->assertSame(['nombre_completo', 'documento'], array_keys(array_intersect_key($r->participantes[0], array_flip($this->cabecera()))));
        }
    }

    public function test_csv_con_punto_y_coma_y_con_tabulador(): void
    {
        foreach ([';', "\t"] as $sep) {
            $r = $this->validar(A::csv([$this->cabecera(), ['José Muñoz', 'C.C. 80.123.456']], $sep));
            $this->assertTrue($r->valido(), 'sep='.json_encode($sep));
            $this->assertSame('JOSÉ MUÑOZ', $r->participantes[0]['nombre_completo']);
        }
    }

    public function test_csv_windows_1252(): void
    {
        $r = $this->validar(A::csv([$this->cabecera(), ['José Peña Núñez', 'C.C. 80.123.456']], ';', 'Windows-1252'));

        $this->assertTrue($r->valido());
        $this->assertSame('JOSÉ PEÑA NÚÑEZ', $r->participantes[0]['nombre_completo']);
    }

    public function test_csv_con_comas_dentro_de_comillas(): void
    {
        $r = $this->validar(A::csv([$this->cabecera(), ['Pérez, Juan', 'C.C. 1.023.456.789']]));

        $this->assertTrue($r->valido());
        $this->assertSame('PÉREZ, JUAN', $r->participantes[0]['nombre_completo']);
    }

    public function test_xlsx_prefiere_la_hoja_participantes_y_ignora_las_demas(): void
    {
        $r = $this->validar(A::xlsx([$this->cabecera(), ['Solo Uno', 'CC 11112222']], 'p.xlsx', ['Instrucciones' => [['texto sin relación'], ['otro']]], 'Participantes'));

        $this->assertTrue($r->valido());
        $this->assertSame(1, $r->filasValidas);
    }

    public function test_las_filas_totalmente_vacias_se_ignoran_y_conservan_el_numero_real(): void
    {
        $r = $this->validar(A::xlsx([$this->cabecera(), [null, null], ['Ana Ruiz', 'CC 22223333'], ['', ''], ['Luis Soto', 'CC 44445555']]));

        $this->assertTrue($r->valido());
        $this->assertSame([3, 5], array_column($r->participantes, 'fila'));
    }

    // ── Archivo inválido ──────────────────────────────────────────────────────

    public static function extensionesInvalidas(): array
    {
        return ['xls' => ['participantes.xls'], 'xlsm' => ['participantes.xlsm'], 'xlsb' => ['p.xlsb'], 'ods' => ['p.ods'], 'txt' => ['p.txt'], 'pdf' => ['p.pdf'], 'sin extensión' => ['participantes'], 'doble extensión' => ['p.xlsx.exe']];
    }

    #[DataProvider('extensionesInvalidas')]
    public function test_extension_invalida(string $nombre): void
    {
        $r = $this->validar(A::subir(A::xlsxBytes([$this->cabecera()]), $nombre));

        $this->assertSame([E::EXTENSION_NO_PERMITIDA], $this->codigos($r));
    }

    public function test_mime_enganoso(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

        $this->assertSame([E::ARCHIVO_INVALIDO], $this->codigos($this->validar(A::subir($png, 'participantes.csv'))));
        $this->assertSame([E::ARCHIVO_INVALIDO], $this->codigos($this->validar(A::subir($png, 'participantes.xlsx'))));
        $this->assertSame([E::ARCHIVO_INVALIDO], $this->codigos($this->validar(A::subir("nombre_completo,documento\nAna,1234", 'participantes.xlsx'))));
    }

    public function test_csv_utf16_se_rechaza_con_un_mensaje_util(): void
    {
        $r = $this->validar(A::subir("\xFF\xFE".mb_convert_encoding("nombre_completo,documento\r\n", 'UTF-16LE', 'UTF-8'), 'p.csv'));

        $this->assertSame([E::ARCHIVO_INVALIDO], $this->codigos($r));
        $this->assertStringContainsString('UTF-8', $r->errores[0]->mensaje);
    }

    public function test_archivo_de_mas_de_2_mb(): void
    {
        $r = $this->validar(A::subir("nombre_completo,documento\n".str_repeat("Ana Ruiz,CC 22223333\n", 110_000), 'grande.csv'));

        $this->assertSame([E::ARCHIVO_MUY_GRANDE], $this->codigos($r));
    }

    public function test_xlsx_corrupto(): void
    {
        $bytes = A::xlsxBytes([$this->cabecera(), ['Ana Ruiz', 'CC 22223333']]);
        $r = $this->validar(A::subir(substr($bytes, 0, (int) (strlen($bytes) / 2)), 'p.xlsx'));

        $this->assertContains($this->codigos($r)[0], [E::ARCHIVO_CORRUPTO, E::ARCHIVO_INVALIDO]);
    }

    public function test_xlsx_con_macros(): void
    {
        $base = A::xlsxBytes([$this->cabecera()]);

        $this->assertSame([E::ARCHIVO_CON_MACROS], $this->codigos($this->validar(A::subir(A::conEntrada($base, 'xl/vbaProject.bin', 'x'), 'p.xlsx'))));
        $this->assertSame([E::ARCHIVO_CON_MACROS], $this->codigos($this->validar(A::subir(
            A::conTiposReemplazados($base, 'sheet.main+xml', 'sheet.macroEnabled.main+xml'), 'p.xlsx'
        ))));
    }

    public function test_zip_sin_las_partes_de_un_libro_de_excel(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'cft_');
        $zip = new \ZipArchive;
        $zip->open($ruta, \ZipArchive::OVERWRITE);
        $zip->addFromString('hola.txt', 'no soy un xlsx');
        $zip->close();
        $bytes = file_get_contents($ruta);
        @unlink($ruta);

        $this->assertSame([E::ARCHIVO_INVALIDO], $this->codigos($this->validar(A::subir($bytes, 'p.xlsx'))));
    }

    public function test_zip_bomb_por_tamano_descomprimido_y_por_razon_de_compresion(): void
    {
        // 30 MB de ceros comprimen a unas decenas de KB: supera el total descomprimido permitido.
        $grande = A::zipBomb(30 * 1024 * 1024);
        $this->assertLessThan(LectorArchivo::ARCHIVO_MAX_BYTES, strlen($grande));
        $this->assertSame([E::ARCHIVO_SOSPECHOSO], $this->codigos($this->validar(A::subir($grande, 'bomba.xlsx'))));

        // 3 MB: no llega al total, pero la razón de compresión es anormal.
        $ratio = A::zipBomb(3 * 1024 * 1024);
        $this->assertSame([E::ARCHIVO_SOSPECHOSO], $this->codigos($this->validar(A::subir($ratio, 'bomba2.xlsx'))));
    }

    public function test_demasiadas_filas(): void
    {
        $filas = [$this->cabecera()];
        for ($i = 1; $i <= LectorArchivo::FILAS_MAX + 1; $i++) {
            $filas[] = ["Persona $i", 'CC '.(10_000_000 + $i)];
        }

        $this->assertSame([E::DEMASIADAS_FILAS], $this->codigos($this->validar(A::csv($filas))));
    }

    public function test_exactamente_el_maximo_de_filas_es_valido(): void
    {
        $filas = [$this->cabecera()];
        for ($i = 1; $i <= LectorArchivo::FILAS_MAX; $i++) {
            $filas[] = ["Persona $i", 'CC '.(10_000_000 + $i)];
        }
        $r = $this->validar(A::csv($filas));

        $this->assertTrue($r->valido());
        $this->assertSame(1000, $r->filasValidas);
    }

    public function test_demasiadas_columnas(): void
    {
        $cabecera = array_merge($this->cabecera(), array_map(fn ($i) => "extra$i", range(1, 19)));
        $fila = array_merge(['Ana Ruiz', 'CC 22223333'], array_fill(0, 19, 'x'));

        $this->assertSame([E::DEMASIADAS_COLUMNAS], $this->codigos($this->validar(A::csv([$cabecera, $fila]))));
    }

    public function test_archivo_sin_filas_o_solo_con_encabezado(): void
    {
        $this->assertSame([E::SIN_FILAS], $this->codigos($this->validar(A::subir('', 'vacio.csv'))));
        $this->assertSame([E::SIN_FILAS], $this->codigos($this->validar(A::csv([$this->cabecera()]))));
    }

    public function test_csv_de_0_bytes_nunca_es_valido_y_termina_en_sin_filas(): void
    {
        // finfo reporta un archivo vacío como application/x-empty: ese tipo solo se tolera en la capa MIME
        // para que el flujo llegue al error funcional SIN_FILAS; jamás se interpreta como un CSV válido.
        $vacio = A::subir('', 'vacio.csv');
        $this->assertSame(0, filesize($vacio->getRealPath()));
        $this->assertContains((new \finfo(FILEINFO_MIME_TYPE))->file($vacio->getRealPath()), ['application/x-empty', 'inode/x-empty']);

        $r = $this->validar($vacio);

        $this->assertFalse($r->valido());
        $this->assertSame([E::SIN_FILAS], $this->codigos($r));
        $this->assertSame(0, $r->filasDetectadas);
        $this->assertSame([], $r->participantes);
        $this->assertSame([], $r->preview);

        // Un CSV que solo tiene saltos de línea o espacios tampoco es válido.
        $this->assertSame([E::SIN_FILAS], $this->codigos($this->validar(A::subir("\r\n\r\n  \r\n", 'blancos.csv'))));
        // Un XLSX de 0 bytes no llega ni a la lectura: no es un libro de Excel.
        $this->assertSame([E::ARCHIVO_INVALIDO], $this->codigos($this->validar(A::subir('', 'vacio.xlsx'))));
    }

    // ── Encabezados ───────────────────────────────────────────────────────────

    public static function aliases(): array
    {
        return [
            'nombre' => [['Nombre', 'Cédula']],
            'nombres y apellidos / identificación' => [['NOMBRES Y APELLIDOS', 'Identificación']],
            'nombre completo / número de documento' => [['Nombre completo', 'Número de documento']],
            'participante / cc' => [['participante', 'CC']],
            'asistente / doc' => [['Asistente', 'doc']],
            'nombre-completo / numero_de_documento' => [['nombre-completo', 'numero_de_documento']],
            'con espacios' => [['  Nombre  Completo ', ' Documento ']],
            'nombres / cedula' => [['Nombres', 'cedula']],
        ];
    }

    #[DataProvider('aliases')]
    public function test_aliases_de_encabezado(array $cabecera): void
    {
        $r = $this->validar(A::xlsx([$cabecera, ['Ana Ruiz', 'CC 22223333']]));

        $this->assertTrue($r->valido(), json_encode($this->codigos($r)));
        $this->assertSame('ANA RUIZ', $r->participantes[0]['nombre_completo']);
    }

    public function test_orden_de_columnas_distinto_y_columnas_ignoradas_como_aviso(): void
    {
        $r = $this->validar(A::xlsx([['Celular', 'documento', 'nombre_completo'], ['3001234567', 'CC 22223333', 'Ana Ruiz']]));

        $this->assertTrue($r->valido());
        $this->assertSame('CC 22223333', $r->participantes[0]['documento']);
        $this->assertSame(['Celular'], $r->columnasIgnoradas);
        $this->assertSame([E::COLUMNA_IGNORADA], array_map(fn (E $a) => $a->codigo, $r->avisos));
    }

    public function test_columna_faltante(): void
    {
        $r = $this->validar(A::xlsx([['nombre_completo', 'celular'], ['Ana Ruiz', '300']]));

        $this->assertSame([E::COLUMNA_FALTANTE], $this->codigos($r));
        $this->assertSame(['documento'], $r->columnasFaltantes);
    }

    public function test_encabezado_duplicado(): void
    {
        $r = $this->validar(A::xlsx([['nombre_completo', 'Nombre', 'documento'], ['Ana Ruiz', 'Ana', 'CC 22223333']]));

        $this->assertSame([E::COLUMNA_DUPLICADA], $this->codigos($r));
    }

    public function test_columnas_de_nivel_lote_no_se_ignoran_en_silencio(): void
    {
        foreach (['evento', 'Fecha', 'intensidad_horaria', 'Intensidad horaria'] as $columna) {
            $r = $this->validar(A::xlsx([['nombre_completo', 'documento', $columna], ['Ana Ruiz', 'CC 22223333', 'x']]));

            $this->assertSame([E::COLUMNA_NIVEL_LOTE], $this->codigos($r), $columna);
            $this->assertStringContainsString('formulario de la base', $r->errores[0]->mensaje);
        }
    }

    // ── Datos por fila ────────────────────────────────────────────────────────

    public function test_ceros_iniciales_como_texto_se_conservan(): void
    {
        $r = $this->validar(A::xlsx([$this->cabecera(), ['Ana Ruiz', '00123456'], ['Luis Soto', '0012345678']]));

        $this->assertTrue($r->valido());
        $this->assertSame('00123456', $r->participantes[0]['documento']);
        $this->assertSame([], array_filter($r->avisos, fn (E $a) => $a->codigo === E::DOCUMENTO_NUMERICO_EXCEL));
    }

    public function test_documento_numerico_de_excel_entero_se_acepta_con_aviso(): void
    {
        $r = $this->validar(A::xlsx([$this->cabecera(), ['Ana Ruiz', 1023456789]]));

        $this->assertTrue($r->valido());
        $this->assertSame('1023456789', $r->participantes[0]['documento']);
        $this->assertSame([E::DOCUMENTO_NUMERICO_EXCEL], array_map(fn (E $a) => $a->codigo, $r->avisos));
        $this->assertSame('1023456789', $r->preview[0]['documento']); // el preview muestra lo que se guardará
    }

    public function test_documento_numerico_con_decimales_o_cientifico_se_rechaza(): void
    {
        $this->assertSame([E::DOCUMENTO_NOTACION_CIENTIFICA], $this->codigos($this->validar(A::xlsx([$this->cabecera(), ['Ana Ruiz', 1.5]]))));
        $this->assertSame([E::DOCUMENTO_NOTACION_CIENTIFICA], $this->codigos($this->validar(A::xlsx([$this->cabecera(), ['Ana Ruiz', 1.023456789E+20]]))));
    }

    public static function documentosCientificosCsv(): array
    {
        return ['1.02E+9' => ['1.02E+9'], '1,02e9' => ['1,02e9'], '1023456789.0' => ['1023456789.0'], '1023456789,00' => ['1023456789,00']];
    }

    #[DataProvider('documentosCientificosCsv')]
    public function test_documento_en_notacion_cientifica_o_decimal_en_csv(string $doc): void
    {
        $r = $this->validar(A::csv([$this->cabecera(), ['Ana Ruiz', $doc]], ';'));

        $this->assertSame([E::DOCUMENTO_NOTACION_CIENTIFICA], $this->codigos($r));
    }

    public function test_formulas_de_texto_se_rechazan(): void
    {
        foreach (['=1+1', '+57 300', '-cmd', '@SUM(A1)', '  =1+1'] as $texto) {
            $r = $this->validar(A::csv([$this->cabecera(), [$texto, 'CC 22223333'], ['Ana Ruiz', $texto]]));

            $this->assertSame([E::FORMULA_NO_PERMITIDA, E::FORMULA_NO_PERMITIDA], $this->codigos($r), $texto);
            $this->assertSame(['nombre_completo', 'documento'], array_map(fn (E $e) => $e->columna, $r->errores));
        }
    }

    public function test_celdas_con_formula_real_de_xlsx_se_rechazan_aunque_tengan_valor_calculado(): void
    {
        $r = $this->validar(A::xlsx([$this->cabecera(), [['f' => '=CONCATENATE("A","na")', 'calculado' => 'Ana'], 'CC 22223333'], ['Luis Soto', ['f' => '=1000+234', 'calculado' => 1234]]]));

        $this->assertSame([E::FORMULA_NO_PERMITIDA, E::FORMULA_NO_PERMITIDA], $this->codigos($r));
        $this->assertSame([2, 3], array_map(fn (E $e) => $e->fila, $r->errores));
    }

    public function test_texto_normalizado_espacios_saltos_nbsp_y_nfc(): void
    {
        $r = $this->validar(A::xlsx([$this->cabecera(), ["  Ana   María\u{00A0}Ruiz\tPérez\n de la Cruz ", " C.C.\u{00A0} 1.023.456.789 "], ["Cafe\u{0301} Núñez", 'CC 1234']]));

        $this->assertTrue($r->valido());
        $this->assertSame('ANA MARÍA RUIZ PÉREZ DE LA CRUZ', $r->participantes[0]['nombre_completo']);
        $this->assertSame('C.C. 1.023.456.789', $r->participantes[0]['documento']);
        $this->assertSame("CAF\u{00C9} N\u{00DA}\u{00D1}EZ", $r->participantes[1]['nombre_completo']); // NFC + mayúsculas
    }

    public function test_nombres_extranjeros_razonables_y_ñ(): void
    {
        $r = $this->validar(A::xlsx([$this->cabecera(), ["O'Brien-Müller Ñandú", 'PASAPORTE AB1234567'], ['Zoë Łukasz Çelik', 'CC 99998888']]));

        $this->assertTrue($r->valido(), json_encode($r->toArray()['errores']));
        $this->assertSame("O'BRIEN-MÜLLER ÑANDÚ", $r->participantes[0]['nombre_completo']);
    }

    public function test_caracter_que_outfit_no_puede_representar(): void
    {
        $r = $this->validar(A::xlsx([$this->cabecera(), ['Ana 日本', 'CC 22223333'], ['Luis Soto', 'CC 4444 😀']]));

        $this->assertSame([E::CARACTER_NO_SOPORTADO, E::CARACTER_NO_SOPORTADO], $this->codigos($r));
        $this->assertSame(['nombre_completo', 'documento'], array_map(fn (E $e) => $e->columna, $r->errores));
    }

    public function test_limites_de_nombre_y_documento(): void
    {
        $r = $this->validar(A::xlsx([
            $this->cabecera(),
            ['A', 'CC 22223331'],
            [str_repeat('A', 101), 'CC 22223332'],
            ['Ana Ruiz', 'CC 1'],
            ['Luis Soto', str_repeat('9', 41)],
            [null, 'CC 55556666'],
            ['Eva Díaz', null],
            [str_repeat('B', 501), 'CC 66667777'],
        ]));

        $this->assertSame([
            E::NOMBRE_MUY_CORTO, E::NOMBRE_MUY_LARGO, E::DOCUMENTO_MUY_CORTO, E::DOCUMENTO_MUY_LARGO,
            E::NOMBRE_VACIO, E::DOCUMENTO_VACIO, E::CELDA_MUY_LARGA,
        ], $this->codigos($r));
    }

    public function test_caracteres_de_control_y_celdas_que_no_son_texto(): void
    {
        $r = $this->validar(A::xlsx([$this->cabecera(), ["Ana\x07 Ruiz", 'CC 22223333'], ['Luis Soto', true]]));

        $this->assertSame([E::CARACTER_INVALIDO, E::VALOR_NO_TEXTO], $this->codigos($r));
    }

    // ── Duplicados ────────────────────────────────────────────────────────────

    public function test_duplicados_por_documento_clave_dentro_del_archivo(): void
    {
        $r = $this->validar(A::xlsx([
            $this->cabecera(),
            ['Ana Ruiz', 'C.C. 1.023.456.789'],
            ['Luis Soto', 'CC 22223333'],
            ['Otra Persona', 'c.c. 1023456789'],
            ['Tercera', 'CC-1023456789'],
        ]));

        $this->assertSame([E::DOCUMENTO_DUPLICADO, E::DOCUMENTO_DUPLICADO], $this->codigos($r));
        $this->assertSame([4, 5], array_map(fn (E $e) => $e->fila, $r->errores));
        $this->assertStringContainsString('fila 2', $r->errores[0]->mensaje);
        $this->assertFalse($r->valido());
        $this->assertSame(2, $r->filasConError);
    }

    public function test_el_mismo_nombre_con_documentos_distintos_no_es_duplicado(): void
    {
        $r = $this->validar(A::xlsx([$this->cabecera(), ['Juan Pérez', 'CC 11112222'], ['Juan Pérez', 'CC 33334444']]));

        $this->assertTrue($r->valido());
    }

    // ── Preview y errores ─────────────────────────────────────────────────────

    public function test_preview_limitado_a_20_filas_y_errores_con_valor_truncado(): void
    {
        $filas = [$this->cabecera()];
        for ($i = 1; $i <= 30; $i++) {
            $filas[] = ["Persona $i", 'CC '.(10_000_000 + $i)];
        }
        $filas[] = ['Larga '.str_repeat('X', 90), 'CC 88887777'];
        $r = $this->validar(A::xlsx($filas));

        $this->assertCount(20, $r->preview);
        $this->assertSame(31, $r->filasDetectadas);
        $this->assertSame(31, $r->filasValidas);

        $largo = $this->validar(A::xlsx([$this->cabecera(), ['Larga '.str_repeat('X', 100), 'CC 88887777']]));
        $arr = $largo->toArray();
        $this->assertSame(E::NOMBRE_MUY_LARGO, $arr['errores'][0]['codigo']);
        $this->assertLessThanOrEqual(E::VALOR_MAX + 1, mb_strlen($arr['errores'][0]['valor']));
        $this->assertSame(['fila', 'columna', 'codigo', 'mensaje', 'valor', 'tipo'], array_keys($arr['errores'][0]));
    }

    public function test_la_lista_de_errores_devuelta_tiene_tope_pero_informa_el_total(): void
    {
        $filas = [$this->cabecera()];
        for ($i = 1; $i <= 300; $i++) {
            $filas[] = [null, 'CC '.(10_000_000 + $i)];
        }
        $arr = $this->validar(A::xlsx($filas))->toArray();

        $this->assertSame(300, $arr['errores_total']);
        $this->assertCount(ResultadoImportacion::LISTA_MAX, $arr['errores']);
    }

    public function test_sanear_nombre_de_archivo(): void
    {
        $this->assertSame('participantes.xlsx', ImportadorParticipantes::sanearNombre('participantes.xlsx'));
        $this->assertSame('passwd', ImportadorParticipantes::sanearNombre('../../etc/passwd'));
        $this->assertSame('a_b_.csv', ImportadorParticipantes::sanearNombre('a<b>.csv'));
        $this->assertSame(150, mb_strlen(ImportadorParticipantes::sanearNombre(str_repeat('a', 300).'.xlsx')));
        $this->assertSame('archivo', ImportadorParticipantes::sanearNombre('   '));
    }
}
