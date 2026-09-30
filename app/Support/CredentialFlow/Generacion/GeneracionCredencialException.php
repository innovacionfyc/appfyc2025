<?php

namespace App\Support\CredentialFlow\Generacion;

use RuntimeException;

/**
 * Error esperado al generar un PDF. Lleva un código estable y un mensaje seguro en español (sin
 * rutas del servidor ni detalles internos), pensado para mostrarse tal cual al administrador.
 */
final class GeneracionCredencialException extends RuntimeException
{
    public const PLANTILLA_SIN_PDF = 'PLANTILLA_SIN_PDF';

    public const SIN_DISENO = 'SIN_DISENO';

    public const SCHEMA_NO_SOPORTADO = 'SCHEMA_NO_SOPORTADO';

    public const PAGINA_DISTINTA = 'PAGINA_DISTINTA';

    public const FUENTE_NO_REPRODUCIBLE = 'FUENTE_NO_REPRODUCIBLE';

    public const CARACTER_NO_SOPORTADO = 'CARACTER_NO_SOPORTADO';

    public const CAMPO_SIN_VALOR = 'CAMPO_SIN_VALOR';

    public const CAMPO_DESCONOCIDO = 'CAMPO_DESCONOCIDO';

    public const FUERA_DE_PAGINA = 'FUERA_DE_PAGINA';

    public const NO_CABE = 'NO_CABE';

    public const PDF_ILEGIBLE = 'PDF_ILEGIBLE';

    public const DATO_INVALIDO = 'DATO_INVALIDO';

    public const PLANTILLA_ALTERADA = 'PLANTILLA_ALTERADA';

    public const QR_NO_VALIDO = 'QR_NO_VALIDO';

    public const QR_SIN_URL = 'QR_SIN_URL';

    public function __construct(public readonly string $codigo, string $mensaje, public readonly ?string $elementoId = null)
    {
        parent::__construct($mensaje);
    }

    public static function con(string $codigo, string $mensaje, ?string $elementoId = null): self
    {
        return new self($codigo, $mensaje, $elementoId);
    }
}
