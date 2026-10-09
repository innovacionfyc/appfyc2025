<?php

namespace App\Support\CredentialFlow\Legado;

use RuntimeException;

/** El certificado histórico NO es elegible para generar ni servir un PDF (ver ElegibilidadLegado). Mensaje técnico, sin datos personales. */
final class CongeladoNoPermitido extends RuntimeException
{
    public function __construct(public readonly string $motivo)
    {
        parent::__construct('NO_ELEGIBLE: '.$motivo);
    }
}
