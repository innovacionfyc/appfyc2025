<?php

namespace App\Support\CredentialFlow\Participantes;

/** Filas no vacías leídas de un archivo (la primera es el encabezado), con su número de fila real. */
final readonly class LecturaArchivo
{
    /**
     * @param  'xlsx'|'csv'  $formato
     * @param  array<int,array{fila:int, celdas:array<int,CeldaCruda>}>  $filas
     */
    public function __construct(
        public string $formato,
        public array $filas,
        public bool $excedeFilas = false,
    ) {}
}
