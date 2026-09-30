<?php

namespace App\Support\CredentialFlow\Participantes;

use RuntimeException;

/** El archivo en sí no es utilizable (formato, tamaño, contenido). Lleva un código estable y un mensaje seguro. */
final class ErrorArchivoException extends RuntimeException
{
    public function __construct(public readonly string $codigo, string $mensaje)
    {
        parent::__construct($mensaje);
    }

    public function comoError(): ErrorFila
    {
        return ErrorFila::error($this->codigo, $this->getMessage());
    }
}
