<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Support\CredentialFlow\Generacion\DatosCredencial;
use App\Support\CredentialFlow\Generacion\GeneracionCredencialException;
use App\Support\CredentialFlow\Participantes\CeldaCruda;
use App\Support\CredentialFlow\Participantes\DatosDeParticipante;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Participantes\ValidadorDatosComunes;
use App\Support\CredentialFlow\Participantes\ValidadorParticipante;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** F&C Credential Flow · Fase 6: normalización de texto, datos comunes y adaptador hacia el motor PDF. */
class ParticipantesDatosTest extends TestCase
{
    public static function limpiezas(): array
    {
        return [
            'espacios dobles y bordes' => ['  Ana   Ruiz  ', 'Ana Ruiz'],
            'tabuladores internos' => ["Ana\tRuiz", 'Ana Ruiz'],
            'saltos de línea' => ["Ana\r\nRuiz\nPérez", 'Ana Ruiz Pérez'],
            'NBSP' => ["Ana\u{00A0}\u{00A0}Ruiz", 'Ana Ruiz'],
            'NFC (e + acento combinado)' => ["Cafe\u{0301}", "Caf\u{00E9}"],
            'vacío' => ['   ', ''],
        ];
    }

    #[DataProvider('limpiezas')]
    public function test_limpiar(string $entrada, string $esperado): void
    {
        $this->assertSame($esperado, Texto::limpiar($entrada));
    }

    public function test_mayusculas_unicode(): void
    {
        $this->assertSame('ÑANDÚ ÁÉÍÓÚ ÜÇ', Texto::mayusculas('ñandú áéíóú üç'));
        $this->assertSame("CAF\u{00C9}", Texto::mayusculas("cafe\u{0301}"));
    }

    public function test_clave_de_documento(): void
    {
        $this->assertSame('CC1023456789', Texto::claveDocumento('C.C. 1.023.456.789'));
        $this->assertSame('CC1023456789', Texto::claveDocumento('c.c. 1023456789'));
        $this->assertSame('NIT9001234567', Texto::claveDocumento('NIT 900.123.456-7'));
        $this->assertSame('PASAPORTEAB1234567', Texto::claveDocumento('Pasaporte AB-1234567'));
    }

    public function test_encabezados_normalizados(): void
    {
        foreach (['Número de Documento', 'NUMERO_DE_DOCUMENTO', ' número-de documento '] as $h) {
            $this->assertSame('numerodedocumento', Texto::encabezado($h), $h);
        }
        $this->assertSame('cedula', Texto::encabezado('Cédula'));
    }

    public function test_controles_y_formulas(): void
    {
        $this->assertTrue(Texto::tieneControl("a\x07b"));
        $this->assertFalse(Texto::tieneControl('Ana Ruiz ñ'));
        foreach (['=1', '+1', '-1', '@a'] as $f) {
            $this->assertTrue(Texto::iniciaConFormula($f), $f);
        }
        $this->assertFalse(Texto::iniciaConFormula('C.C. 1-2'));
    }

    public function test_validador_de_participante_sobre_valores_de_formulario(): void
    {
        $ok = ValidadorParticipante::validar(CeldaCruda::texto(' ana  ruiz '), CeldaCruda::texto('C.C.  1.023.456.789'));

        $this->assertTrue($ok->valido());
        $this->assertSame('ANA RUIZ', $ok->nombre);
        $this->assertSame('C.C. 1.023.456.789', $ok->documento);
        $this->assertSame('CC1023456789', $ok->clave);
        $this->assertSame([], $ok->avisos);

        $mal = ValidadorParticipante::validar(CeldaCruda::texto('=x'), CeldaCruda::texto('12'));
        $this->assertSame(['FORMULA_NO_PERMITIDA', 'DOCUMENTO_MUY_CORTO'], array_map(fn ($e) => $e->codigo, $mal->errores));
        $this->assertNull($mal->nombre);
    }

    public function test_datos_comunes_normalizados_y_validados(): void
    {
        $r = ValidadorDatosComunes::validar(['evento' => ' congreso  de finanzas ', 'fecha' => '17, 18 y 19 de septiembre de 2026', 'intensidad_horaria' => ' 30 horas ']);

        $this->assertSame([], $r['errores']);
        $this->assertSame(['evento' => 'CONGRESO DE FINANZAS', 'fecha' => '17, 18 y 19 de septiembre de 2026', 'intensidad_horaria' => '30 horas'], $r['datos']);

        $r = ValidadorDatosComunes::validar(['evento' => str_repeat('A', 201), 'fecha' => "a\x07", 'intensidad_horaria' => 'Ω日']);
        $this->assertSame(['evento', 'fecha', 'intensidad_horaria'], array_keys($r['errores']));

        $r = ValidadorDatosComunes::validar([]);
        $this->assertSame(['evento', 'fecha', 'intensidad_horaria'], array_keys($r['errores']));
    }

    public function test_datos_de_participante_combina_participante_y_lote_sin_leer_eventos(): void
    {
        $lote = new Lote(['datos_comunes' => ['evento' => 'CONGRESO', 'fecha' => '1 de octubre', 'intensidad_horaria' => '8 horas']]);
        $part = new Participante(['nombre_completo' => 'ANA RUIZ', 'documento' => 'C.C. 1.023.456.789']);

        $datos = DatosDeParticipante::para($part, $lote);

        $this->assertInstanceOf(DatosCredencial::class, $datos);
        $this->assertSame('ANA RUIZ', $datos->valor('nombre_completo'));
        $this->assertSame('C.C. 1.023.456.789', $datos->valor('documento'));
        $this->assertSame('CONGRESO', $datos->valor('evento'));
        $this->assertSame('1 de octubre', $datos->valor('fecha'));
        $this->assertSame('8 horas', $datos->valor('intensidad_horaria'));
        // DatosCredencial::qa() sigue existiendo y es independiente
        $this->assertSame('JUAN CARLOS PÉREZ GÓMEZ', DatosCredencial::qa()->valor('nombre_completo'));
    }

    public function test_datos_de_participante_falla_si_el_lote_no_tiene_un_dato_comun(): void
    {
        $lote = new Lote(['datos_comunes' => ['evento' => 'CONGRESO']]);
        $part = new Participante(['nombre_completo' => 'ANA RUIZ', 'documento' => 'CC 1234']);

        $this->expectException(GeneracionCredencialException::class);
        DatosDeParticipante::para($part, $lote)->valor('fecha');
    }
}
