<?php

namespace App\Support\CredentialFlow\Migracion;

use RuntimeException;

/**
 * La migración DELTA se NIEGA antes de escribir nada (Fase 11F-B). `codigo` es estable para operación y pruebas; `detalle` solo
 * lleva conteos y nombres de tabla (nunca datos personales): es lo que hay que revisar antes de decidir una ruta controlada.
 */
final class DeltaRechazado extends RuntimeException
{
    /** Alguna fila ya migrada cambió en el origen: el delta no actualiza ni reconcilia. */
    public const FILAS_CAMBIADAS = 'FILAS_CAMBIADAS';

    /** Alguna fila ya migrada ya no existe en el origen: el delta no borra ni reconcilia. */
    public const FILAS_AUSENTES = 'FILAS_AUSENTES';

    /** Un participante nuevo cae en un grupo de duplicados que ya existe en la base: requiere una ruta controlada aparte. */
    public const GRUPO_EXISTENTE = 'GRUPO_EXISTENTE';

    /** Falta la corrida base (o la del delta de certificados) de la que cuelga lo nuevo. */
    public const SIN_BASE = 'SIN_BASE';

    public const EVENTO_NO_MIGRADO = 'EVENTO_NO_MIGRADO';

    public const PARTICIPANTE_SIN_EVENTO = 'PARTICIPANTE_SIN_EVENTO';

    /** Una respuesta nueva usa una versión u opción que la estructura base no tiene: el delta no la modifica. */
    public const ESTRUCTURA_ENCUESTA_NUEVA = 'ESTRUCTURA_ENCUESTA_NUEVA';

    public const CLAVE_DISTINTA = 'CLAVE_DISTINTA';

    public const CODIGO_COLISIONA = 'CODIGO_COLISIONA';

    /** @param array<string,mixed> $detalle */
    public function __construct(public readonly string $codigo, string $mensaje, public readonly array $detalle = [])
    {
        parent::__construct($codigo.': '.$mensaje);
    }
}
