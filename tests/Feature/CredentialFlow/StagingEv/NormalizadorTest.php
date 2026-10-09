<?php

namespace Tests\Feature\CredentialFlow\StagingEv;

use App\Support\CredentialFlow\StagingEv\Normalizador;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Funciones puras de normalización del staging histórico: sin base de datos y con datos sintéticos. */
class NormalizadorTest extends TestCase
{
    /** @return array<string,array{0:?string,1:?string}> vectores medidos con PHP 7.4.33 en el sistema viejo (Fase 0) */
    public static function documentosImpresos(): array
    {
        return [
            'número normal' => ['73156827', '73.156.827'],
            'ceros a la izquierda se pierden' => ['009876543', '9.876.543'],
            'con puntos solo cuenta el prefijo' => ['73.156.827', '73'],
            'letras: no se imprime nada' => ['ABC123', null],
            'prefijo numérico' => ['123abc', '123'],
            'vacío' => ['', null],
            'nulo' => [null, null],
            'notación científica' => ['1e3', '1.000'],
            'más de 15 dígitos pierde precisión (float)' => ['12345678901234567890', '12.345.678.901.234.567.168'],
            'espacio inicial' => [' 123', '123'],
            'negativo' => ['-5', '-5'],
            'espacio interno' => ['12 345', '12'],
        ];
    }

    #[DataProvider('documentosImpresos')]
    public function test_documento_impreso_reproduce_number_format_de_php_74(?string $original, ?string $esperado): void
    {
        $this->assertSame($esperado, Normalizador::documentoImpresoLegado($original));
    }

    public function test_linea_de_documento_con_tipo_vacio(): void
    {
        $this->assertSame('CC: 73.156.827', Normalizador::lineaDocumentoLegado('CC', '73156827'));
        $this->assertSame(': 1.234', Normalizador::lineaDocumentoLegado(null, '1234'));
    }

    /** @return array<string,array{0:?string,1:string,2:?string}> */
    public static function estadosDeDocumento(): array
    {
        return [
            'válido' => ['73156827', 'valido', null],
            'válido con espacios alrededor' => ['  73156827 ', 'valido', null],
            'vacío' => ['', 'vacio', null],
            'solo espacios' => ['   ', 'vacio', null],
            'nulo' => [null, 'vacio', null],
            'letras' => ['ABC123', 'anomalo', 'letras'],
            'puntos' => ['73.156.827', 'anomalo', 'separadores'],
            'comas' => ['73,156,827', 'anomalo', 'separadores'],
            'otro carácter' => ['123-456', 'anomalo', 'otro'],
            'ceros a la izquierda' => ['0123456', 'anomalo', 'ceros_izquierda'],
            'más de 15 dígitos' => ['1234567890123456', 'anomalo', 'muy_largo'],
            '15 dígitos es válido' => ['123456789012345', 'valido', null],
        ];
    }

    #[DataProvider('estadosDeDocumento')]
    public function test_estado_del_documento(?string $original, string $estado, ?string $detalle): void
    {
        $this->assertSame([$estado, $detalle], Normalizador::estadoDocumento($original));
    }

    public function test_documento_clave_usa_la_misma_regla_que_credential_flow(): void
    {
        $this->assertSame('CC1023456789', Normalizador::documentoClave('C.C. 1.023.456.789'));
        $this->assertSame('', Normalizador::documentoClave(null));
        $this->assertSame('', Normalizador::documentoClave('   '));
        $this->assertSame('ABC123', Normalizador::documentoClave(' abc-123 '));
    }

    public function test_nulo_y_vacio_son_lo_mismo_para_correo_tipo_y_nombre(): void
    {
        foreach ([null, '', '   ', "\t\n"] as $vacio) {
            $this->assertNull(Normalizador::correo($vacio));
            $this->assertNull(Normalizador::tipoDocumento($vacio));
            $this->assertSame('', Normalizador::nombre($vacio));
            $this->assertNull(Normalizador::sinDato($vacio));
        }
    }

    public function test_tipo_documento(): void
    {
        $this->assertSame('CC', Normalizador::tipoDocumento(' c.c. '));
        $this->assertSame('CE', Normalizador::tipoDocumento('ce'));
        $this->assertSame('TI', Normalizador::tipoDocumento('T.I'));
    }

    public function test_nombre_se_normaliza_a_nfc_mayusculas_y_espacios(): void
    {
        $descompuesto = "Jose\u{0301}  Mun\u{0303}oz\u{00A0}Alvarez";   // José Muñoz Alvarez con marcas combinadas y NBSP
        $this->assertSame("JOS\u{00C9} MU\u{00D1}OZ ALVAREZ", Normalizador::nombre($descompuesto));
        $this->assertSame('ÁNGELA', Normalizador::nombre('Ángela'));
    }

