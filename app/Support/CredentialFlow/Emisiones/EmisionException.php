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

    public const NO_ELEGIBLE = 'NO_ELEGIBLE';

    public const TIPO_DOCUMENTO_PENDIENTE = 'TIPO_DOCUMENTO_PENDIENTE';

    public const VISTA_PREVIA_PENDIENTE = 'VISTA_PREVIA_PENDIENTE';

    public const DATOS_DEL_DISENO_INCOMPLETOS = 'DATOS_DEL_DISENO_INCOMPLETOS';

    /** La persona ya tiene un certificado oficial vigente del mismo evento en otra base (ver DuplicadosEnEvento). */
    public const DUPLICADO_EN_EVENTO = 'DUPLICADO_EN_EVENTO';

    /** La plantilla de la base no tiene su variante de pruebas internas: sus QA no se emiten con el diseño de otra capacitación (ver ServicioQa::plantillaPara). */
    public const VARIANTE_QA_FALTANTE = 'VARIANTE_QA_FALTANTE';

    /** La base tiene una emisión programada pendiente (o ejecutándose): no se emite a mano hasta cancelarla o reprogramarla (ver Programacion\CandadoProgramacion). */
    public const EMISION_PROGRAMADA_PENDIENTE = 'EMISION_PROGRAMADA_PENDIENTE';

    /** La corrección de certificados ya emitidos solo la autoriza un administrador de Credential Flow (ver AdministradorAutorizado). */
    public const NO_AUTORIZADO = 'NO_AUTORIZADO';

    /** Otra corrección (cambio de plantilla o reemisión masiva) de la MISMA base está en curso. */
    public const OPERACION_EN_CURSO = 'OPERACION_EN_CURSO';

    /** Algo cambió desde la simulación: la huella del plan ya no coincide y hay que volver a simular. */
    public const PLAN_DESACTUALIZADO = 'PLAN_DESACTUALIZADO';

    /** El cambio autorizado de plantilla no se puede hacer (plantilla de prueba, sin diseño, sin variante QA, igual a la actual…). */
    public const CAMBIO_PLANTILLA_INVALIDO = 'CAMBIO_PLANTILLA_INVALIDO';

    /** No hay certificados vigentes desactualizados que reemitir (repetir una reemisión ya hecha no duplica nada). */
    public const SIN_DESACTUALIZADAS = 'SIN_DESACTUALIZADAS';

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
