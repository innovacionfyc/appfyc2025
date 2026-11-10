<?php

namespace App\Support\CredentialFlow\Eventos;

use RuntimeException;

/** Por qué no se autorizó o no se revocó la doble modalidad de un evento. El mensaje es seguro (sin datos personales) y está escrito para el administrador. */
final class AutorizacionDobleModalidadException extends RuntimeException
{
    public const NO_DISPONIBLE = 'DOBLE_MODALIDAD_NO_DISPONIBLE';

    public const SIN_PERMISO = 'SIN_PERMISO';

    public const MOTIVO_INVALIDO = 'MOTIVO_INVALIDO';

    public const EVENTO_NO_VALIDO = 'EVENTO_NO_VALIDO';

    public const YA_AUTORIZADO = 'YA_AUTORIZADO';

    public const NO_AUTORIZADO = 'NO_AUTORIZADO';

    public const PARTICIPACIONES_DOBLES = 'PARTICIPACIONES_DOBLES';

    public const PROGRAMACION_ACTIVA = 'PROGRAMACION_ACTIVA';

    public const BORRADOR_EN_REVISION = 'BORRADOR_EN_REVISION';

    /** @param  list<array{code:string, message:string}>  $bloqueos */
    public function __construct(public readonly string $codigo, string $mensaje, public readonly array $bloqueos = [])
    {
        parent::__construct($mensaje);
    }
}
