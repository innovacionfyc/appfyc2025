<?php

namespace App\Support\CredentialFlow\Programacion;

use RuntimeException;

/**
 * Error esperado al programar, reprogramar, reconfirmar o cancelar una emisión. Código estable, mensaje seguro (sin rutas, trazas ni datos personales) y el
 * estado HTTP que le corresponde. Los errores de la propia emisión (variante QA, vista previa, prevalidación…) siguen saliendo como EmisionException.
 */
final class ProgramacionException extends RuntimeException
{
    /** El interruptor general está apagado. */
    public const DESACTIVADA = 'PROGRAMACION_DESACTIVADA';

    public const SIN_PERMISO = 'SIN_PERMISO';

    public const BASE_NO_DISPONIBLE = 'BASE_NO_DISPONIBLE';

    /** La base no está en estado «activa» (borrador, archivada o prueba). */
    public const BASE_NO_ACTIVA = 'BASE_NO_ACTIVA';

    /** La fecha y hora no tienen el formato exacto «AAAA-MM-DD HH:MM» o no existen en el calendario. */
    public const FECHA_INVALIDA = 'FECHA_INVALIDA';

    public const FECHA_PASADA = 'FECHA_PASADA';

    public const FECHA_MUY_PRONTO = 'FECHA_MUY_PRONTO';

    public const FECHA_MUY_LEJANA = 'FECHA_MUY_LEJANA';

    /** La aplicación no está configurada en la zona de Colombia: no se programa nada con una hora que podría interpretarse mal. */
    public const ZONA_NO_CONFIGURADA = 'ZONA_NO_CONFIGURADA';

    public const YA_EXISTE_ACTIVA = 'YA_EXISTE_ACTIVA';

    /** La programación ya no está pendiente (se está ejecutando o terminó). */
    public const NO_MODIFICABLE = 'NO_MODIFICABLE';

    /** Otra persona la cambió mientras tanto (versión distinta). */
    public const CAMBIO_CONCURRENTE = 'CAMBIO_CONCURRENTE';

    /** La base cambió desde que el administrador vio el resumen que confirma (o falta ese resumen): no se aprueba lo que no vio. */
    public const RESUMEN_DESACTUALIZADO = 'RESUMEN_DESACTUALIZADO';

    /** La base cambió mientras se programaba (entre la validación y el bloqueo). */
    public const BASE_CAMBIO = 'BASE_CAMBIO_DURANTE_PROGRAMACION';

    public const MOTIVO_INVALIDO = 'MOTIVO_INVALIDO';

    public function __construct(
        public readonly string $codigo,
        string $mensaje,
        public readonly int $estadoHttp = 409,
    ) {
        parent::__construct($mensaje);
    }
}
