<?php

namespace App\Support\CredentialFlow\Legado;

use RuntimeException;

/**
 * El PDF congelado no coincide con su registro (ausente, otros bytes u otro SHA-256) o hay un archivo inesperado en su ruta.
 * NUNCA se regenera ni se sobrescribe por encima: un PDF histórico congelado es inmutable. Sin datos personales.
 */
final class PdfHistoricoInconsistente extends RuntimeException
{
    public const CODIGO = 'PDF_HISTORICO_INCONSISTENTE';

    public function __construct(public readonly string $motivo)
    {
        parent::__construct(self::CODIGO.': '.$motivo);
    }
}
