<?php

namespace App\Support\CredentialFlow\StagingEv;

/**
 * De dónde salen las filas del sistema viejo. Solo lectura. Las tablas de origen se llaman como en el sistema viejo:
 * evento, participante, token, descargas, encuesta, preguntas_encuesta, opciones_respuesta.
 */
interface FuenteSnapshot
{
    public const TABLAS = ['evento', 'participante', 'token', 'descargas', 'encuesta', 'preguntas_encuesta', 'opciones_respuesta'];

    /** @return iterable<int,array<string,mixed>> filas completas de la tabla, ordenadas por id */
    public function filas(string $tabla): iterable;

    /** @return array<string,int> cantidad de filas por tabla de origen */
    public function conteos(): array;

    /** Hash de la estructura (tablas, columnas y tipos) o null si no se puede deducir. */
    public function esquemaSha256(): ?string;
}