    public function test_correo_se_normaliza_y_se_clasifica(): void
    {
        $this->assertSame('ana@example.test', Normalizador::correo('  Ana@Example.TEST '));
        $this->assertSame('valido', Normalizador::correoEstado('ana@example.test'));
        $this->assertSame('sin_correo', Normalizador::correoEstado(null));
        foreach (['correo-invalido', 'ana@', '@example.test', 'ana@localhost', 'ana@@example.test', 'ana uno@example.test', 'a@b@c.com', str_repeat('a', 250).'@x.co'] as $malo) {
            $this->assertSame('invalido', Normalizador::correoEstado(Normalizador::correo($malo)), $malo);
        }
    }

    public function test_para_buscar_quita_tildes_y_mayusculas(): void
    {
        $this->assertSame('gestion integral de propiedad horizontal', Normalizador::paraBuscar('  Gestión  INTEGRAL de Propiedad Horizontal '));
        $this->assertSame('', Normalizador::paraBuscar(null));
    }

    public function test_clave_de_archivo_empareja_nombres_cambiados_por_el_sistema_viejo(): void
    {
        $this->assertSame(Normalizador::claveArchivo('Curso ¿Efectiva? 2024.jpg'), Normalizador::claveArchivo('curso__efectiva__2024.jpg'));
        $this->assertSame('gat_4_0_0', Normalizador::claveArchivo('GAT 4.0.0'));
    }

    public function test_extension_como_la_decide_fpdf(): void
    {
        $this->assertSame('png', Normalizador::extensionFpdf('a b.PNG'));
        $this->assertSame('7 cuipo', Normalizador::extensionFpdf('V.7 CUIPO.7 CUIPO'));
        $this->assertNull(Normalizador::extensionFpdf('sin extension'));
        $this->assertNull(Normalizador::extensionFpdf('.oculto'));
    }

    public function test_anio_deducible_solo_si_es_unico(): void
    {
        $this->assertSame([2024, 'nombre'], Normalizador::anioDeducible(['nombre' => 'Curso 2024', 'imagen' => 'x 2025.png']));
        $this->assertSame([2025, 'imagen'], Normalizador::anioDeducible(['nombre' => 'Curso', 'imagen' => 'x 2025.png']));
        $this->assertSame([null, 'ambiguo'], Normalizador::anioDeducible(['nombre' => 'Curso 2024 y 2025', 'imagen' => 'x 2025.png']));
        $this->assertSame([null, null], Normalizador::anioDeducible(['nombre' => 'Curso', 'imagen' => null]));
        $this->assertSame([2024, 'nombre'], Normalizador::anioDeducible(['nombre' => 'Curso 2024 (2024)']));
        $this->assertSame([null, null], Normalizador::anioDeducible(['nombre' => 'Resolución 12345678']));
    }

    public function test_nombre_de_archivo_raro(): void
    {
        $this->assertTrue(Normalizador::nombreArchivoRaro(' x.png'));
        $this->assertTrue(Normalizador::nombreArchivoRaro('x  y.png'));
        $this->assertTrue(Normalizador::nombreArchivoRaro('x .png'));
        $this->assertFalse(Normalizador::nombreArchivoRaro('x y.png'));
    }

    /** @return array<string,array{0:string,1:string,2:?string,3:bool,4:?string}> original, estado, detalle, marca whitespace, tipos de blancos */
    public static function documentosConBlancos(): array
    {
        return [
            'LF al final' => ["1234567\n", 'valido', null, true, 'lf@borde'],
            'tab al inicio' => ["\t1234568", 'valido', null, true, 'tab@borde'],
            'CR LF al final' => ["1234569\r\n", 'valido', null, true, 'cr@borde,lf@borde'],
            'espacios alrededor' => [' 1234570 ', 'valido', null, true, 'espacio@borde'],
            'NBSP al final: se imprimía igual' => ["1234572\u{00A0}", 'valido', null, true, 'nbsp@borde'],
            'espacio al final tras tab inicial' => ["\t 1234573 ", 'valido', null, true, 'espacio@borde,tab@borde'],
            'espacio interno: cambiaba lo impreso' => ['12 345', 'anomalo', 'whitespace_cambia_impresion', false, 'espacio@interno'],
            'NBSP al inicio: PHP 7.4 no lo tomaba como espacio' => ["\u{00A0}1234571", 'anomalo', 'whitespace_cambia_impresion', false, 'nbsp@borde'],
            'tab interno' => ["123\t456", 'anomalo', 'whitespace_cambia_impresion', false, 'tab@interno'],
            'sigue anómalo: letras' => ['AB 123', 'anomalo', 'letras', false, 'espacio@interno'],
            'sigue anómalo: separadores' => [" 73.156.827\n", 'anomalo', 'separadores', false, 'espacio@borde,lf@borde'],
            'sigue anómalo: ceros a la izquierda' => ["\n0123456", 'anomalo', 'ceros_izquierda', false, 'lf@borde'],
            'sin blancos y válido' => ['73156827', 'valido', null, false, null],
            'solo blancos' => ["\t \n", 'vacio', null, false, 'espacio@borde,lf@borde,tab@borde'],
        ];
    }

