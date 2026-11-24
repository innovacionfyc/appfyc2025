<?php

namespace App\Support\CredentialFlow\Emisiones;

use RuntimeException;

/** «Corregir toda la base» terminó SIN reemitir nada: lleva el error (código y mensaje) y el resultado por grupo para mostrarlo tal cual. */
final class CorreccionBaseException extends RuntimeException
{
    /** @param  array{estado:string, grupos:array<string,array<string,mixed>>, correos_enviados:int}  $resultado */
    public function __construct(public readonly EmisionException $error, public readonly array $resultado)
    {
        parent::__construct($error->getMessage());
    }
}
