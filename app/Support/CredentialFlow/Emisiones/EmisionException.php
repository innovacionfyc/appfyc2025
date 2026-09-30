<?php

namespace App\Support\CredentialFlow\Emisiones;

use RuntimeException;

/**
 * Error esperado del flujo de emisión. Lleva un código estable, un mensaje seguro (sin rutas ni trazas),
 * el estado HTTP que le corresponde y, si aplica, detalles para mostrar (por ejemplo la lista de fallos
 * de una prevalidación masiva).
 */
final class EmisionException extends RuntimeException
{
    public const EMISION_YA_VIGENTE = 'EMISION_YA_VIGENTE';

    public const EMISION_YA_REVOCADA = 'EMISION_YA_REVOCADA';

    public const DATOS_CAMBIARON_DURANTE_EMISION = 'DATOS_CAMBIARON_DURANTE_EMISION';

    public const ARCHIVO_EMISION_NO_EXISTE = 'ARCHIVO_EMISION_NO_EXISTE';

    public const INTEGRIDAD_EMISION_INVALIDA = 'INTEGRIDAD_EMISION_INVALIDA';

    public const ESPACIO_INSUFICIENTE = 'ESPACIO_INSUFICIENTE';

    public const SIN_PENDIENTES = 'SIN_PENDIENTES';

    public const PREVALIDACION_FALLIDA = 'PREVALIDACION_FALLIDA';

    public const SIN_EMISIONES_VIGENTES = 'SIN_EMISIONES_VIGENTES';

    public const ZIP_MUY_GRANDE = 'ZIP_MUY_GRANDE';

    public const PARTICIPANTE_NO_DISPONIBLE = 'PARTICIPANTE_NO_DISPONIBLE';

    public const PLANTILLA_NO_DISPONIBLE = 'PLANTILLA_NO_DISPONIBLE';

    public const ERROR_ESCRITURA = 'ERROR_ESCRITURA';

    /** @param  array<int,array<string,mixed>>  $detalles */
    public function __construct(
        public readonly string $codigo,
        string $mensaje,
        public readonly int $estadoHttp = 409,
        public readonly array $detalles = [],
    ) {
        parent::__construct($mensaje);
    }
}
