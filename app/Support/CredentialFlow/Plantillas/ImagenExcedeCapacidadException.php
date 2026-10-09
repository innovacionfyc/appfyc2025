<?php

namespace App\Support\CredentialFlow\Plantillas;

/**
 * La imagen NO se puede procesar con la memoria disponible: se detecta ANTES de decodificar (estimación previa), así nunca hay un «Allowed memory size
 * exhausted». Es una `ImagenInvalidaException` para que los llamadores existentes sigan capturándola igual.
 */
final class ImagenExcedeCapacidadException extends ImagenInvalidaException
{
    public const CODIGO = 'PLANTILLA_HISTORICA_EXCEDE_CAPACIDAD';

    public function __construct(string $mensaje, public readonly int $necesariaBytes = 0, public readonly int $disponibleBytes = 0)
    {
        parent::__construct($mensaje);
    }
}
