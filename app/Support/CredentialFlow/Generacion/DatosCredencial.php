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
        // Última barrera antes de generar: claves EXACTAS del catálogo, valores string, NFC, sin
        // caracteres de control y dentro de maxLongitud. Las reglas de negocio (mayúsculas, documentos,
        // duplicados…) viven en la importación; aquí solo se comprueba que el dato sea imprimible.
        $catalogo = CamposDinamicos::todos();
        $limpios = [];

        foreach ($valores as $clave => $valor) {
            if (! CamposDinamicos::existe((string) $clave)) {
                throw GeneracionCredencialException::con(GeneracionCredencialException::CAMPO_DESCONOCIDO, 'Los datos incluyen un campo que no existe en el catálogo.');
            }
            if (! is_string($valor)) {
                throw GeneracionCredencialException::con(GeneracionCredencialException::CAMPO_SIN_VALOR, 'Un dato del certificado no es texto.');
            }
            $etiqueta = $catalogo[$clave]['etiqueta'];
            if (! mb_check_encoding($valor, 'UTF-8')) {
                throw GeneracionCredencialException::con(GeneracionCredencialException::DATO_INVALIDO, "El dato «{$etiqueta}» no es UTF-8 válido.");
            }
            $nfc = \Normalizer::normalize($valor, \Normalizer::FORM_C);
            $valor = $nfc === false ? $valor : $nfc;
            if (preg_match('/\p{Cc}/u', $valor) === 1) {
                throw GeneracionCredencialException::con(GeneracionCredencialException::DATO_INVALIDO, "El dato «{$etiqueta}» tiene caracteres de control no permitidos.");
            }
            $max = $catalogo[$clave]['maxLongitud'];
            if (mb_strlen($valor) > $max) {
                throw GeneracionCredencialException::con(GeneracionCredencialException::DATO_INVALIDO, "El dato «{$etiqueta}» supera los {$max} caracteres.");
            }
            $limpios[$clave] = $valor;
        }

        return new self($limpios);
    }

    /** Valor de un campo dinámico. Nunca devuelve un sustituto: si falta, falla. */
    public function valor(string $clave): string
    {
        if (! CamposDinamicos::existe($clave)) {
            throw GeneracionCredencialException::con(GeneracionCredencialException::CAMPO_DESCONOCIDO, 'Un elemento usa un campo dinámico que no existe en el catálogo.');
        }
        if (! array_key_exists($clave, $this->valores) || $this->valores[$clave] === '') {
            $etiqueta = CamposDinamicos::todos()[$clave]['etiqueta'];
            throw GeneracionCredencialException::con(GeneracionCredencialException::CAMPO_SIN_VALOR, "Falta el dato «{$etiqueta}» para generar el certificado.");
        }

        return $this->valores[$clave];
    }

    /** Todos los valores tal como se imprimirán. @return array<string,string> */
    public function todos(): array
    {
        return $this->valores;
    }

    /** @return array<int,string> */
    public function claves(): array
    {
        return array_keys($this->valores);
    }
}