    #[DataProvider('documentosConBlancos')]
    public function test_regla_de_whitespace_del_documento(string $original, string $estado, ?string $detalle, bool $marca, ?string $blancos): void
    {
        $e = Normalizador::evaluarDocumento($original);

        $this->assertSame([$estado, $detalle, $marca, $blancos], [$e['estado'], $e['detalle'], $e['normalizado_whitespace'], $e['blancos']]);
    }

    public function test_el_documento_original_nunca_se_modifica_al_evaluarlo(): void
    {
        $original = "\t 1234567\r\n";
        $copia = $original;
        Normalizador::evaluarDocumento($original);

        $this->assertSame($copia, $original);
        $this->assertSame('1234567', Normalizador::quitarBlancos($original));
    }

    /** @return array<string,array{0:?string,1:array<int,string>,2:array<int,string>}> original, candidatos, estados */
    public static function camposDeCorreo(): array
    {
        return [
            'dos válidos con punto y coma' => ['a@example.test;b@example.test', ['a@example.test', 'b@example.test'], ['valido', 'valido']],
            'dos válidos con coma y espacio' => ['c@example.test, d@example.test', ['c@example.test', 'd@example.test'], ['valido', 'valido']],
            'válido más inválido (ambos con @)' => ['f@example.test;g@@example.test', ['f@example.test', 'g@@example.test'], ['valido', 'invalido']],
            'duplicado con distinto casing' => ['H@Example.test; h@example.TEST', ['h@example.test'], ['valido']],
            'separador sobrante' => ['x@example.test;', ['x@example.test'], ['valido']],
            'un fragmento sin arroba: no se parte' => ['e@example.test;correo-malo', ['e@example.test;correo-malo'], ['invalido']],
            'nombre con coma: no se parte' => ['Pérez, Juan', ['pérez, juan'], ['invalido']],
            'correo único normal' => ['  Ana@Example.test ', ['ana@example.test'], ['valido']],
            'vacío' => ['', [], []],
            'nulo' => [null, [], []],
            'tres con mezcla de separadores' => ['a@example.test;b@example.test,c@example.test', ['a@example.test', 'b@example.test', 'c@example.test'], ['valido', 'valido', 'valido']],
            'válido con segundo incompleto' => ['i@example.test; j@', ['i@example.test', 'j@'], ['valido', 'invalido']],
        ];
    }

    /**
     * @param  array<int,string>  $candidatos
     * @param  array<int,string>  $estados
     */
    #[DataProvider('camposDeCorreo')]
    public function test_candidatos_de_correo(?string $original, array $candidatos, array $estados): void
    {
        $r = Normalizador::candidatosCorreo($original);

        $this->assertSame($candidatos, array_column($r, 'correo'));
        $this->assertSame($estados, array_column($r, 'estado'));
    }

    public function test_resumen_de_correos_no_elige_un_principal(): void
    {
        $dos = Normalizador::resumenCorreos('a@example.test;b@example.test');
        $this->assertSame(['multiple', null, 2, 2], [$dos['estado'], $dos['correo_normalizado'], $dos['candidatos'], $dos['validos']]);

        $mezcla = Normalizador::resumenCorreos('f@example.test;g@@example.test');
        $this->assertSame(['valido', 'f@example.test', 2, 1], [$mezcla['estado'], $mezcla['correo_normalizado'], $mezcla['candidatos'], $mezcla['validos']]);

        $malos = Normalizador::resumenCorreos('uno@@x;dos@@y');
        $this->assertSame(['invalido', null, 2, 0], [$malos['estado'], $malos['correo_normalizado'], $malos['candidatos'], $malos['validos']]);

        $this->assertSame(['sin_correo', null, 0, 0, null], array_values(Normalizador::resumenCorreos(' ')));
        $this->assertSame(Normalizador::resumenCorreos('b@example.test;a@example.test')['firma'], $dos['firma'], 'La firma no depende del orden');
        $this->assertNotSame($dos['firma'], Normalizador::resumenCorreos('a@example.test')['firma']);
    }

    public function test_hash_de_fila_es_estable_e_independiente_del_orden(): void
    {
        $a = Normalizador::hashFila(['id' => 1, 'nombre' => 'Curso Alfa 2024', 'imagen_certificado' => null]);
        $b = Normalizador::hashFila(['imagen_certificado' => null, 'nombre' => 'Curso Alfa 2024', 'id' => '1']);

        $this->assertSame($a, $b, 'Un entero y su texto dan el mismo hash');
        // Valor fijo: si cambia, cambió el formato del hash y todos los staging existentes dejarían de coincidir.
        $this->assertSame('f0d771fd62321e15a2a8badb8bb3554b618c403ded1422e5230ca44bb425fadd', $a);
        $this->assertNotSame($a, Normalizador::hashFila(['id' => 1, 'nombre' => 'Curso Alfa 2024', 'imagen_certificado' => '']), 'NULL y vacío se distinguen: el hash es del valor ORIGINAL');
        $this->assertNotSame($a, Normalizador::hashFila(['id' => 1, 'nombre' => 'Curso Alfa 2025', 'imagen_certificado' => null]));
    }
}
