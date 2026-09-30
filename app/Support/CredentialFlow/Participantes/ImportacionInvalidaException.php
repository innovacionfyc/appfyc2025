<?php

namespace App\Support\CredentialFlow\Participantes;

use RuntimeException;

/** La confirmación de una importación volvió a validar el archivo y encontró errores: no se cambió nada. */
final class ImportacionInvalidaException extends RuntimeException
{
    public function __construct(public readonly ResultadoImportacion $resultado)
    {
        parent::__construct('El archivo tiene errores y no se importó ningún participante.');
    }
}
