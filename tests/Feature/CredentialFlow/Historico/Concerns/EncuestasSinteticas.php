<?php

namespace Tests\Feature\CredentialFlow\Historico\Concerns;

use App\Support\CredentialFlow\Migracion\MigradorEncuestas;

/** Encuestas históricas SINTÉTICAS (tres estructuras, huérfanas, ambigua y columnas «sin uso») y su migración. Sin datos reales. */
trait EncuestasSinteticas
{
    public const TEXTO_EXACTO = "  Texto con espacios y salto\n  de línea  ";

    /** @return array<string,mixed> */
    private function enc(int $id, int $evento, string $doc, string $fecha, array $r): array
    {
        return array_merge([
            'id' => $id, 'id_evento' => $evento, 'documento_participante' => $doc, 'fecha' => $fecha, 'justificacion_pregunta1' => null,
            'pregunta1' => '', 'pregunta2' => '', 'pregunta3' => '', 'pregunta4' => '', 'pregunta5' => '', 'pregunta6' => '', 'pregunta7' => '', 'pregunta8' => '', 'pregunta9' => '',
        ], $r);
    }

    /** @return array<int,array<string,mixed>> */
    private function filas(): array
    {
        return [
            // Estructura inicial: opciones en otra redacción, pregunta2/4 Si/No, sin pregunta8.
            $this->enc(1, 1, self::DOCUMENTO, '2022-02-10 09:00:00', ['pregunta1' => 'Excelentes', 'pregunta2' => 'Si', 'pregunta3' => 'Muy buena', 'pregunta4' => 'Si', 'pregunta5' => self::TEXTO_EXACTO, 'pregunta7' => 'SECRETO-LIBRE-1']),
            // Estructura intermedia: calificaciones y pregunta8 en uso.
            $this->enc(2, 1, '1000002', '2023-05-05 10:00:00', ['pregunta1' => 'Excelente', 'pregunta2' => 'Muy bueno', 'pregunta3' => 'Bueno', 'pregunta4' => 'Regular', 'pregunta5' => 'a', 'pregunta6' => 'b', 'pregunta7' => 'c', 'pregunta8' => 'SECRETO-LIBRE-8']),
            // Estructura actual: pregunta4 Si/No; con datos en columnas «sin uso» (no se pueden perder).
            $this->enc(3, 1, '2000001', '2026-02-01 11:00:00', ['pregunta1' => 'Bueno', 'pregunta2' => 'Excelente', 'pregunta3' => 'Deficiente', 'pregunta4' => 'No', 'pregunta5' => 'x', 'pregunta6' => 'y', 'pregunta7' => 'z', 'pregunta9' => 'DATO-INESPERADO', 'justificacion_pregunta1' => 'JUSTIF']),
            // Huérfanas: evento inexistente; participante inexistente en un evento que sí existe; participante ambiguo (variantes conflictivas).
            $this->enc(4, 99, '5555555', '2024-01-01 08:00:00', ['pregunta1' => 'Regular', 'pregunta2' => 'Bueno', 'pregunta3' => 'Bueno', 'pregunta4' => 'Excelente', 'pregunta5' => 'h']),
            $this->enc(5, 1, '9999999', '2026-03-01 08:00:00', ['pregunta1' => 'Excelente', 'pregunta2' => 'Excelente', 'pregunta3' => 'Excelente', 'pregunta4' => 'Si', 'pregunta5' => 'i']),
            $this->enc(6, 1, '2000002', '2026-03-02 08:00:00', ['pregunta1' => 'Muy bueno', 'pregunta2' => 'Muy bueno', 'pregunta3' => 'Muy bueno', 'pregunta4' => 'Si', 'pregunta5' => 'j', 'pregunta6' => 'k']),
        ];
    }

    private function migrarTodo(): array
    {
        $datos = $this->datos();
        $datos['encuesta'] = $this->filas();
        $this->migrarSintetico($datos);   // certificados (corrida legado)

        return (array) (new MigradorEncuestas)->ejecutar();
    }
}
