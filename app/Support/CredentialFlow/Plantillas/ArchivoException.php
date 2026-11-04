<?php

namespace App\Support\CredentialFlow\Plantillas;

use RuntimeException;

/** Motivo por el que una plantilla no se puede archivar o restaurar. El mensaje es seguro (sin rutas ni datos personales) y está escrito para el administrador. */
final class ArchivoException extends RuntimeException
{
    public const NO_DISPONIBLE = 'ARCHIVO_NO_DISPONIBLE';

    public const NO_AUTORIZADO = 'NO_AUTORIZADO';

    public const MOTIVO_INVALIDO = 'MOTIVO_INVALIDO';

    public const YA_ARCHIVADA = 'YA_ARCHIVADA';

    public const NO_ARCHIVADA = 'NO_ARCHIVADA';

    public const ELIMINADA = 'PLANTILLA_ELIMINADA';

    public const VARIANTE_EN_USO = 'VARIANTE_EN_USO';

    public const OFICIAL_ARCHIVADA = 'OFICIAL_ARCHIVADA';

    public const PROGRAMACION_ACTIVA = 'PROGRAMACION_ACTIVA';

    public const SUCESORA_INVALIDA = 'SUCESORA_INVALIDA';

    public const CAMBIO_DURANTE_ARCHIVO = 'CAMBIO_DURANTE_ARCHIVO';

    /** @param  array<int,array{code:string, message:string}>  $bloqueos */
    public function __construct(public readonly string $codigo, string $mensaje, public readonly array $bloqueos = [])
    {
        parent::__construct($mensaje);
    }
}
