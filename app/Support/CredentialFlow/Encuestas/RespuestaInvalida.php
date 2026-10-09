<?php

namespace App\Support\CredentialFlow\Encuestas;

use RuntimeException;

/** Respuesta rechazada por el motor. `errores` = clave de pregunta (o `_`) → mensaje humano. No lleva el contenido respondido. */
final class RespuestaInvalida extends RuntimeException
{
    public const VERSION_NO_VIGENTE = 'VERSION_NO_VIGENTE';

    public const DUPLICADA = 'DUPLICADA';

    public const CONTEXTO = 'CONTEXTO';

    public const CAMPOS = 'CAMPOS';

    /** @param array<string,string> $errores */
    public function __construct(public readonly string $codigo, public readonly array $errores = [], string $mensaje = '')
    {
        parent::__construct($mensaje !== '' ? $mensaje : "Respuesta de encuesta inválida ({$codigo}).");
    }
}
