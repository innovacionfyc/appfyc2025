<?php

namespace App\Support\CredentialFlow\Busqueda;

use RuntimeException;

/** La descarga desde el buscador no procede; `motivo` es un código técnico sin datos personales (p. ej. REVOCADA, INTEGRIDAD). */
final class DescargaNoPermitida extends RuntimeException
{
    public function __construct(public readonly string $motivo)
    {
        parent::__construct($motivo);
    }
}
