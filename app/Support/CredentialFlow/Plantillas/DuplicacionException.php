<?php

namespace App\Support\CredentialFlow\Plantillas;

use RuntimeException;

/** Motivo por el que una plantilla no se puede duplicar (o por el que la copia se deshizo). El mensaje es seguro: sin rutas, sin datos personales. */
final class DuplicacionException extends RuntimeException
{
    public const NOMBRE_INVALIDO = 'NOMBRE_INVALIDO';

    public const NOMBRE_DUPLICADO = 'NOMBRE_DUPLICADO';

    public const ORIGEN_NO_EXISTE = 'ORIGEN_NO_EXISTE';

    public const ORIGEN_ELIMINADA = 'ORIGEN_ELIMINADA';

    public const ORIGEN_ES_QA = 'ORIGEN_ES_QA';

    public const ORIGEN_SIN_DISENO = 'ORIGEN_SIN_DISENO';

    public const ORIGEN_SIN_PDF = 'ORIGEN_SIN_PDF';

    public const ORIGEN_PDF_ALTERADO = 'ORIGEN_PDF_ALTERADO';

    public const COPIA_IDENTICA_EXISTE = 'COPIA_IDENTICA_EXISTE';

    public const OCUPADO = 'OCUPADO';

    public const COPIA_NO_FIEL = 'COPIA_NO_FIEL';

    public const ORIGEN_CAMBIO = 'ORIGEN_CAMBIO';

    public function __construct(public readonly string $codigo, string $mensaje)
    {
        parent::__construct($mensaje);
    }
}
