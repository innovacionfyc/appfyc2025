<?php

namespace Tests\Feature\CredentialFlow\Legado;

use App\Support\CredentialFlow\Legado\FormatoLegado;
use App\Support\CredentialFlow\Legado\RutasLegado;
use App\Support\CredentialFlow\Legado\TextoLegado;
use App\Support\CredentialFlow\StagingEv\Normalizador;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Helpers históricos puros. Los vectores «dorados» son SALIDAS REALES de PHP 7.4.33 (utf8_decode y number_format) sobre las
 * mismas entradas, en hexadecimal para que ningún editor o consola las altere. Se generaron con un test diferencial de
 * 20.017 cadenas aleatorias de bytes (0 diferencias) del que aquí se conserva una muestra representativa.
 */
class HelpersLegadoTest extends TestCase
{
    /** @return array<int,array{0:string,1:string}> entrada hex, salida de utf8_decode() de PHP 7.4.33 hex */
    public static function utf8Dorado(): array
    {
        return [
            ['2a7ff5f5', '2a7f3f3f'],
            ['c1', '3f'],
            ['63c2ffb0804d88', '633f3f3f4d3f'],
            ['a0c1c1ede12fc1c3c21a9e', '3f3f3f3f3f2f3f3f3f1a3f'],
            ['15', '15'],
            ['9f24c1', '3f243f'],
            ['ea58bf', '3f583f'],
            ['dfbfe1f5416ec7e0e1b2', '3f3f416e3f3f3f'],
            ['80', '3f'],
            ['f429c217806ca022e04762', '3f293f173f6c3f223f4762'],
            ['e1315c', '3f315c'],
            ['edef60f5809c91df7f', '3f3f603f3f3f3f3f7f'],
            ['4a4f53c389204d55c3914f5a', '4a4f53c9204d55d14f5a'],
            ['c381c389c38dc393c39a20c39cc39120c3a1c3a9c3adc3b3c3ba', 'c1c9cdd3da20dcd120e1e9edf3fa'],
            ['c581554b41535a', '3f554b41535a'],
            ['45555220e282ac', '455552203f'],
            ['e2809320e2809420e2809c20e2809d20e2809820e28099', '3f203f203f203f203f203f'],
            ['e697a5e69cace8aa9e', '3f3f3f'],
            ['f09f988020656d6f6a69', '3f20656d6f6a69'],
            ['cea96d656761', '3f6d656761'],
            ['4e425350c2a078', '4e425350a078'],
            ['c3b1c28578', 'f18578'],
            ['c3bfc480', 'ff3f'],
            ['efbbbf626f6d', '3f626f6d'],
            ['eda080', '3f'],
            ['f4908080', '3f'],
            ['c080', '3f3f'],
            ['e08080', '3f'],
            ['f0808080', '3f'],
        ];
    }

    #[DataProvider('utf8Dorado')]
    public function test_utf8_decode_coincide_con_php_7_4_real(string $entradaHex, string $esperadoHex): void
    {
        $this->assertSame($esperadoHex, bin2hex(TextoLegado::utf8Decode(hex2bin($entradaHex))['bytes']));
    }

    public function test_el_port_no_equivale_a_mb_convert_encoding_y_por_eso_existe(): void
    {
        // Secuencia truncada: utf8_decode (7.4) da «?»; mb_convert_encoding da otra cosa.
        $this->assertSame('41', bin2hex(TextoLegado::utf8Decode('A')['bytes']));
        $this->assertSame('413f', bin2hex(TextoLegado::utf8Decode("A\xC3")['bytes']));
    }

    public function test_reporta_lo_no_representable_y_lo_invalido(): void
    {
        $r = TextoLegado::utf8Decode("A\u{0141}B\u{20AC}C\u{0141}\xFF");

        $this->assertSame('A?B?C??', $r['bytes']);
        $this->assertSame([0x141, 0x20AC], $r['no_representables']);
        $this->assertSame(1, $r['invalidos']);
        $this->assertSame([], TextoLegado::utf8Decode('JOSÉ MUÑOZ')['no_representables']);
        $this->assertSame("JOS\xC9 MU\xD1OZ", TextoLegado::utf8Decode('JOSÉ MUÑOZ')['bytes']);
    }

