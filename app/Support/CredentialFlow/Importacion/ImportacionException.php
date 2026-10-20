<?php

namespace App\Support\CredentialFlow\Importacion;

use RuntimeException;

/**
 * Error esperado de la importación inteligente: código estable, mensaje seguro (sin datos personales ni rutas) y estado HTTP.
 * `detalles` lista bloqueos o problemas por fila para mostrarlos al administrador.
 */
final class ImportacionException extends RuntimeException
{
    public const APAGADA = 'IMPORTACION_INTELIGENTE_APAGADA';

    public const ARCHIVO_INVALIDO = 'ARCHIVO_INVALIDO';

    public const SIN_TABLA = 'TABLA_DE_PARTICIPANTES_NO_ENCONTRADA';

    public const NO_ENCONTRADO = 'BORRADOR_NO_ENCONTRADO';

    public const EXPIRADO = 'BORRADOR_EXPIRADO';

    public const NO_EN_REVISION = 'BORRADOR_NO_ESTA_EN_REVISION';

    public const YA_CONFIRMADO = 'BORRADOR_YA_CONFIRMADO';

    public const VERSION_DESACTUALIZADA = 'BORRADOR_DESACTUALIZADO';

    public const CORRECCION_INVALIDA = 'CORRECCION_INVALIDA';

    public const NO_LISTO = 'BORRADOR_NO_ESTA_LISTO';

    public const QA_INCORPORACION_FALLIDA = 'QA_INCORPORACION_FALLIDA';

    public const IMPORTACION_INVALIDA = 'IMPORTACION_INVALIDA';

    public const VISTA_PREVIA_SIN_PLANTILLA = 'VISTA_PREVIA_SIN_PLANTILLA';

    public const VISTA_PREVIA_DATOS_INCOMPLETOS = 'VISTA_PREVIA_DATOS_INCOMPLETOS';

    public const FILA_ORIGEN_INCONSISTENTE = 'FILA_DE_ORIGEN_INCONSISTENTE';

    public const ACCION_INVALIDA = 'ACCION_MASIVA_INVALIDA';

    public const ACCION_SIN_CAMBIOS = 'ACCION_MASIVA_SIN_CAMBIOS';

    public const VERIFICACION_REQUERIDA = 'ACCION_MASIVA_SIN_VERIFICACION';

    public const SOBRESCRITURA_SIN_CONFIRMAR = 'ACCION_MASIVA_SOBRESCRITURA_SIN_CONFIRMAR';

    public const VISTA_PREVIA_OBSOLETA = 'ACCION_MASIVA_VISTA_PREVIA_OBSOLETA';

    public const DESHACER_NO_POSIBLE = 'ACCION_MASIVA_NO_SE_PUEDE_DESHACER';

    /** @param  array<int|string,mixed>  $detalles */
    public function __construct(
        public readonly string $codigo,
        string $mensaje,
        public readonly int $estadoHttp = 422,
        public readonly array $detalles = [],
    ) {
        parent::__construct($mensaje);
    }
}
