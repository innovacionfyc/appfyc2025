<?php

namespace App\Support\CredentialFlow\Legado;

use RuntimeException;

/**
 * El renderer legado se NIEGA a generar el certificado. Nunca corrige ni «arregla» nada: el mensaje es técnico e interno
 * (para el equipo), no lleva nombres ni documentos, y el código dice exactamente por qué.
 */
final class RenderNoPermitido extends RuntimeException
{
    public const PLANTILLA_FALTANTE = 'PLANTILLA_FALTANTE';

    public const PLANTILLA_NO_OK = 'PLANTILLA_NO_OK';

    public const EXTENSION_INVALIDA = 'EXTENSION_INVALIDA';

    public const IMAGEN_NO_SOPORTADA = 'IMAGEN_NO_SOPORTADA';

    public const ARCHIVO_AUSENTE = 'ARCHIVO_AUSENTE';

    public const SHA_NO_COINCIDE = 'SHA_NO_COINCIDE';

    public const DOCUMENTO_REVISION = 'DOCUMENTO_REVISION';

    public const DATOS_FALTANTES = 'DATOS_FALTANTES';

    public const VARIAS_PAGINAS = 'VARIAS_PAGINAS';

    public const ERROR_FPDF = 'ERROR_FPDF';

    public function __construct(public readonly string $codigo, string $mensaje)
    {
        parent::__construct($codigo.': '.$mensaje);
    }
}