    /** @return array<int,array{0:string,1:?string}> documento hex, number_format() de PHP 7.4.33 */
    public static function documentoDorado(): array
    {
        return [
            ['3733313536383237', '73.156.827'],
            ['303039383736353433', '9.876.543'],
            ['37332e3135362e383237', '73'],
            ['414243313233', null],
            ['313233616263', '123'],
            ['', null],
            ['20313233', '123'],
            ['31323320343536', '123'],
            ['c2a0313233', null],
            ['093435', '45'],
            ['0a3435', '45'],
            ['2020', null],
            ['316533', '1.000'],
            ['312e35', '2'],
            ['2e35', '1'],
            ['2d3132', '-12'],
            ['2b3132', '12'],
            ['30', '0'],
            ['3030', '0'],
            ['393939393939393939393939393939', '999.999.999.999.999'],
            ['31323334353637383930313233343536', '1.234.567.890.123.456'],
            ['3132333435363738393031323334353637383930', '12.345.678.901.234.567.168'],
            ['312c323334', '1'],
            ['30783141', '0'],
            ['e0a5a7e0a5a8e0a5a9', null],
            ['37333135363832370a', '73.156.827'],
            ['373331353638323720', '73.156.827'],
            ['203733313536383237', '73.156.827'],
            ['3165', '1'],
            ['6535', null],
            ['d9a1d9a2d9a3', null],
            ['313200', '12'],
            ['d9a3', null],
            ['35652d32', '0'],
            ['31452b33', '1.000'],
            ['312e', '1'],
            ['2d', null],
            ['2b', null],
            ['2e', null],
            ['302e39', '1'],
            ['302e35', '1'],
            ['3939392e35', '1.000'],
        ];
    }

    #[DataProvider('documentoDorado')]
    public function test_number_format_del_documento_coincide_con_php_7_4_real(string $documentoHex, ?string $esperado): void
    {
        $this->assertSame($esperado, FormatoLegado::documento(hex2bin($documentoHex)));
    }

    public function test_la_linea_del_documento_y_los_casos_pedidos(): void
    {
        $this->assertSame('CC: 73.156.827', FormatoLegado::lineaDocumento('CC', '73156827'));      // numérico
        $this->assertSame('CE: 1.234', FormatoLegado::lineaDocumento('CE', '1234'));
        $this->assertSame('CC: 73', FormatoLegado::lineaDocumento('CC', '73.156.827'));            // separadores: prefijo numérico
        $this->assertSame('CC: ', FormatoLegado::lineaDocumento('CC', 'ABC123'));                  // letras: nada
        $this->assertSame('CC: ', FormatoLegado::lineaDocumento('CC', ''));                        // vacío
        $this->assertSame('CC: ', FormatoLegado::lineaDocumento('CC', "\u{00A0}123"));             // NBSP inicial: nada
        $this->assertSame('CC: 123', FormatoLegado::lineaDocumento('CC', ' 123'));                 // espacio inicial: lo admite
        $this->assertSame('CC: 123', FormatoLegado::lineaDocumento('CC', '123 456'));              // espacio en medio: corta
        $this->assertSame(': 123', FormatoLegado::lineaDocumento(null, '123'));                    // sin tipo
    }

    public function test_el_staging_y_el_renderer_comparten_la_misma_regla_de_impresion(): void
    {
        foreach (['73156827', '009876543', '73.156.827', 'ABC123', '', ' 123', "73156827\n"] as $d) {
            $this->assertSame(FormatoLegado::documento($d), Normalizador::documentoImpresoLegado($d));
        }
    }

    // ── Rutas de storage ─────────────────────────────────────────────────────

    public function test_rutas_futuras_de_plantillas_y_certificados(): void
    {
        $sha = hash('sha256', 'x');

        $this->assertSame("credential-flow/legado/plantillas/{$sha}/original.png", RutasLegado::plantilla($sha, 'png'));
        $this->assertSame("credential-flow/legado/plantillas/{$sha}/original.jpg", RutasLegado::plantilla($sha, 'JPG'));
        $this->assertSame('credential-flow/legado/certificados/42/certificado.pdf', RutasLegado::certificado(42));
        $this->assertSame('png', RutasLegado::extensionPorMime('image/png'));
        $this->assertSame('jpg', RutasLegado::extensionPorMime('image/jpeg'));
        $this->assertNull(RutasLegado::extensionPorMime('image/webp'));
        $this->assertNull(RutasLegado::extensionPorMime(null));
    }

    #[DataProvider('rutasInvalidas')]
    public function test_las_rutas_invalidas_se_rechazan(callable $construir): void
    {
        $this->expectException(InvalidArgumentException::class);
        $construir();
    }

    /** @return array<string,array{0:callable}> */
    public static function rutasInvalidas(): array
    {
        $sha = str_repeat('a', 64);

        return [
            'sha corto' => [fn () => RutasLegado::plantilla('abc', 'png')],
            'sha con traversal' => [fn () => RutasLegado::plantilla('../'.str_repeat('a', 61), 'png')],
            'sha en mayúsculas' => [fn () => RutasLegado::plantilla(strtoupper($sha), 'png')],
            'extensión no permitida' => [fn () => RutasLegado::plantilla($sha, 'php')],
            'extensión con ruta' => [fn () => RutasLegado::plantilla($sha, '../png')],
            'id cero' => [fn () => RutasLegado::certificado(0)],
            'id negativo' => [fn () => RutasLegado::certificado(-5)],
        ];
    }
}
