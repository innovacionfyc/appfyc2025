<?php

namespace App\Support\CredentialFlow\Generacion;

use App\Support\CredentialFlow\CamposDinamicos;

/**
 * Valores con los que se resuelven los campos dinámicos al generar un PDF. Es independiente del
 * preview del editor (CamposDinamicos::preview): el preview solo existe para ver el diseño, estos
 * son los datos de generación. En esta fase solo hay un dataset QA fijo; los participantes reales
 * llegarán en otra fase y usarán fromArray().
 */
final readonly class DatosCredencial
{
    /** @param array<string,string> $valores clave del catálogo => texto listo para imprimir */
    private function __construct(private array $valores) {}

    /** Dataset QA fijo (no proviene de personas reales ni del preview del catálogo). */
    public static function qa(): self
    {
        return new self([
            'nombre_completo' => 'JUAN CARLOS PÉREZ GÓMEZ',
            'documento' => 'C.C. 1.023.456.789',
            'evento' => 'GESTIÓN INTEGRAL DE PROPIEDAD HORIZONTAL',
            'fecha' => '29 DE SEPTIEMBRE DE 2026',
            'intensidad_horaria' => '16 HORAS',
        ]);
    }

    /** @param array<string,string> $valores */
    public static function fromArray(array $valores): self
    {
        foreach ($valores as $clave => $valor) {
            if (! CamposDinamicos::existe((string) $clave)) {
                throw GeneracionCredencialException::con(GeneracionCredencialException::CAMPO_DESCONOCIDO, 'Los datos incluyen un campo que no existe en el catálogo.');
            }
            if (! is_string($valor)) {
                throw GeneracionCredencialException::con(GeneracionCredencialException::CAMPO_SIN_VALOR, 'Un dato de la credencial no es texto.');
            }
        }

        return new self($valores);
    }

    /** Valor de un campo dinámico. Nunca devuelve un sustituto: si falta, falla. */
    public function valor(string $clave): string
    {
        if (! CamposDinamicos::existe($clave)) {
            throw GeneracionCredencialException::con(GeneracionCredencialException::CAMPO_DESCONOCIDO, 'Un elemento usa un campo dinámico que no existe en el catálogo.');
        }
        if (! array_key_exists($clave, $this->valores) || $this->valores[$clave] === '') {
            $etiqueta = CamposDinamicos::todos()[$clave]['etiqueta'];
            throw GeneracionCredencialException::con(GeneracionCredencialException::CAMPO_SIN_VALOR, "Falta el dato «{$etiqueta}» para generar la credencial.");
        }

        return $this->valores[$clave];
    }

    /** @return array<int,string> */
    public function claves(): array
    {
        return array_keys($this->valores);
    }
}
