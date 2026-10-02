<?php

namespace App\Support\CredentialFlow\Eliminacion;

use RuntimeException;

/** La eliminación definitiva no se pudo (o no debía) hacer. El mensaje está escrito para mostrarse tal cual al usuario. */
final class EliminacionException extends RuntimeException
{
    public const NO_EXISTE = 'NO_EXISTE';

    public const RUTA_INVALIDA = 'RUTA_INVALIDA';

    public const ARCHIVO_AJENO = 'ARCHIVO_AJENO';

    public const PLANTILLA_RELACIONADA = 'PLANTILLA_RELACIONADA';

    public const CAMBIO_CONCURRENTE = 'CAMBIO_CONCURRENTE';

    public const ERROR_ARCHIVOS = 'ERROR_ARCHIVOS';

    public const RELACIONES_AJENAS = 'RELACIONES_AJENAS';

    public const ERROR_GENERAL = 'ERROR_GENERAL';

    public function __construct(public readonly string $codigo, string $mensaje)
    {
        parent::__construct($mensaje);
    }

    public static function noExiste(string $que): self
    {
        return new self(self::NO_EXISTE, "Esta {$que} ya no existe.");
    }

    public static function rutaInvalida(): self
    {
        return new self(self::RUTA_INVALIDA, 'No se eliminó nada: un archivo no está donde debería estar. Avisa al equipo técnico.');
    }

    public static function archivoAjeno(): self
    {
        return new self(self::ARCHIVO_AJENO, 'No se eliminó nada: un archivo de esta base también aparece en otra. Avisa al equipo técnico.');
    }

    public static function plantillaRelacionada(): self
    {
        return new self(self::PLANTILLA_RELACIONADA, 'Esta plantilla todavía está relacionada con bases o certificados. Elimínalos primero.');
    }

    public static function cambioConcurrente(): self
    {
        return new self(self::CAMBIO_CONCURRENTE, 'La información cambió mientras se preparaba la eliminación. No se eliminó nada: inténtalo de nuevo.');
    }

    public static function errorArchivos(): self
    {
        return new self(self::ERROR_ARCHIVOS, 'No se pudo preparar la eliminación de los archivos. No se eliminó nada: inténtalo de nuevo o avisa al equipo técnico.');
    }

    public static function datosRelacionados(): self
    {
        return new self(self::RELACIONES_AJENAS, 'No se eliminó nada: esta base tiene datos enlazados con otra base. Avisa al equipo técnico.');
    }

    public static function errorGeneral(): self
    {
        return new self(self::ERROR_GENERAL, 'No se pudo completar la eliminación. No se eliminó nada: inténtalo de nuevo; si continúa, avisa al equipo técnico.');
    }
}
