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

    /** Bloqueos por historial: una base con certificados emitidos, descargas o certificados históricos no se elimina. */
    public const CON_HISTORIAL = 'CON_HISTORIAL';

    public const CON_DESCARGAS = 'CON_DESCARGAS';

    public const REEMPLAZA_HISTORICOS = 'REEMPLAZA_HISTORICOS';

    public const CERTIFICADOS_HISTORICOS = 'CERTIFICADOS_HISTORICOS';

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

    private const REVOCAR = ' Puedes revocar los certificados si necesitas retirarlos.';

    public static function conHistorial(): self
    {
        return new self(self::CON_HISTORIAL, 'Esta base tiene certificados o historial asociado y no se puede eliminar definitivamente.'.self::REVOCAR);
    }

    public static function conDescargas(): self
    {
        return new self(self::CON_DESCARGAS, 'Esta base tiene certificados que ya se descargaron y su historial debe conservarse, por lo que no se puede eliminar definitivamente.'.self::REVOCAR);
    }

    public static function reemplazaHistoricos(): self
    {
        return new self(self::REEMPLAZA_HISTORICOS, 'Esta base tiene certificados que reemplazan a certificados históricos, que deben conservarse, por lo que no se puede eliminar definitivamente.'.self::REVOCAR);
    }

    public static function certificadosHistoricos(): self
    {
        return new self(self::CERTIFICADOS_HISTORICOS, 'Esta base está relacionada con certificados históricos que deben conservarse, por lo que no se puede eliminar definitivamente.');
    }

    public static function errorGeneral(): self
    {
        return new self(self::ERROR_GENERAL, 'No se pudo completar la eliminación. No se eliminó nada: inténtalo de nuevo; si continúa, avisa al equipo técnico.');
    }
}
